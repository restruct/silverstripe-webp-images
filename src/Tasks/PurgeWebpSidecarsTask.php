<?php

namespace Restruct\Silverstripe\WebpImages\Tasks;

use League\Flysystem\Filesystem;
use League\Flysystem\StorageAttributes;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Versioned\Versioned;
use Throwable;

/**
 * Removes the WebP "sidecar" files that wedevelopnl/silverstripe-webp-images 2.x wrote next to
 * public images (`foo.jpg.webp`, `foo__ScaleWidthWzQwMF0.jpg.webp`). 3.0 no longer uses or serves
 * them; it stores WebP as core image variants instead. 2.x could also leave them behind when an
 * image was deleted (its adapter delete was broken until the SS6 branch), so some may be orphans.
 *
 * Usage (Silverstripe 6):
 *   vendor/bin/sake tasks:webp-images-purge-sidecars             (dry run)
 *   vendor/bin/sake tasks:webp-images-purge-sidecars --confirm
 *
 * Usage (Silverstripe 5):
 *   vendor/bin/sake dev/tasks/webp-images-purge-sidecars         (dry run)
 *   vendor/bin/sake dev/tasks/webp-images-purge-sidecars confirm=1
 *
 * Only the PUBLIC asset filesystem is scanned (2.x only wrote sidecars for public files), and
 * dot-directories in it (eg `.protected`) are skipped. A file whose name matches but which is a
 * real File record (somebody uploaded "photo.jpg.webp") is never touched. Old cached HTML that
 * still points at a sidecar keeps working until this task is run, so run it after the page
 * caches have been cleared.
 */
class PurgeWebpSidecarsTask extends BuildTask
{
    use PurgeWebpSidecarsTaskEntryPoint;

    /**
     * Silverstripe 5 URL segment (dev/tasks/webp-images-purge-sidecars). Inert config on
     * Silverstripe 6, where $commandName names the task instead.
     *
     * @config
     */
    private static $segment = 'webp-images-purge-sidecars';

    /**
     * Silverstripe 6 command name (sake tasks:webp-images-purge-sidecars). Declared here, not in
     * the trait: on SS5 this is a new static, on SS6 a compatible redeclaration of
     * PolyCommand::$commandName.
     */
    protected static string $commandName = 'webp-images-purge-sidecars';

    /**
     * What a 2.x sidecar is called: the image's own file name (original or variant) plus ".webp"
     */
    public const SIDECAR_PATTERN = '/\.(jpe?g|png)\.webp$/i';

    public function __construct()
    {
        parent::__construct();
        # assigned rather than declared: BuildTask::$title is untyped on SS5 and `string` on SS6
        $this->title = 'Purge WebP sidecar files (webp-images 2.x)';
    }

    /**
     * Version-neutral body of the task.
     *
     * @param bool $confirm Actually delete (false = dry run)
     * @param bool $verbose List every file
     * @param callable $writeln function (string $line): void - lines may carry <info>/<comment> tags
     * @return array{found: int, deleted: int, skipped: int}
     */
    public function purgeSidecars(bool $confirm, bool $verbose, callable $writeln): array
    {
        $result = ['found' => 0, 'deleted' => 0, 'skipped' => 0];

        if (!$confirm) {
            $writeln('<comment>DRY RUN - add ' . $this->confirmHint() . ' to actually delete.</comment>');
        }

        $store = Injector::inst()->get(AssetStore::class);
        if (!$store instanceof FlysystemAssetStore) {
            $writeln('The asset store is not a FlysystemAssetStore; nothing to scan.');
            return $result;
        }
        /** @var Filesystem $filesystem */
        $filesystem = $store->getPublicFilesystem();

        foreach ($this->findSidecars($filesystem) as $path) {
            if ($this->isFileRecord($path)) {
                $result['skipped']++;
                if ($verbose) {
                    $writeln("{$path} - is a File record, kept");
                }
                continue;
            }
            $result['found']++;
            if (!$confirm) {
                if ($verbose) {
                    $writeln("{$path} - would be deleted");
                }
                continue;
            }
            try {
                $filesystem->delete($path);
                $result['deleted']++;
                if ($verbose) {
                    $writeln("{$path} - <info>deleted</info>");
                }
            } catch (Throwable $e) {
                $writeln("{$path} - <comment>could not delete: {$e->getMessage()}</comment>");
            }
        }

        $writeln("Sidecar files found: <comment>{$result['found']}</comment>");
        if ($result['skipped']) {
            $writeln("Matching names kept because they are File records: <comment>{$result['skipped']}</comment>");
        }
        if ($confirm) {
            $writeln("Deleted: <comment>{$result['deleted']}</comment>");
        } elseif ($result['found']) {
            $writeln('Run with <comment>' . $this->confirmHint() . '</comment> to delete them.');
        }

        return $result;
    }

    /**
     * Paths of every *.jpg.webp / *.jpeg.webp / *.png.webp file in the filesystem, outside
     * dot-directories
     *
     * @return string[]
     */
    protected function findSidecars(Filesystem $filesystem): array
    {
        $paths = [];
        /** @var StorageAttributes $item */
        foreach ($filesystem->listContents('', true) as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $path = $item->path();
            # .protected (when it sits inside the public assets dir), .htaccess, etc.
            if (preg_match('#(^|/)\.#', $path)) {
                continue;
            }
            if (preg_match(self::SIDECAR_PATTERN, $path)) {
                $paths[] = $path;
            }
        }
        sort($paths);
        return $paths;
    }

    /**
     * Is there a File record (any stage) with this file's name? Matched on the name rather than
     * the path, so it holds for natural paths and for hash-directory layouts alike.
     */
    protected function isFileRecord(string $path): bool
    {
        $name = basename($path);
        return (bool) Versioned::withVersionedMode(function () use ($name) {
            Versioned::set_stage(Versioned::DRAFT);
            return File::get()->filter('Name', $name)->exists();
        });
    }
}

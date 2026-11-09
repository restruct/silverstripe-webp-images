<?php

namespace Restruct\Silverstripe\WebpImages\Tests;

use Restruct\Silverstripe\WebpImages\Tasks\PurgeWebpSidecarsTask;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * The 2.x sidecar purge: finds foo.jpg.webp files in public assets, lists them on a dry run,
 * deletes them on confirm, and never touches images, core variants or real File records.
 *
 * Drives the version-neutral purgeSidecars() body, so the test means the same on both majors.
 */
class PurgeWebpSidecarsTaskTest extends SapphireTest
{
    use WebpTestHelpers;

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activateTestAssetStore();
    }

    protected function tearDown(): void
    {
        $this->resetTestAssetStore();
        parent::tearDown();
    }

    /**
     * The public filesystem as 2.x left it: an image, a variant, a sidecar for each, an orphan
     * sidecar whose image is gone, and one inside a dot-directory.
     *
     * @return array{0: \League\Flysystem\Filesystem, 1: Image}
     */
    private function legacyState(): array
    {
        $image = $this->makeImage('legacy.jpg');
        $variant = $image->ScaleWidth(200);
        # a core WebP variant too (3.0), which the purge must leave alone
        $variant->getURL();

        /** @var FlysystemAssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);
        $fs = $store->getPublicFilesystem();
        $fs->write('webptest/legacy.jpg.webp', 'x');
        $fs->write('webptest/' . basename($variant->getSourceURL()) . '.webp', 'x');
        $fs->write('webptest/deleted-long-ago.png.webp', 'x');
        $fs->write('.protected/webptest/hidden.jpg.webp', 'x');

        return [$fs, $image];
    }

    private function runTask(bool $confirm): array
    {
        $lines = [];
        $result = PurgeWebpSidecarsTask::create()->purgeSidecars($confirm, true, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $result['lines'] = $lines;
        return $result;
    }

    public function testDryRunListsButKeeps(): void
    {
        [$fs] = $this->legacyState();

        $result = $this->runTask(false);

        $this->assertSame(3, $result['found']);
        $this->assertSame(0, $result['deleted']);
        $this->assertTrue($fs->fileExists('webptest/legacy.jpg.webp'));
        $this->assertStringContainsString('DRY RUN', $result['lines'][0]);
    }

    public function testConfirmDeletesOnlySidecars(): void
    {
        [$fs, $image] = $this->legacyState();
        $webpVariantURL = $image->ScaleWidth(200)->getURL();

        $result = $this->runTask(true);

        $this->assertSame(3, $result['found']);
        $this->assertSame(3, $result['deleted']);
        $this->assertFalse($fs->fileExists('webptest/legacy.jpg.webp'));
        $this->assertFalse($fs->fileExists('webptest/deleted-long-ago.png.webp'));
        # dot-directories are not scanned
        $this->assertTrue($fs->fileExists('.protected/webptest/hidden.jpg.webp'));
        # the image, its jpg variant and its core webp variant are untouched
        $this->assertTrue($image->exists());
        $this->assertTrue($fs->fileExists('webptest/legacy.jpg'));
        $this->assertTrue($fs->fileExists('webptest/' . basename($webpVariantURL)));
        $this->assertTrue($fs->fileExists('webptest/' . basename($image->ScaleWidth(200)->getSourceURL())));
    }

    public function testRealFileRecordWithAMatchingNameIsKept(): void
    {
        # somebody uploaded a file literally called "photo.jpg.webp"
        $real = $this->makeImage('photo.jpg.webp', 'webp');
        /** @var FlysystemAssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);
        $fs = $store->getPublicFilesystem();
        $this->assertTrue($fs->fileExists('webptest/photo.jpg.webp'));

        $result = $this->runTask(true);

        $this->assertSame(0, $result['found']);
        $this->assertSame(1, $result['skipped']);
        $this->assertTrue($fs->fileExists('webptest/photo.jpg.webp'));
        $this->assertTrue($real->exists());
    }

    public function testNothingToPurge(): void
    {
        $this->makeImage('clean.jpg')->ScaleWidth(200)->getURL();

        $result = $this->runTask(true);

        $this->assertSame(0, $result['found']);
        $this->assertSame(0, $result['deleted']);
    }
}

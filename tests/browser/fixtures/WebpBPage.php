<?php

namespace Restruct\WebpBrowser;

use SilverStripe\AssetAdmin\Controller\AssetAdmin;
use SilverStripe\Assets\Image;
use SilverStripe\CMS\Model\SiteTree;

/**
 * BROWSER-TEST FIXTURE ONLY - a published front-end page at /webpb-page whose controller
 * (WebpBPageController) renders seeded images through the module, and the seeded images:
 * a published jpeg (800 x 600), a published gif and a draft-only (protected) jpeg.
 *
 * Never loaded by a real install: it lives under tests/browser/, which carries a _manifest_exclude
 * marker, and the browser-test runner copies it into a scratch host's app/ before dev/build.
 * Extends SiteTree, not Page: the scratch host has no app Page class.
 */
class WebpBPage extends SiteTree
{
    # short table name: no namespaced default
    private static $table_name = 'WebpBPage';

    public const PHOTO = 'webpb/webpb-photo.jpg';
    public const GIF = 'webpb/webpb-anim.gif';
    public const DRAFT = 'webpb/webpb-draft.jpg';

    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();

        $this->seed(self::PHOTO, 'jpg', true);
        $this->seed(self::GIF, 'gif', true);
        $this->seed(self::DRAFT, 'jpg', false);

        # The Files section only shows EXISTING thumbnails when browsing (asset-admin's
        # "assetadminopen" generator has Generates: false); make them, as an upload in the CMS
        # would, so the cms spec sees what a folder of earlier uploads looks like. Every build,
        # not only at seeding: a reused host may have lost them to a variant cleanup.
        $photo = Image::get()->filter('FileFilename', self::PHOTO)->first();
        if ($photo) {
            AssetAdmin::singleton()->generateThumbnails($photo);
        }

        if (!static::get()->filter('URLSegment', 'webpb-page')->exists()) {
            $page = static::create(['Title' => 'WebP images', 'URLSegment' => 'webpb-page']);
            $page->write();
            $page->publishRecursive();
        }
    }

    /**
     * An 800 x 600 test image drawn with GD (two colour blocks and some lines), stored once
     */
    private function seed(string $filename, string $type, bool $publish): void
    {
        if (Image::get()->filter('FileFilename', $filename)->exists()) {
            return;
        }
        $gd = imagecreatetruecolor(800, 600);
        imagefilledrectangle($gd, 0, 0, 399, 599, imagecolorallocate($gd, 52, 152, 219));
        imagefilledrectangle($gd, 400, 0, 799, 599, imagecolorallocate($gd, 231, 76, 60));
        for ($i = 0; $i < 800; $i += 9) {
            imageline($gd, $i, 0, 799 - $i, 599, imagecolorallocate($gd, ($i * 3) % 255, ($i * 5) % 255, ($i * 11) % 255));
        }
        ob_start();
        $type === 'gif' ? imagegif($gd) : imagejpeg($gd, null, 90);
        $content = ob_get_clean();

        $image = Image::create();
        $image->setFromString($content, $filename);
        $image->write();
        if ($publish) {
            $image->publishSingle();
        }
    }
}

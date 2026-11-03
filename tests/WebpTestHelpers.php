<?php

namespace Restruct\Silverstripe\WebpImages\Tests;

use Restruct\Silverstripe\WebpImages\WebpImages;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;

/**
 * Shared set-up for the suite.
 *
 * A trait rather than an abstract base test class on purpose: nothing in a module's tests/ may be
 * abstract if it could ever end up a DataObject (SOP abstract-fixture trap).
 *
 * The suite runs under PHPUnit 9 (Silverstripe 5) and PHPUnit 11 (Silverstripe 6): keep it free
 * of doc-comment metadata (@test, @dataProvider) and of assertions removed after PHPUnit 9.
 */
trait WebpTestHelpers
{
    protected function activateTestAssetStore(): void
    {
        # every file the suite writes goes to a throwaway store under the temp folder
        TestAssetStore::activate('WebpImagesTest');
        # fresh per-request state (memo, flags) for every test
        WebpImages::singleton()->reset();
    }

    protected function resetTestAssetStore(): void
    {
        TestAssetStore::reset();
    }

    /**
     * Raster test image made with GD: 400x300, two colour blocks so an encode is not trivially
     * tiny. $type: jpg, png (truecolor with alpha), palette (paletted png, upstream #2), gif, webp.
     */
    protected function makeImage(string $name, string $type = 'jpg', bool $publish = true): Image
    {
        $img = $type === 'palette' ? imagecreate(400, 300) : imagecreatetruecolor(400, 300);
        if ($type === 'png') {
            imagesavealpha($img, true);
            imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        }
        imagefilledrectangle($img, 0, 0, 199, 299, imagecolorallocate($img, 52, 152, 219));
        imagefilledrectangle($img, 200, 0, 399, 299, imagecolorallocate($img, 231, 76, 60));
        # some detail, so quality settings make a measurable size difference
        for ($i = 0; $i < 400; $i += 7) {
            imageline($img, $i, 0, 399 - $i, 299, imagecolorallocate($img, ($i * 3) % 255, ($i * 5) % 255, ($i * 11) % 255));
        }
        ob_start();
        match ($type) {
            'jpg' => imagejpeg($img, null, 95),
            'png', 'palette' => imagepng($img),
            'gif' => imagegif($img),
            'webp' => imagewebp($img, null, 90),
        };
        $content = ob_get_clean();

        $image = Image::create();
        $image->setFromString($content, 'webptest/' . $name);
        $image->write();
        if ($publish) {
            $image->publishSingle();
        }
        return $image;
    }

    /**
     * Mime type of a stored file or variant, read from the bytes in the store (not from the name)
     */
    protected function storedMime(AssetContainer $file): string
    {
        /** @var AssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);
        $content = $store->getAsString($file->getFilename(), $file->getHash(), $file->getVariant());
        return (new \finfo(FILEINFO_MIME_TYPE))->buffer((string) $content);
    }

    /**
     * Render a template string with $model as scope, through the real template engine of each
     * major: SSViewer::fromString() on Silverstripe 5, the silverstripe/template-engine package
     * (which renders through ViewLayerData) on Silverstripe 6.
     */
    protected function renderString(string $template, object $model): string
    {
        $engineClass = 'SilverStripe\\TemplateEngine\\SSTemplateEngine';
        if (class_exists($engineClass)) {
            $layerClass = 'SilverStripe\\View\\ViewLayerData';
            return (string) (new $engineClass())->renderString($template, new $layerClass($model), [], false);
        }
        return (string) \SilverStripe\View\SSViewer::fromString($template, false)->process($model);
    }

    /**
     * Pretend Director is handling a request for $url (as it registers one with the Injector)
     */
    protected function simulateRequest(string $url): void
    {
        Injector::inst()->registerService(new HTTPRequest('GET', $url), HTTPRequest::class);
        WebpImages::singleton()->reset();
    }
}

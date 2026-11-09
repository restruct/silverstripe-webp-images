<?php

namespace Restruct\Silverstripe\WebpImages\Tests;

use Restruct\Silverstripe\WebpImages\Extensions\WebpUrlExtension;
use Restruct\Silverstripe\WebpImages\Tests\Stub\CountingFileConverterManager;
use Restruct\Silverstripe\WebpImages\WebpImages;
use SilverStripe\Assets\Conversion\FileConverterManager;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * The updateSignedURLFile hook of restruct/silverstripe-signed-asset-urls (1.4+): a signed URL is
 * built for the WebP variant whenever the plain URL would be rewritten to it.
 *
 * signed-asset-urls is a suggestion, not a dependency, and CI does not install it, so the hook is
 * tested by calling it directly, the way that module calls it. The end-to-end test at the bottom
 * signs a URL only when signed-asset-urls is installed; without it, it checks that the module
 * adds no signing methods of its own (the suite has no skips: CI runs with failOnSkipped).
 */
class SignedUrlFileHookTest extends SapphireTest
{
    use WebpTestHelpers;

    protected $usesDatabase = true;

    /**
     * signed-asset-urls' DBFile extension, by name: the class may not exist
     */
    private const SIGNED_URL_EXTENSION = 'Restruct\\SilverStripe\\SignedAssetUrls\\Extensions\\SignedUrlDBFileExtension';

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
     * Run the hook as signed-asset-urls does: $file starts as the DBFile being signed
     */
    private function hook(mixed $file): mixed
    {
        (new WebpUrlExtension())->updateSignedURLFile($file);
        return $file;
    }

    public function testResizedJpegIsSwappedForItsWebpVariant(): void
    {
        $variant = $this->makeImage('signed.jpg')->ScaleWidth(200);

        $swapped = $this->hook($variant);
        $this->assertInstanceOf(DBFile::class, $swapped);
        $this->assertNotSame($variant, $swapped);
        # the same stored file (signed-asset-urls ignores any other), another variant
        $this->assertSame($variant->getFilename(), $swapped->getFilename());
        $this->assertSame($variant->getHash(), $swapped->getHash());
        $this->assertStringStartsWith($variant->getVariant() . '_ExtRewrite', (string) $swapped->getVariant());
        $this->assertTrue($swapped->exists());
        $this->assertSame('image/webp', $this->storedMime($swapped));
        # the same file the plain URL points at
        $this->assertSame($variant->getURL(), $swapped->getSourceURL(false));
    }

    public function testDraftImageVariantIsSwapped(): void
    {
        # a draft image sits in the protected store: the case signed URLs exist for
        $variant = $this->makeImage('signed-draft.jpg', 'jpg', false)->ScaleWidth(200);

        $swapped = $this->hook($variant);
        $this->assertStringEndsWith('.webp', (string) $swapped->getSourceURL(false));
        $this->assertSame('image/webp', $this->storedMime($swapped));
    }

    public function testOriginalIsNotSwapped(): void
    {
        $image = $this->makeImage('signed-orig.jpg');

        $this->assertSame($image->File, $this->hook($image->File), 'originals stay the uploaded file (convert_originals)');
    }

    public function testDisableWebPIsHonoured(): void
    {
        $variant = $this->makeImage('signed-dis.jpg')->ScaleWidth(200)->DisableWebP();

        $this->assertSame($variant, $this->hook($variant));
    }

    public function testRewriteUrlsOffIsHonoured(): void
    {
        WebpImages::config()->set('rewrite_urls', false);
        $variant = $this->makeImage('signed-off.jpg')->ScaleWidth(200);

        $this->assertSame($variant, $this->hook($variant));
    }

    public function testConvertedVariantIsNotSwappedAgain(): void
    {
        $webp = $this->makeImage('signed-conv.jpg')->ScaleWidth(200)->Convert('webp');

        $this->assertSame($webp, $this->hook($webp));
    }

    public function testGifIsNotSwapped(): void
    {
        $variant = $this->makeImage('signed.gif', 'gif')->ScaleWidth(200);

        $this->assertSame($variant, $this->hook($variant));
    }

    public function testNonFileIsLeftAlone(): void
    {
        $this->assertNull($this->hook(null));
        $this->assertSame('x', $this->hook('x'));
    }

    public function testSwappedFileIsMemoisedPerRequest(): void
    {
        $image = $this->makeImage('signed-memo.jpg');
        $spy = new CountingFileConverterManager();
        Injector::inst()->registerService($spy, FileConverterManager::class);

        $first = $this->hook($image->ScaleWidth(200));
        $this->assertSame(1, $spy->calls);
        $this->assertSame($first, $this->hook($image->ScaleWidth(200)), 'answered from the memo');
        $this->assertSame(1, $spy->calls);

        WebpImages::singleton()->reset();
        $this->hook($image->ScaleWidth(200));
        $this->assertSame(2, $spy->calls);
    }

    public function testSignedUrlIsForTheWebpVariantWhenSignedAssetUrlsIsInstalled(): void
    {
        if (!class_exists(self::SIGNED_URL_EXTENSION)) {
            # without signed-asset-urls nothing calls the hook, and this module adds no AutoURL
            $this->assertFalse(DBFile::create()->hasMethod('AutoURL'));
            return;
        }

        Environment::setEnv('ASSET_SIGNING_SECRET', 'test-signing-secret-0123456789');
        $variant = $this->makeImage('signed-e2e.jpg', 'jpg', false)->ScaleWidth(200);

        $url = (string) $variant->AutoURL();
        $this->assertStringStartsWith('/signed-asset/', $url, 'a draft image gets a signed URL');
        $this->assertMatchesRegularExpression('#__[^/?]*_ExtRewrite[^/?]*\.webp\?#', $url, 'for its WebP variant');
    }
}

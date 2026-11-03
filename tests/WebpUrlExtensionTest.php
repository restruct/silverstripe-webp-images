<?php

namespace Restruct\Silverstripe\WebpImages\Tests;

use Restruct\Silverstripe\WebpImages\Extensions\WebpImageExtension;
use Restruct\Silverstripe\WebpImages\Extensions\WebpUrlExtension;
use Restruct\Silverstripe\WebpImages\WebpImages;
use SilverStripe\Assets\Conversion\FileConverterManager;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use Restruct\Silverstripe\WebpImages\Tests\Stub\CountingFileConverterManager;

/**
 * Automatic mode: which image URLs become WebP, and what each setting and template flag changes.
 */
class WebpUrlExtensionTest extends SapphireTest
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

    # -------------------------------------------------------------------------
    # Registration
    # -------------------------------------------------------------------------

    public function testExtensionsAreApplied(): void
    {
        $this->assertTrue(DBFile::has_extension(WebpUrlExtension::class));
        $this->assertTrue(DBFile::has_extension(WebpImageExtension::class));
        $this->assertTrue(Image::has_extension(WebpImageExtension::class));
    }

    public function testDefaults(): void
    {
        $config = WebpImages::config();
        $this->assertTrue($config->get('enabled'));
        $this->assertTrue($config->get('rewrite_urls'));
        $this->assertFalse($config->get('convert_originals'));
        $this->assertFalse($config->get('convert_in_cms'));
        $this->assertSame(['jpg', 'jpeg', 'png'], $config->get('convert_extensions'));
        $this->assertSame(80, $config->get('quality'));
        $this->assertSame(['avif' => false, 'webp' => true], $config->get('picture_formats'));
        $this->assertSame(['webp'], WebpImages::singleton()->getPictureFormats());
    }

    # -------------------------------------------------------------------------
    # What is converted
    # -------------------------------------------------------------------------

    public function testResizedJpegIsServedAsCoreWebpVariant(): void
    {
        $image = $this->makeImage('photo.jpg');
        $variant = $image->ScaleWidth(200);

        $url = $variant->getURL();
        $this->assertStringEndsWith('.webp', $url);
        # a core ExtRewrite variant, not a 2.x "photo.jpg.webp" sidecar
        $this->assertStringContainsString('ExtRewrite', $url);
        $this->assertStringNotContainsString('.jpg.webp', $url);

        $webp = $variant->Convert('webp');
        $this->assertNotNull($webp);
        $this->assertTrue($webp->exists());
        $this->assertSame('image/webp', $this->storedMime($webp));
    }

    public function testOriginalIsNotConverted(): void
    {
        $image = $this->makeImage('original.jpg');

        $this->assertStringEndsWith('original.jpg', $image->getURL());
        $this->assertStringEndsWith('original.jpg', $image->File->getURL());
    }

    public function testTagKeepsTheVariantsDimensions(): void
    {
        $html = (string) $this->makeImage('dims.jpg')->ScaleWidth(200)->forTemplate();

        $this->assertMatchesRegularExpression('/src="[^"]+\.webp"/', $html);
        $this->assertStringContainsString('width="200"', $html);
        $this->assertStringContainsString('height="150"', $html);
    }

    public function testTruecolorPngWithAlphaIsConverted(): void
    {
        $variant = $this->makeImage('alpha.png', 'png')->ScaleWidth(200);

        $this->assertStringEndsWith('.webp', $variant->getURL());
        $this->assertSame('image/webp', $this->storedMime($variant->Convert('webp')));
    }

    /**
     * Upstream #2: a paletted PNG used to come out wrong through raw GD.
     */
    public function testPalettePngIsConverted(): void
    {
        $variant = $this->makeImage('palette.png', 'palette')->ScaleWidth(200);

        $this->assertStringEndsWith('.webp', $variant->getURL());
        $this->assertSame('image/webp', $this->storedMime($variant->Convert('webp')));
    }

    public function testGifIsLeftAlone(): void
    {
        $url = $this->makeImage('anim.gif', 'gif')->ScaleWidth(200)->getURL();

        $this->assertStringEndsWith('.gif', $url);
    }

    /**
     * Upstream #16: an uploaded .webp was fed back into the encoder as if it were a jpeg.
     */
    public function testUploadedWebpIsNotReconverted(): void
    {
        $url = $this->makeImage('already.webp', 'webp')->ScaleWidth(200)->getURL();

        $this->assertStringEndsWith('.webp', $url);
        $this->assertStringNotContainsString('ExtRewrite', $url);
    }

    /**
     * Phase-2 finding: an ExtRewrite variant keeps the ORIGINAL Filename (foo.jpg), so an
     * extension check let a Convert('webp')->ScaleWidth() chain be converted a second time.
     */
    public function testConvertedVariantIsNotConvertedAgain(): void
    {
        $image = $this->makeImage('chain.jpg');
        $chained = $image->Convert('webp')->ScaleWidth(200);
        $url = $chained->getURL();

        $this->assertSame(1, substr_count($url, 'ExtRewrite'), "converted once: {$url}");
        $this->assertSame($chained->getSourceURL(), $url);
    }

    public function testDeletingTheImageRemovesItsWebpVariant(): void
    {
        $image = $this->makeImage('gone.jpg');
        $webp = $image->ScaleWidth(200)->Convert('webp');
        /** @var AssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);
        $this->assertTrue($store->exists($webp->getFilename(), $webp->getHash(), $webp->getVariant()));

        # the 2.x adapter delete was broken, so its sidecars outlived deleted images
        $image->doArchive();

        $this->assertFalse($store->exists($webp->getFilename(), $webp->getHash(), $webp->getVariant()));
    }

    public function testDraftImageVariantIsConvertedInTheProtectedStore(): void
    {
        $image = $this->makeImage('draft.jpg', 'jpg', false);
        $variant = $image->ScaleWidth(200);

        $url = $variant->getURL();
        $this->assertStringEndsWith('.webp', $url);
        $webp = $variant->Convert('webp');
        $this->assertSame(AssetStore::VISIBILITY_PROTECTED, $webp->getVisibility());
    }

    # -------------------------------------------------------------------------
    # Settings
    # -------------------------------------------------------------------------

    public function testDisabledConvertsNothing(): void
    {
        WebpImages::config()->set('enabled', false);

        $this->assertStringEndsWith('.jpg', $this->makeImage('off.jpg')->ScaleWidth(200)->getURL());
    }

    public function testRewriteUrlsOffKeepsVariantUrls(): void
    {
        WebpImages::config()->set('rewrite_urls', false);

        $this->assertStringEndsWith('.jpg', $this->makeImage('norewrite.jpg')->ScaleWidth(200)->getURL());
    }

    public function testConvertOriginals(): void
    {
        WebpImages::config()->set('convert_originals', true);

        $this->assertStringEndsWith('.webp', $this->makeImage('orig.jpg')->getURL());
    }

    public function testConvertExtensions(): void
    {
        WebpImages::config()->set('convert_extensions', ['png']);

        $this->assertStringEndsWith('.jpg', $this->makeImage('only-png.jpg')->ScaleWidth(200)->getURL());
        $this->assertStringEndsWith('.webp', $this->makeImage('only-png.png', 'png')->ScaleWidth(200)->getURL());
    }

    public function testQualityIsPassedToTheConverter(): void
    {
        WebpImages::config()->set('quality', 5);
        $low = $this->makeImage('low.jpg')->ScaleWidth(300)->Convert('webp');
        $lowSize = strlen((string) $low->getString());

        # quality is not part of the variant name, so compare two different images
        WebpImages::config()->set('quality', 100);
        WebpImages::singleton()->reset();
        $high = $this->makeImage('high.jpg')->ScaleWidth(300);
        $high->getURL();
        $highSize = strlen((string) $high->Convert('webp')->getString());

        $this->assertLessThan($highSize, $lowSize, "quality 5 ({$lowSize} B) smaller than quality 100 ({$highSize} B)");
    }

    public function testQualityConfigReachesTheConversion(): void
    {
        # the size check above uses Convert() directly for the low image; this one goes through
        # the URL hook for both, which is the path a template takes
        WebpImages::config()->set('quality', 5);
        $low = $this->makeImage('hook-low.jpg')->ScaleWidth(300);
        $low->getURL();
        WebpImages::config()->set('quality', 100);
        $high = $this->makeImage('hook-high.jpg')->ScaleWidth(300);
        $high->getURL();

        $lowSize = strlen((string) $low->Convert('webp')->getString());
        $highSize = strlen((string) $high->Convert('webp')->getString());
        $this->assertLessThan($highSize, $lowSize);
    }

    public function testNoConversionInCmsRequestsByDefault(): void
    {
        $image = $this->makeImage('cms.jpg');
        $this->simulateRequest('admin/assets');

        $this->assertTrue(WebpImages::singleton()->isCmsRequest());
        $this->assertStringEndsWith('.jpg', $image->ScaleWidth(200)->getURL());
        # and nothing was written behind the scenes
        $variant = $image->ScaleWidth(200);
        $webpVariant = $variant->getVariant() . '_' . $variant->variantName('ExtRewrite', 'jpg', 'webp');
        $this->assertFalse(Injector::inst()->get(AssetStore::class)->exists($variant->getFilename(), $variant->getHash(), $webpVariant));
    }

    public function testConvertInCms(): void
    {
        WebpImages::config()->set('convert_in_cms', true);
        $image = $this->makeImage('cms-on.jpg');
        $this->simulateRequest('admin/assets');

        $this->assertStringEndsWith('.webp', $image->ScaleWidth(200)->getURL());
    }

    public function testFrontendRequestIsNotACmsRequest(): void
    {
        $this->simulateRequest('about-us/administration');

        $this->assertFalse(WebpImages::singleton()->isCmsRequest());
    }

    # -------------------------------------------------------------------------
    # Template flags, per image object
    # -------------------------------------------------------------------------

    public function testDisableWebPOnAVariant(): void
    {
        $image = $this->makeImage('dis.jpg');

        $this->assertStringEndsWith('.jpg', $image->ScaleWidth(200)->DisableWebP()->getURL());
        # another variant of the same image is not affected
        $this->assertStringEndsWith('.webp', $image->ScaleWidth(250)->getURL());
    }

    public function testDisableWebPOnTheRecordCarriesToVariantsMadeFromIt(): void
    {
        $image = $this->makeImage('dis-rec.jpg');

        $this->assertStringEndsWith('.jpg', $image->DisableWebP()->ScaleWidth(200)->getURL());
    }

    /**
     * 2.x known issue: `$Image.DisableWebP` in a template had no effect, because the one-shot
     * global flag was used up by the first of several getURL() calls.
     */
    public function testDisableWebPInATemplate(): void
    {
        $image = $this->makeImage('tpl.jpg');

        $html = $this->renderString('$ScaleWidth(200).DisableWebP|$ScaleWidth(201)', $image);
        [$disabled, $enabled] = explode('|', $html);

        $this->assertMatchesRegularExpression('/src="[^"]+\.jpg"/', $disabled);
        $this->assertMatchesRegularExpression('/src="[^"]+\.webp"/', $enabled);
    }

    public function testEnableWebPConvertsAnOriginal(): void
    {
        $image = $this->makeImage('en.jpg');

        $this->assertStringEndsWith('.webp', $image->EnableWebP()->getURL());
    }

    public function testEnableWebPOverridesDisabledConfig(): void
    {
        WebpImages::config()->set('enabled', false);
        $image = $this->makeImage('en-off.jpg');

        $this->assertStringEndsWith('.webp', $image->ScaleWidth(200)->EnableWebP()->getURL());
    }

    # -------------------------------------------------------------------------
    # Deprecated 2.x settings
    # -------------------------------------------------------------------------

    public function testLegacyEnabledSettingStillApplies(): void
    {
        $legacy = \WeDevelop\WebpImages\WebpGenerator::singleton();
        $legacy->setEnabled(false);
        try {
            $this->assertStringEndsWith('.jpg', $this->makeImage('legacy.jpg')->ScaleWidth(200)->getURL());
        } finally {
            $legacy->enabled = null;
        }
    }

    public function testLegacyQualitySettingStillApplies(): void
    {
        $legacy = \WeDevelop\WebpImages\WebpGenerator::singleton();
        $legacy->quality = 42;
        try {
            $this->assertSame(42, WebpImages::singleton()->getQuality('webp'));
            # not for avif: 2.x never made avif
            $this->assertNull(WebpImages::singleton()->getQuality('avif'));
        } finally {
            $legacy->quality = null;
        }
        $this->assertSame(80, WebpImages::singleton()->getQuality('webp'));
    }

    # -------------------------------------------------------------------------
    # Per-request memo
    # -------------------------------------------------------------------------

    public function testUrlIsMemoisedPerRequest(): void
    {
        $image = $this->makeImage('memo.jpg');
        $spy = new CountingFileConverterManager();
        Injector::inst()->registerService($spy, FileConverterManager::class);

        $first = $image->ScaleWidth(200)->getURL();
        $this->assertSame(1, $spy->calls);
        # the same variant URL again, in the same request: answered from the memo
        $this->assertSame($first, $image->ScaleWidth(200)->getURL());
        $this->assertSame(1, $spy->calls);

        WebpImages::singleton()->reset();
        $this->assertSame($first, $image->ScaleWidth(200)->getURL());
        $this->assertSame(2, $spy->calls);
    }
}

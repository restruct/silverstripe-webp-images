<?php

namespace Restruct\Silverstripe\WebpImages\Tests;

use Restruct\Silverstripe\WebpImages\WebpImages;
use Restruct\Silverstripe\WebpImages\Tests\Stub\CountingFileConverterManager;
use SilverStripe\Assets\Conversion\FileConverterManager;
use SilverStripe\Assets\Conversion\InterventionImageFileConverter;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * $Image.Picture: <picture> with one <source> per configured format and the image in its own
 * format as the <img> fallback, plus the AVIF config the module ships for it.
 */
class PictureTest extends SapphireTest
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

    public function testPictureHasAWebpSourceAndTheJpegAsFallback(): void
    {
        $html = (string) $this->makeImage('pic.jpg')->ScaleWidth(200)->Picture();

        $this->assertStringStartsWith('<picture>', trim($html));
        $this->assertMatchesRegularExpression('#<source type="image/webp" srcset="[^"]+ExtRewrite[^"]+\.webp">#', $html);
        $this->assertMatchesRegularExpression('#<img [^>]*src="[^"]+\.jpg"#', $html);
        $this->assertStringContainsString('width="200"', $html);
        $this->assertStringEndsWith('</picture>', trim($html));
        $this->assertSame(1, substr_count($html, '<source'));
    }

    public function testPictureFromATemplate(): void
    {
        $html = $this->renderString('$ScaleWidth(200).Picture', $this->makeImage('pic-tpl.jpg'));

        $this->assertStringContainsString('<source type="image/webp"', $html);
        $this->assertMatchesRegularExpression('#<img [^>]*src="[^"]+\.jpg"#', $html);
    }

    public function testPictureOnTheOriginal(): void
    {
        $html = (string) $this->makeImage('pic-orig.jpg')->Picture();

        $this->assertStringContainsString('<source type="image/webp"', $html);
        $this->assertMatchesRegularExpression('#<img [^>]*src="[^"]+pic-orig\.jpg"#', $html);
    }

    public function testPictureLeavesTheImageAsItWas(): void
    {
        $variant = $this->makeImage('pic-after.jpg')->ScaleWidth(200);
        $variant->Picture();

        # the fallback is rendered with WebP off temporarily; the variant itself still converts
        $this->assertStringEndsWith('.webp', $variant->getURL());
    }

    public function testPictureWorksWithAutomaticModeOff(): void
    {
        WebpImages::config()->set('rewrite_urls', false);
        $variant = $this->makeImage('pic-manual.jpg')->ScaleWidth(200);

        $this->assertStringContainsString('<source type="image/webp"', (string) $variant->Picture());
        $this->assertStringEndsWith('.jpg', $variant->getURL());
    }

    public function testGifRendersAPlainImg(): void
    {
        $html = trim((string) $this->makeImage('pic.gif', 'gif')->ScaleWidth(200)->Picture());

        $this->assertStringStartsWith('<img', $html);
        $this->assertStringNotContainsString('<picture', $html);
    }

    public function testDisabledRendersAPlainImg(): void
    {
        WebpImages::config()->set('enabled', false);
        $html = trim((string) $this->makeImage('pic-off.jpg')->ScaleWidth(200)->Picture());

        $this->assertStringStartsWith('<img', $html);
    }

    public function testDisableWebPRendersAPlainImg(): void
    {
        $html = trim((string) $this->makeImage('pic-dis.jpg')->ScaleWidth(200)->DisableWebP()->Picture());

        $this->assertStringStartsWith('<img', $html);
    }

    /**
     * AVIF comes first when configured, and only when GD/Imagick can encode it; otherwise the
     * source is left out (logged) and the WebP one remains. Asserting both branches keeps the
     * test meaningful on a CI runner whatever its GD build, without a skip.
     */
    public function testAvifSourceWhenConfigured(): void
    {
        # the way a project turns it on: merged into the module's map
        WebpImages::config()->merge('picture_formats', ['avif' => true]);
        $this->assertSame(['avif', 'webp'], WebpImages::singleton()->getPictureFormats());
        $variant = $this->makeImage('pic-avif.jpg')->ScaleWidth(200);
        $html = (string) $variant->Picture();

        $canAvif = WebpImages::singleton()->convert($variant, 'avif') !== null;
        if ($canAvif) {
            $this->assertMatchesRegularExpression('#<source type="image/avif" srcset="[^"]+\.avif"><source type="image/webp"#', $html);
        } else {
            $this->assertStringNotContainsString('image/avif', $html);
            $this->assertStringContainsString('<source type="image/webp"', $html);
        }
    }

    public function testPictureFormatsOrderAndOff(): void
    {
        # order is fixed (avif first) whatever order config arrives in, and false switches off
        WebpImages::config()->set('picture_formats', ['webp' => true, 'avif' => true]);
        $this->assertSame(['avif', 'webp'], WebpImages::singleton()->getPictureFormats());

        WebpImages::config()->set('picture_formats', ['webp' => false, 'avif' => true]);
        $this->assertSame(['avif'], WebpImages::singleton()->getPictureFormats());

        # a plain list still works
        WebpImages::config()->set('picture_formats', ['webp', 'avif']);
        $this->assertSame(['avif', 'webp'], WebpImages::singleton()->getPictureFormats());
    }

    # -------------------------------------------------------------------------
    # Two conversions of one variant (assets 2 + Imagick)
    # -------------------------------------------------------------------------

    /**
     * `$Image.Picture` with AVIF on converts the same variant twice (avif, webp) and then renders
     * the <img> from it. On assets 2 core's converter shallow-clones the image backend it is
     * handed, and the clone's __destruct() destroys the Intervention image it shares with the
     * original, which under Imagick empties the original's Imagick object: the next use of the
     * variant threw "Can not process empty Imagick object" (HTTP 500 on the page).
     *
     * The failure needs Imagick, and a CI runner may not have it. Without Imagick the same
     * scenario runs on GD, where it cannot fail (GD's destroy is a no-op), so the test below this
     * one covers the cause itself on every backend. No skip: the suite has none by design.
     */
    public function testPictureWithAvifLeavesTheVariantUsable(): void
    {
        $this->useImagick();
        WebpImages::config()->merge('picture_formats', ['avif' => true]);
        $variant = $this->makeImage('pic-twice.jpg')->ScaleWidth(200);

        $html = (string) $variant->Picture();

        $this->assertStringContainsString('<source type="image/webp"', $html);
        $this->assertMatchesRegularExpression('#<img [^>]*src="[^"]+\.jpg"#', $html);
        $this->assertStringContainsString('width="200"', $html);
        # and the variant is still usable after the conversions, eg for a further manipulation
        $this->assertSame(100, (int) $variant->ScaleWidth(100)->getWidth());
    }

    /**
     * The cause, on every backend: the module must not hand core's converter the caller's own
     * file object, whose image backend the converter's shallow clone would share (see above).
     */
    public function testConverterIsNotHandedTheCallersFileObject(): void
    {
        $spy = new CountingFileConverterManager();
        Injector::inst()->registerService($spy, FileConverterManager::class);
        $variant = $this->makeImage('own-object.jpg')->ScaleWidth(200);
        $record = $this->makeImage('own-record.jpg');

        $converted = WebpImages::singleton()->convert($variant, 'webp');
        WebpImages::singleton()->convert($record, 'webp');

        $this->assertSame(2, $spy->calls);
        $this->assertNotSame($variant, $spy->from[0]);
        $this->assertNotSame($record, $spy->from[1]);
        # it is the same stored file all the same
        $this->assertSame($variant->getHash(), $spy->from[0]->getHash());
        $this->assertSame($variant->getVariant(), $spy->from[0]->getVariant());
        $this->assertSame($record->getFilename(), $spy->from[1]->getFilename());
        $this->assertStringContainsString('ExtRewrite', (string) $converted->getVariant());
    }

    /**
     * The file object handed to the converter is a fresh one (see above): it must still honour
     * the caller's existing-only flag, as core sets it for CMS thumbnails
     */
    public function testExistingOnlyStillAppliesToTheConversion(): void
    {
        $variant = $this->makeImage('existing-only.jpg')->ScaleWidth(200);
        $variant->setAllowGeneration(false);

        $this->assertNull(WebpImages::singleton()->convert($variant, 'webp'));

        $variant->setAllowGeneration(true);
        $this->assertNotNull(WebpImages::singleton()->convert($variant, 'webp'));
    }

    # -------------------------------------------------------------------------
    # AVIF as a first-class image (_config/avif.yml)
    # -------------------------------------------------------------------------

    public function testAvifIsAnAllowedImageType(): void
    {
        $this->assertContains('avif', File::config()->get('allowed_extensions'));
        $this->assertContains('avif', File::config()->get('app_categories')['image']);
        $this->assertContains('avif', File::config()->get('app_categories')['image/supported']);
        $this->assertContains('image/avif', DBFile::config()->get('supported_images'));
    }

    public function testAvifVariantIsAnImage(): void
    {
        $avif = WebpImages::singleton()->convert($this->makeImage('is-image.jpg')->ScaleWidth(200), 'avif');
        if ($avif === null) {
            # no AVIF encoder in this PHP build: then core's converter must say so too
            $converter = Injector::inst()->get(InterventionImageFileConverter::class);
            $this->assertFalse($converter->supportsConversion('jpg', 'avif'));
            return;
        }
        $this->assertSame('image/avif', $this->storedMime($avif));
        $this->assertTrue($avif->getIsImage());
        $this->assertStringStartsWith('<img', trim((string) $avif->forTemplate()));
    }
}

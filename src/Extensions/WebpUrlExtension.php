<?php

declare(strict_types=1);

namespace Restruct\Silverstripe\WebpImages\Extensions;

use Restruct\Silverstripe\WebpImages\WebpImages;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Core\Extension;

/**
 * Automatic mode: a resized jpeg/png variant's URL becomes the URL of its WebP variant.
 *
 * Applied to DBFile, so it sees every getURL() of a stored file or one of its variants (the Image
 * record's getURL() delegates to its DBFile). No Accept-header check: WebP is supported by every
 * current browser, and the HTML stays the same for every visitor, so page caches, static
 * publishing and CDNs are safe (2.x varied the HTML per request without a Vary header).
 * The width/height attributes of the tag stay those of the source variant.
 *
 * @extends Extension<DBFile>
 */
class WebpUrlExtension extends Extension
{
    /**
     * @param string $url
     */
    public function updateURL(&$url): void
    {
        $owner = $this->getOwner();
        if (!$owner instanceof DBFile || !is_string($url) || $url === '') {
            return;
        }

        $service = WebpImages::singleton();
        if (!$service->shouldRewrite($owner)) {
            return;
        }

        $webp = $service->getConvertedURL($owner, $url, 'webp');
        if ($webp !== null) {
            $url = $webp;
        }
    }

    /**
     * restruct/silverstripe-signed-asset-urls (1.4+): sign the WebP variant instead of the
     * jpeg/png one. A signed URL is built from the stored file (getSourceURL()), not through
     * updateURL above, so without this hook `$Image.ScaleWidth(400).AutoURL` signed the jpeg.
     * Same decision as updateURL (shouldRewrite()); the swapped file is a variant of the same
     * stored file, which that module requires. Without that module nothing calls this.
     *
     * @param mixed $file the DBFile about to be signed (the owner, unless another extension swapped it)
     */
    public function updateSignedURLFile(&$file): void
    {
        if (!$file instanceof DBFile) {
            return;
        }

        $service = WebpImages::singleton();
        if (!$service->shouldRewrite($file)) {
            return;
        }

        $webp = $service->getConvertedFile($file, 'webp');
        if ($webp !== null) {
            $file = $webp;
        }
    }
}

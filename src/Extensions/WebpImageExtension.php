<?php

declare(strict_types=1);

namespace Restruct\Silverstripe\WebpImages\Extensions;

use Restruct\Silverstripe\WebpImages\WebpImages;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;

/**
 * Template helpers, on the Image record and on its variants (DBFile):
 *
 * - `$Image.ScaleWidth(400).DisableWebP` / `$Image.DisableWebP.ScaleWidth(400)`: keep this image
 *   in its own format.
 * - `$Image.EnableWebP`: convert this image even where the config would not (an original, a CMS
 *   request, `enabled: false`).
 * - `$Image.ScaleWidth(400).Picture`: a <picture> with AVIF/WebP <source>s and the jpeg/png <img>
 *   as fallback (template Restruct/Silverstripe/WebpImages/Picture, overridable in a theme).
 *
 * 2.x kept Enable/Disable as one global "next URL" flag, which `$Image.DisableWebP` alone could not
 * use (the template engine calls getURL() more than once). The flag is now held per image object,
 * and variants made from a flagged object afterwards inherit it.
 *
 * @extends Extension<File|DBFile>
 */
class WebpImageExtension extends Extension
{
    /**
     * Convert this image (and variants made from it afterwards) regardless of config
     *
     * @return File|DBFile
     */
    public function EnableWebP()
    {
        $this->flag(true);
        return $this->getOwner();
    }

    /**
     * Keep this image (and variants made from it afterwards) in its own format
     *
     * @return File|DBFile
     */
    public function DisableWebP()
    {
        $this->flag(false);
        return $this->getOwner();
    }

    /**
     * Render this image as <picture>: one <source> per configured format (WebpImages.picture_formats,
     * eg avif then webp), then the image in its own format as the <img> fallback. A format that
     * cannot be produced (no AVIF support in GD/Imagick, a gif, `enabled: false`) is left out; with
     * no sources left the template renders the plain <img>.
     */
    public function Picture(): DBHTMLText
    {
        $owner = $this->getOwner();
        $service = WebpImages::singleton();
        $file = $this->getDBFile();

        $sources = [];
        $flag = $file ? $service->getFlag($owner) : false;
        $convert = $file && $flag !== false && ($flag === true || $service->isEnabled()) && $service->isConvertible($file);
        if ($convert) {
            $sourceURL = (string) $file->getSourceURL(false);
            foreach ($service->getPictureFormats() as $format) {
                $url = $service->getConvertedURL($file, $sourceURL, $format);
                if ($url !== null) {
                    $sources[] = ['Format' => $format, 'Type' => 'image/' . $format, 'SrcSet' => $url];
                }
            }
        }

        $listClass = class_exists('SilverStripe\\Model\\List\\ArrayList')
            ? 'SilverStripe\\Model\\List\\ArrayList'  # Silverstripe 6
            : 'SilverStripe\\ORM\\ArrayList';          # Silverstripe 5

        return $owner->renderWith('Restruct\\Silverstripe\\WebpImages\\Picture', [
            'Sources' => new $listClass($sources),
            'Img' => DBField::create_field('HTMLFragment', $this->renderFallback()),
        ]);
    }

    /**
     * The <img> in the image's own format: rendered right here with WebP switched off for the
     * owner, then the previous flag restored, so the owner (eg a $Image used again further down
     * the template) is left exactly as it was.
     */
    protected function renderFallback(): string
    {
        $service = WebpImages::singleton();
        $targets = $this->flagTargets();
        $previous = [];
        foreach ($targets as $i => $target) {
            $previous[$i] = $service->getFlag($target);
            $service->setFlag($target, false);
        }
        try {
            # trimmed: the core image template ends in a newline, which would land inside <picture>
            return trim((string) $this->getOwner()->forTemplate());
        } finally {
            foreach ($targets as $i => $target) {
                $service->setFlag($target, $previous[$i]);
            }
        }
    }

    protected function flag(bool $enabled): void
    {
        $service = WebpImages::singleton();
        foreach ($this->flagTargets() as $target) {
            $service->setFlag($target, $enabled);
        }
    }

    /**
     * The objects a flag must be set on. A variant is one DBFile; the Image record is the record
     * (which its variants find through their failover chain) plus its own DBFile field, because
     * the record's getURL() goes through that field, which has no link back to the record.
     *
     * @return object[]
     */
    protected function flagTargets(): array
    {
        $owner = $this->getOwner();
        $targets = [$owner];
        if ($owner instanceof File && $owner->File instanceof DBFile) {
            $targets[] = $owner->File;
        }
        return $targets;
    }

    /**
     * The stored file behind the owner: the owner itself for a variant, the File field for a record
     */
    protected function getDBFile(): ?DBFile
    {
        $owner = $this->getOwner();
        if ($owner instanceof DBFile) {
            return $owner->exists() ? $owner : null;
        }
        if ($owner instanceof File && $owner->File instanceof DBFile && $owner->File->exists()) {
            return $owner->File;
        }
        return null;
    }
}

<?php

declare(strict_types=1);

namespace WeDevelop\WebpImages;

use SilverStripe\Core\Injector\Injectable;

/**
 * Deprecated 2.x settings holder, kept for one major so existing projects keep working unchanged:
 *
 *     SilverStripe\Core\Injector\Injector:
 *       WeDevelop\WebpImages\WebpGenerator:
 *         properties:
 *           enabled: false
 *           quality: 70
 *
 * and `WebpGenerator::singleton()->setEnabled(false)` in PHP. Both are read by
 * Restruct\Silverstripe\WebpImages\WebpImages (isEnabled(), getQuality()) and override its config
 * when set. This class no longer generates anything: the 2.x GD encoder and its .webp sidecar
 * files are gone (code kept in docs/legacy-2x.md).
 *
 * @deprecated 3.0.0 Use Restruct\Silverstripe\WebpImages\WebpImages config (enabled, quality) instead.
 * Will be removed in 4.0.0.
 */
final class WebpGenerator
{
    use Injectable;

    # 2.x had `private ?bool $enabledForNextGenerate`, the global one-shot flag behind
    # $Image.EnableWebP / DisableWebP; those are per image object now (WebpImages::setFlag()).
    // private ?bool $enabledForNextGenerate = null;

    /**
     * Null (not set) leaves the decision to WebpImages.enabled. Was `bool $enabled = true` in 2.x.
     */
    public ?bool $enabled = null;

    /**
     * Null (not set) leaves the quality to WebpImages.quality. Was `int $quality = 80` in 2.x.
     */
    public ?int $quality = null;

    /**
     * @deprecated 3.0.0 Set Restruct\Silverstripe\WebpImages\WebpImages.enabled in config instead
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * @deprecated 3.0.0 Use Restruct\Silverstripe\WebpImages\WebpImages::singleton()->isEnabled()
     */
    public function getEnabled(): bool
    {
        return \Restruct\Silverstripe\WebpImages\WebpImages::singleton()->isEnabled();
    }
}

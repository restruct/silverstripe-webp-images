<?php

declare(strict_types=1);

namespace WeDevelop\WebpImages;

use SilverStripe\Core\Injector\Injectable;

final class WebpGenerator
{
    use Injectable;

    private ?bool $enabledForNextGenerate = null;

    public bool $enabled = true;

    public int $quality = 80;

    public function generate(string $url, string $mimeType): string
    {
        if (!$this->getEnabled()) {
            return $url;
        }

        if (!in_array($mimeType, ['image/png', 'image/jpeg', 'image/jpg'], true)) {
            return $url;
        }

        if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') === false) {
            return $url;
        }

        # Never feed a URL that already is a WebP back into GD (issue #16): the mime check above
        # relies on the store's detection, which need not agree with the URL's extension.
        if (str_ends_with(strtolower(strtok($url, '?')), '.webp')) {
            return $url;
        }

        $originalFilename = PUBLIC_PATH . $url;

        # imagecreatefrom*() raises an E_WARNING on a missing or unreadable file, which the
        # Silverstripe error handler turns into an exception in dev mode; serve the original instead.
        if (!is_file($originalFilename) || !is_readable($originalFilename)) {
            return $url;
        }

        $webpUrl = "{$url}.webp";
        $webpFilename = PUBLIC_PATH . $webpUrl;

        if (file_exists($webpFilename)) {
            return $webpUrl;
        }

        $image = match($mimeType) {
            'image/png' => imagecreatefrompng($originalFilename),
            'image/jpeg', 'image/jpg' => imagecreatefromjpeg($originalFilename),
        };

        if ($image === false) {
            return $url;
        }

        switch ($mimeType) {
            case 'image/png':
                imagepalettetotruecolor($image);
                imagealphablending($image, true);
                imagesavealpha($image, true);
                break;
        }

        $webpImage = imagewebp($image, $webpFilename, $this->quality);
        # imagedestroy() has been a no-op since PHP 8.0 and is deprecated as of PHP 8.5 (allowed by SS6)
        // imagedestroy($image);

        return $webpImage === false ? $url : $webpUrl;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getEnabled(): bool
    {
        if (is_bool($this->getEnabledForNextGenerate())) {
            $state = $this->getEnabledForNextGenerate();

            $this->enabledForNextGenerate = null;

            return $state;
        }

        return $this->enabled;
    }

    public function setEnabledForNextGenerate(bool $enabled): void
    {
        $this->enabledForNextGenerate = $enabled;
    }

    public function getEnabledForNextGenerate(): ?bool
    {
        return $this->enabledForNextGenerate;
    }
}

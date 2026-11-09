# The 2.x implementation (kept for reference)

Version 3.0 replaced the whole 2.x mechanism with core's `Convert()` image variants (see the README,
"Upgrading from 2.x"). The 2.x code is kept here, as it was on the last 2.x-compatible commit
(`bd3267a`, branch `ss6-compat`), so anyone comparing behaviour or reviving a piece of it can see
what it did without digging through history. It is not loaded by the module.

What it did, in short:

- `Storage\DBFileExtension::updateURL()` handed every public jpeg/png URL (originals and variants) to
  `WebpGenerator::generate()`.
- `WebpGenerator::generate()` checked the *page* request's `Accept` header for `image/webp`, then
  wrote `<file>.webp` next to the image with raw GD (`imagecreatefrom*()` + `imagewebp()`) and
  returned that URL. The file lived outside the asset store.
- `Flysystem\PublicAssetAdapter` / `ProtectedAssetAdapter` replaced core's adapters through Injector
  so deleting a file also deleted its `.webp` sibling.
- `Asset\ImageExtension` added `$Image.EnableWebP` / `$Image.DisableWebP` as one-shot global flags.

Why it was replaced: the HTML depended on the request `Accept` header without a `Vary: Accept`
header (so page caches served one browser's URLs to another), Safari and Firefox do not list
`image/webp` on page requests (so they got originals), the sidecars were invisible to the asset
store (publish/unpublish, remote storage, `findVariants()`, flush), and the GD encode ignored the
configured image backend. Leftover sidecar files can be removed with the
`webp-images-purge-sidecars` task.

## `src/WebpGenerator.php`

```php
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

```

## `src/Storage/DBFileExtension.php`

```php
<?php

declare(strict_types=1);

namespace WeDevelop\WebpImages\Storage;

use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Core\Extension;
use WeDevelop\WebpImages\WebpGenerator;

/**
 * @property DBFile $owner;
 */
class DBFileExtension extends Extension
{
    public function updateURL(&$url): void
    {
        if (!$this->getOwner()->getIsImage() || $this->getOwner()->getVisibility() !== AssetStore::VISIBILITY_PUBLIC) {
            return;
        }

        $url = WebpGenerator::singleton()->generate($url, $this->getOwner()->getMimeType());
    }
}

```

## `src/Asset/ImageExtension.php`

```php
<?php

namespace WeDevelop\WebpImages\Asset;

use SilverStripe\Core\Extension;
use WeDevelop\WebpImages\WebpGenerator;

class ImageExtension extends Extension
{
    public function EnableWebP()
    {
        WebpGenerator::singleton()->setEnabledForNextGenerate(true);

        return $this->getOwner();
    }

    public function DisableWebP()
    {
        WebpGenerator::singleton()->setEnabledForNextGenerate(false);

        return $this->getOwner();
    }
}

```

## `src/Flysystem/AssetAdapterTrait.php`

```php
<?php

declare(strict_types=1);

namespace WeDevelop\WebpImages\Flysystem;

// use League\Flysystem\PathPrefixer;

trait AssetAdapterTrait
{
    public function delete($path): void
    {
        // A PathPrefixer with an empty prefix returns $path unchanged, so the unlink below was
        // relative to the process cwd instead of the assets root and never removed the .webp sibling.
        // $location = new PathPrefixer('');
        // $location = $location->prefixPath($path);
        //
        // @unlink($location . '.webp');

        # Let the adapter resolve the sibling against its own (private) root prefixer;
        # LocalFilesystemAdapter::delete() is a no-op when the file does not exist.
        parent::delete($path . '.webp');

        parent::delete($path);
    }
}

```

## `src/Flysystem/PublicAssetAdapter.php`

```php
<?php

declare(strict_types=1);

namespace WeDevelop\WebpImages\Flysystem;

class PublicAssetAdapter extends \SilverStripe\Assets\Flysystem\PublicAssetAdapter
{
    use AssetAdapterTrait;
}

```

## `src/Flysystem/ProtectedAssetAdapter.php`

```php
<?php

declare(strict_types=1);

namespace WeDevelop\WebpImages\Flysystem;

class ProtectedAssetAdapter extends \SilverStripe\Assets\Flysystem\ProtectedAssetAdapter
{
    use AssetAdapterTrait;
}

```

## `_config/config.yml`

```yaml
---
Name: webpimages
---
SilverStripe\Core\Injector\Injector:
  SilverStripe\Assets\Flysystem\PublicAdapter:
    class: WeDevelop\WebpImages\Flysystem\PublicAssetAdapter
  SilverStripe\Assets\Flysystem\ProtectedAdapter:
    class: WeDevelop\WebpImages\Flysystem\ProtectedAssetAdapter

SilverStripe\Assets\Storage\DBFile:
  extensions:
    - WeDevelop\WebpImages\Storage\DBFileExtension

SilverStripe\Assets\File:
  allowed_extensions:
    - webp

Silverstripe\Assets\Image:
  extensions:
    - WeDevelop\WebpImages\Asset\ImageExtension

```


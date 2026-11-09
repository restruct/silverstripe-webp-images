# Changelog

## 3.1.0 (unreleased)

### Added

- restruct/silverstripe-signed-asset-urls 1.4+: `AutoURL`, `SignedURL` and `MaskedURL` on a
  resized jpeg/png sign its WebP variant, as automatic mode serves it, instead of the unconverted
  variant (`WebpUrlExtension::updateSignedURLFile()`). Same rules as the URL rewrite
  (`shouldRewrite()`). signed-asset-urls is a suggestion in `composer.json`, not a dependency.
- `WebpImages::getConvertedFile()`: the converted variant as a file, memoised per request (the
  memo is cleared by `reset()` with the URL memo).

## 3.0.0 (2026-11-09)

First release as `restruct/silverstripe-webp-images`, the maintained successor of
`wedevelopnl/silverstripe-webp-images` 2.x. Breaking: see the README, "Upgrading from
wedevelopnl/silverstripe-webp-images 2.x".

### Changed

- WebP files are now core image variants made by `FileConverterManager` (Silverstripe 5.3+ /
  assets 2.3+), encoded by the configured image backend, instead of `photo.jpg.webp` files written
  with raw GD next to the image. The asset store deletes, publishes, unpublishes and protects them
  with the image, and remote storage adapters work.
- Every visitor gets WebP; the page request's `Accept` header is no longer checked. The HTML is
  the same for every browser, so page, static and CDN caches are safe.
- Only resized and cropped images are converted by default; originals (and download links) stay
  the uploaded file (`convert_originals`).
- Draft (protected) images are converted too.
- No conversion during CMS requests by default (`convert_in_cms`).
- `$Image.EnableWebP` / `$Image.DisableWebP` are per image object and carry to variants made from
  it, so `$Image.ScaleWidth(400).DisableWebP` works in a template (2.x known issue).
- Namespace `Restruct\Silverstripe\WebpImages\`; configuration on
  `Restruct\Silverstripe\WebpImages\WebpImages` (`enabled`, `quality`, and new options).
- Requires Silverstripe 5.3+ or 6 (`silverstripe/framework ^5.3 || ^6`,
  `silverstripe/assets ^2.3 || ^3`), PHP 8.1+.
- Conflicts with `wedevelopnl/silverstripe-webp-images`, which hooks the same URL.

### Added

- `$Image.Picture`: `<picture>` with WebP and (opt-in) AVIF `<source>`s and the original format as
  `<img>` fallback, from an overridable template.
- AVIF as an allowed image type (`File.allowed_extensions`, `app_categories`,
  `DBFile.supported_images`, `HTTP.MimeTypes`).
- Options `rewrite_urls`, `convert_originals`, `convert_in_cms`, `convert_extensions`,
  `avif_quality` (default 60, below WebP's 80: at the image backend's own default of 90 an AVIF
  came out larger than the JPEG it replaces), `picture_formats`.
- Task `webp-images-purge-sidecars`: removes the `.jpg.webp` / `.png.webp` files 2.x left in public
  assets (dry run unless confirmed).
- Unit tests (Silverstripe 5 and 6), Playwright browser tests, CI with PHPStan.

### Fixed

- Silverstripe 5 with Imagick: `$Image.Picture` with AVIF on gave an HTTP 500 ("Can not process
  empty Imagick object"). Core's converter (assets 2) destroys the Imagick image it shares with the
  file it converts; each conversion now gets its own copy of the file.

### Deprecated

- `WeDevelop\WebpImages\WebpGenerator`: its Injector properties `enabled` and `quality` and
  `setEnabled()` are still honoured, with a deprecation notice. Removed in 4.0.

### Removed

- The GD encoder (`WebpGenerator::generate()`), the replacement public/protected asset adapters,
  `Asset\ImageExtension` and `Storage\DBFileExtension`. Kept for reference in `docs/legacy-2x.md`.
- The `webp` entry in `File.allowed_extensions` (core already allows it).
- The Docker, Makefile and php-cs-fixer development setup, replaced by the CI workflows.

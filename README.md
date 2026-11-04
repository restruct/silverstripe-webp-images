# WebP images for Silverstripe

Your templates keep saying `$Image.ScaleWidth(800)`, and visitors get a WebP file that is typically a
quarter to a third smaller than the JPEG or PNG it replaces. No template changes, no server configuration, and
the uploaded originals stay exactly as they were. Where you want AVIF as well, or need a fallback
for very old browsers, `$Image.ScaleWidth(800).Picture` renders a `<picture>` element with AVIF and
WebP sources and the original format as fallback.

*Maintained by [Restruct](https://github.com/restruct). If this module saves you time, you can
[support ongoing maintenance](https://github.com/sponsors/restruct).*

This is the maintained successor of
[wedevelopnl/silverstripe-webp-images](https://github.com/wedevelopnl/silverstripe-webp-images);
see [Upgrading from wedevelopnl/silverstripe-webp-images 2.x](#upgrading-from-wedevelopnlsilverstripe-webp-images-2x).

## Out of the box, every resized image is still a JPEG or PNG

Silverstripe resizes and crops images in the format they were uploaded in. Since Silverstripe 5.3
core can convert an image to another format (`$Image.Convert('webp')`), but only where a template
asks for it, image by image. A site full of `$Image.Fill(400, 300)` keeps serving JPEGs.

Serving WebP by hand also tends to go wrong in ways that are hard to see:

- **Choosing the format per visitor breaks page caches.** If the HTML depends on the browser's
  `Accept` header, a static or full-page cache, or a CDN, hands one visitor's URLs to the next.
- **Safari and Firefox do not announce WebP support on page requests**, only on image requests, so
  code that checks the page request's `Accept` header serves them the JPEG anyway.
- **Files written next to the asset store are invisible to it**: they survive the image being
  deleted, are not moved when it is unpublished, and do not exist on remote (S3) storage.

## WebP as a regular image variant

The module hands every resized JPEG and PNG to core's own converter and serves the result:

- **Automatic, for every visitor alike.** `$Image.ScaleWidth(400)` renders
  `photo__ScaleWidthWzQwMF0_ExtRewriteWyJqcGciLCJ3ZWJwIl0.webp`. The HTML is the same for everyone,
  so every kind of cache is safe. The tag keeps the width and height of the resized image.
- **Stored by the asset store itself.** The WebP is a core variant of the image (like the resized
  JPEG it is made from), so it is deleted, published, unpublished and access-checked together with
  the image, works with remote storage adapters, and is encoded by the image backend you configured
  (GD or Imagick).
- **Originals untouched.** An image used at its original size, and every download link, stays the
  uploaded file. Only resized and cropped versions are converted (configurable).
- **Cheap on repeat.** A WebP variant is made once, the first time it is needed, and the URL is
  remembered for the rest of the request.
- **The CMS stays fast.** No conversion during CMS requests by default, so opening a folder of
  hundreds of images in the Files section does not encode hundreds of WebP files on the spot.
- **GIFs are left alone**, because they are usually animated.
- **`<picture>` with AVIF, when you want it.** `$Image.ScaleWidth(400).Picture` renders AVIF (opt-in)
  and WebP `<source>`s with the JPEG/PNG as `<img>` fallback. AVIF is only ever served this way:
  a plain `<img>` has no fallback for a browser without AVIF support.
- **Per image control in templates.** `$Image.ScaleWidth(400).DisableWebP` keeps one image in its
  own format; `$Image.EnableWebP` converts one the config would skip.

## Requirements

- Silverstripe 5.3+ or 6 (`silverstripe/framework ^5.3 || ^6`, `silverstripe/assets ^2.3 || ^3`;
  `Convert()` arrived in assets 2.3 / CMS 5.3)
- PHP 8.1+ (Silverstripe 6 itself needs 8.3+)
- An image backend that can write WebP: GD with WebP support (`gd_info()['WebP Support']`), or
  Imagick with WEBP in `Imagick::queryFormats()`
- For AVIF in `<picture>`: GD with `imageavif()` (PHP 8.1+ built with libavif) or Imagick with AVIF.
  Without it the AVIF source is left out and a warning is logged.
- On Silverstripe 6 the `.ss` template engine (`silverstripe/template-engine`, part of
  `silverstripe/recipe-core`) for `$Image.Picture`

| Branch | Module version | Silverstripe | PHP |
|--------|----------------|--------------|-----|
| `main` | 3.x | 5.3+, 6 | 8.1+ |
| (upstream) | wedevelopnl 2.x | 5 | 8.1+ |

`composer.json` is the source of truth for the supported versions; this table is a convenience copy.

## Installation

```sh
composer require restruct/silverstripe-webp-images
```

Then run `dev/build flush=1` (Silverstripe 5) or `sake db:build --flush` (Silverstripe 6). The
flush regenerates `assets/.htaccess`, which must allow the `avif` extension the module adds.

## Configuration

Every option, with its default:

```yaml
Restruct\Silverstripe\WebpImages\WebpImages:
  # master switch; false converts nothing (except images a template marks with EnableWebP)
  enabled: true
  # automatic mode: resized jpeg/png URLs become their WebP variant's URL.
  # false = WebP only through $Image.Picture
  rewrite_urls: true
  # also convert images used at their original size (their URL is also the download link)
  convert_originals: false
  # also convert during CMS (admin) requests
  convert_in_cms: false
  # source formats that are converted (gif is left out on purpose: animation)
  convert_extensions: [jpg, jpeg, png]
  # WebP quality 1-100; null = the image backend's own quality setting
  quality: 80
  # AVIF quality 1-100; null = the image backend's own quality setting
  avif_quality: 60
  # <source> formats of $Image.Picture; the order is fixed (avif first, then webp)
  picture_formats:
    avif: false
    webp: true
```

`picture_formats` is a map rather than a list because Silverstripe config merges lists: a project
could add to a list but never take anything out. To add AVIF:

```yaml
Restruct\Silverstripe\WebpImages\WebpImages:
  picture_formats:
    avif: true
```

Quality is not part of a variant's file name, so a change only applies to WebP files that do not
exist yet. To regenerate, remove the existing variants (for instance with a variant-cleanup task).

### Browsers without WebP

WebP works in every current browser (about 97% of visitors per caniuse at the time of writing).
The rest (Internet Explorer, Safari on macOS before 11 or iOS before 14) get a broken image in
automatic mode. If a site must serve them, turn automatic mode off and use `<picture>`, which falls
back to the original format:

```yaml
Restruct\Silverstripe\WebpImages\WebpImages:
  rewrite_urls: false
```

```ss
$Image.ScaleWidth(800).Picture
```

## Usage

Nothing, in automatic mode: every `$Image.ScaleWidth()`, `.Fill()`, `.FitMax()`, `.CropRegion()`
and so on renders a WebP. In templates:

| Template | Renders |
|----------|---------|
| `$Image.ScaleWidth(400)` | `<img src="...webp" width="400" ...>` |
| `$Image` | the original, unconverted |
| `$Image.ScaleWidth(400).DisableWebP` | this one stays JPEG/PNG |
| `$Image.DisableWebP.Fill(400, 300)` | the flag carries to variants made afterwards |
| `$Image.EnableWebP` | the original, converted (also overrides `enabled`, `convert_in_cms`) |
| `$Image.ScaleWidth(400).Picture` | `<picture>` with `<source>`s and the JPEG/PNG `<img>` |
| `$Image.Picture` | the same, at the original size |

`$Image.ScaleWidth(400).Picture` renders:

```html
<picture><source type="image/avif" srcset="/assets/photo__ScaleWidthWzQwMF0_ExtRewriteWyJqcGciLCJhdmlmIl0.avif"><source type="image/webp" srcset="/assets/photo__ScaleWidthWzQwMF0_ExtRewriteWyJqcGciLCJ3ZWJwIl0.webp"><img width="400" height="300" alt="photo" src="/assets/photo__ScaleWidthWzQwMF0.jpg" loading="lazy" /></picture>
```

(the AVIF source only with `avif: true`). The markup comes from the template
`Restruct/Silverstripe/WebpImages/Picture.ss`; copy it to
`themes/<yourtheme>/templates/Restruct/Silverstripe/WebpImages/Picture.ss` to change it. It
receives `$Sources` (each with `$Format`, `$Type`, `$SrcSet`) and `$Img` (the fallback tag).

In PHP the same is available on the service:

```php
use Restruct\Silverstripe\WebpImages\WebpImages;

$webp = WebpImages::singleton()->convert($image->ScaleWidth(400), 'webp'); # DBFile or null
```

### What counts as a CMS request

A request whose URL starts with the admin route (`admin/` by default, read from
`AdminRootController`), which covers every CMS screen and, on Silverstripe 5, `admin/graphql`.
To change the decision, add an extension to `WebpImages` with
`updateIsCmsRequest(bool &$isCms, ?HTTPRequest $request)`.

## Upgrading from wedevelopnl/silverstripe-webp-images 2.x

```sh
composer remove wedevelopnl/silverstripe-webp-images
composer require restruct/silverstripe-webp-images
```

The two packages conflict on purpose (both would rewrite image URLs), so Composer refuses to install
them together. Then flush, and when the page caches have been cleared, remove the old WebP files:

```sh
# Silverstripe 5
vendor/bin/sake dev/tasks/webp-images-purge-sidecars            # dry run: lists them
vendor/bin/sake dev/tasks/webp-images-purge-sidecars confirm=1  # deletes them
# Silverstripe 6
vendor/bin/sake tasks:webp-images-purge-sidecars -v
vendor/bin/sake tasks:webp-images-purge-sidecars --confirm
```

What changed:

- **WebP files are core variants now**, not `photo.jpg.webp` files next to the image. Existing HTML
  that points at a 2.x file keeps working until the purge task removes it, so run the task after
  clearing page, static and CDN caches. The first request for each image after the upgrade writes
  its new WebP variant (a one-time cost, like a flush of resized images).
- **No more `Accept` header check.** Every visitor gets WebP (2.x served it only to browsers that
  announced it on the page request, which in practice meant Chromium-based browsers only).
- **Originals are no longer converted**, only resized versions. `convert_originals: true` restores
  that.
- **Draft (protected) images are converted too**; 2.x skipped them.
- **`$Image.DisableWebP` works as written.** In 2.x it was one global "next URL" flag that the
  template engine used up before the tag was rendered; the README advised
  `<img $Image.DisableWebP.AttributesHTML />`, which still works but is no longer needed.
- **Configuration moved.** The 2.x Injector properties still work for this major and log a
  deprecation notice:

  ```yaml
  # 2.x, still honoured in 3.x, removed in 4.0
  SilverStripe\Core\Injector\Injector:
    WeDevelop\WebpImages\WebpGenerator:
      properties:
        enabled: true
        quality: 80
  ```

  as does `WebpGenerator::singleton()->setEnabled(false)`. Replace them with:

  ```yaml
  Restruct\Silverstripe\WebpImages\WebpImages:
    enabled: true
    quality: 80
  ```

- **Removed:** the replacement public/protected asset adapters (core deletes variants itself), the
  GD encoder, and `WebpGenerator::generate()`. The 2.x code is kept for reference in
  [docs/legacy-2x.md](docs/legacy-2x.md).
- **Added:** `avif` as an allowed image type (needed for the AVIF `<source>`). CMS users can now also
  upload `.avif` files.

## Alternatives: negotiation on the web server

Another way to serve WebP is to keep the HTML pointing at `photo.jpg` and let the web server answer
with a `photo.jpg.webp` file when the browser's image request accepts WebP. This module does not do
that (the files would live outside the asset store), but if you choose that route, these are the
rules that do it. They are documentation only; the module never writes server configuration.

nginx:

```nginx
map $http_accept $webp_suffix { default ""; "~*image/webp" ".webp"; }
location ~* ^/assets/.+\.(jpe?g|png)$ {
    add_header Vary Accept;
    try_files $uri$webp_suffix $uri =404;
}
```

Apache, in the virtual host or the webroot `.htaccess` (not in the generated `assets/.htaccess`,
which Silverstripe rewrites on flush):

```apache
RewriteCond %{HTTP_ACCEPT} image/webp
RewriteCond %{REQUEST_FILENAME}.webp -f
RewriteRule ^assets/(.+\.(?:jpe?g|png))$ assets/$1.webp [T=image/webp,E=WEBP:1,L]
Header append Vary Accept env=REDIRECT_WEBP
```

`Vary: Accept` on the images is required, and a CDN in front must include `Accept` in its cache key.
Safari does not list AVIF in its image `Accept` header, so this route cannot serve AVIF to Safari.

Serving AVIF from this module needs the server to know its mime type: nginx's `mime.types` has
`image/avif` since 2021 and Apache httpd since 2024; older distribution builds may lack it
(`types { image/avif avif; }` for nginx, `AddType image/avif .avif` for Apache).

## Running the tests

The suite needs a host project that requires the module through a path repository with
`"symlink": true` (a dist install has no `tests/`), plus `silverstripe/recipe-testing`. Copy
`phpunit.xml.dist` into the host, then:

```sh
vendor/bin/phpunit vendor/restruct/silverstripe-webp-images/tests flush=1   # Silverstripe 5
SS_PHPUNIT_FLUSH=1 vendor/bin/phpunit --testsuite webpimages              # Silverstripe 6
```

Browser tests (Playwright, Chromium) live in `tests/browser/` and run through the shared runner;
CI runs both on every pull request (`.github/workflows/`).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Credits

- [WeDevelop](https://www.wedevelop.nl/) wrote the original module, wedevelopnl/silverstripe-webp-images.
- [nomidi/silverstripe-webp-image](https://github.com/nomidi/silverstripe-webp-image) and
  [LoveDuckie/silverstripe-webp-image](https://github.com/LoveDuckie/silverstripe-webp-image) for the
  server-negotiation approach and the nginx `map` pattern described under Alternatives.

## License

MIT, see [LICENSE](LICENSE). Copyright (c) 2022 WeDevelop, (c) 2026 Restruct.

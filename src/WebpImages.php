<?php

declare(strict_types=1);

namespace Restruct\Silverstripe\WebpImages;

use Psr\Log\LoggerInterface;
use SilverStripe\Admin\AdminRootController;
use SilverStripe\Assets\Conversion\FileConverterException;
use SilverStripe\Assets\Conversion\FileConverterManager;
use SilverStripe\Assets\File;
use SilverStripe\Assets\FilenameParsing\AbstractFileIDHelper;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\Deprecation;
use Throwable;
use WeakMap;
use WeDevelop\WebpImages\WebpGenerator;

/**
 * Policy and delivery for WebP (and AVIF) image variants.
 *
 * The conversion itself is core's: FileConverterManager -> InterventionImageFileConverter ->
 * manipulateExtension(), which stores the result as a real variant of the image (an ExtRewrite
 * sub-variant). So the asset store deletes, publishes, unpublishes and protects it together with
 * the image, with the configured image backend (GD or Imagick) doing the encoding. This class only
 * decides WHICH images are converted, to WHAT, and remembers the answer for the request.
 *
 * One instance per request (Injector singleton): the per-request memo and the per-instance
 * Enable/Disable flags live on it.
 */
class WebpImages
{
    use Configurable;
    use Extensible;
    use Injectable;

    /**
     * Master switch. When false nothing is converted, except images a template explicitly asked
     * for with EnableWebP.
     *
     * @config
     */
    private static bool $enabled = true;

    /**
     * Automatic mode: every resized jpeg/png variant's URL becomes its WebP variant's URL, so
     * `$Image.ScaleWidth(400)` renders a .webp without any template change. Turn this off to serve
     * WebP only through `$Image.Picture` (which keeps a jpeg/png fallback for old browsers).
     *
     * @config
     */
    private static bool $rewrite_urls = true;

    /**
     * Also convert originals (an image used without any resize/crop). Off by default: an
     * original's URL is also its download link, and a download should stay the uploaded file.
     *
     * @config
     */
    private static bool $convert_originals = false;

    /**
     * Also convert during CMS (admin) requests. Off by default: asset-admin requests thumbnails
     * for whole folders at once, and each missing WebP would be encoded synchronously in that
     * request (core's existingOnly() thumbnail flag does not carry over to the conversion).
     *
     * @config
     */
    private static bool $convert_in_cms = false;

    /**
     * Source file extensions that are converted. GIF is left out on purpose: it is usually
     * animated, and an animated GIF would come out as a single WebP frame.
     *
     * @config
     * @var string[]
     */
    private static array $convert_extensions = ['jpg', 'jpeg', 'png'];

    /**
     * WebP quality (1-100). Null leaves it to the image backend's own quality setting.
     * Quality is not part of a variant's name, so a change only applies to WebP variants that do
     * not exist yet (remove existing ones, or re-upload, to regenerate).
     *
     * @config
     */
    private static ?int $quality = 80;

    /**
     * AVIF quality (1-100). Null leaves it to the image backend's own quality setting.
     *
     * @config
     */
    private static ?int $avif_quality = null;

    /**
     * The <source> formats `$Image.Picture` renders, as format => on/off. AVIF is opt-in:
     * `avif: true`. AVIF is only ever served through <picture>, never in automatic mode, because
     * a plain <img> has no fallback for browsers without AVIF support.
     *
     * A map rather than a list on purpose: Silverstripe config MERGES lists, so a project could
     * add a format to a list but never remove or reorder one. The order of the <source>s is fixed
     * by PICTURE_FORMAT_ORDER (best compression first, the browser takes the first it supports);
     * any other format a converter supports follows in config order.
     *
     * @config
     * @var array<string, bool>
     */
    private static array $picture_formats = [
        'avif' => false,
        'webp' => true,
    ];

    /**
     * Preference order of <picture> sources
     */
    public const PICTURE_FORMAT_ORDER = ['avif', 'webp'];

    /**
     * Converted URLs for this request, keyed by format and source URL ('' = could not convert).
     * Saves the asset-store lookups when the same variant URL is asked for more than once.
     *
     * @var array<string, string>
     */
    protected array $urlMemo = [];

    /**
     * EnableWebP / DisableWebP flags, per file object (the Image record, or one of its variants).
     * A WeakMap, so a flag disappears with the object it was set on.
     *
     * @var WeakMap<object, bool>
     */
    protected WeakMap $flags;

    /**
     * Memoised result of isCmsRequest(), per request object
     */
    protected ?int $cmsRequestId = null;

    protected bool $cmsRequest = false;

    public function __construct()
    {
        $this->flags = new WeakMap();
    }

    /**
     * Force conversion on (true), off (false), or back to the configured policy (null) for one
     * file object and the variants derived from it afterwards.
     */
    public function setFlag(object $file, ?bool $enabled): void
    {
        if ($enabled === null) {
            unset($this->flags[$file]);
            return;
        }
        $this->flags[$file] = $enabled;
    }

    /**
     * The EnableWebP / DisableWebP flag that applies to a file object: its own, or the nearest one
     * up its chain of originals. A variant's failover is the object it was made from
     * (ImageManipulation::manipulate() -> setOriginal()), so `$Image.DisableWebP.ScaleWidth(400)`
     * finds the flag on $Image, and `$Image.ScaleWidth(400).DisableWebP` on the variant itself.
     */
    public function getFlag(object $file): ?bool
    {
        $node = $file;
        # bounded walk: a chain of manipulations is a handful deep, a loop must not hang a request
        for ($depth = 0; $node && $depth < 16; $depth++) {
            if (isset($this->flags[$node])) {
                return $this->flags[$node];
            }
            $node = method_exists($node, 'getFailover') ? $node->getFailover() : null;
        }
        return null;
    }

    /**
     * Should this stored file's URL be swapped for its WebP variant (automatic mode)?
     */
    public function shouldRewrite(DBFile $file): bool
    {
        if (!$this->isConvertible($file)) {
            return false;
        }

        # an explicit EnableWebP / DisableWebP beats every setting below
        $flag = $this->getFlag($file);
        if ($flag !== null) {
            return $flag;
        }

        if (!$this->isEnabled() || !static::config()->get('rewrite_urls')) {
            return false;
        }
        if (!$file->getVariant() && !static::config()->get('convert_originals')) {
            return false;
        }
        if (!static::config()->get('convert_in_cms') && $this->isCmsRequest()) {
            return false;
        }
        return true;
    }

    /**
     * Is this a jpeg/png (per convert_extensions) that has not been converted already?
     *
     * The re-entry guard is the variant name, not the extension: an ExtRewrite variant keeps the
     * ORIGINAL Filename (foo.jpg), so getExtension() still says jpg on a webp or avif variant, and
     * an extension check would convert a Convert('avif') result (or a
     * Convert('webp')->ScaleWidth() chain) to WebP a second time.
     */
    public function isConvertible(AssetContainer $file): bool
    {
        if (!$file->exists()) {
            return false;
        }
        $variant = (string) $file->getVariant();
        if ($variant !== '' && str_contains($variant, AbstractFileIDHelper::EXTENSION_REWRITE_VARIANT)) {
            return false;
        }
        $extension = strtolower((string) pathinfo((string) $file->getFilename(), PATHINFO_EXTENSION));
        $allowed = array_map('strtolower', (array) static::config()->get('convert_extensions'));
        return in_array($extension, $allowed, true);
    }

    /**
     * The URL of $file's variant in $format, or null when it cannot be converted.
     * Memoised per request by the incoming URL (which already differs per file, hash, variant and
     * public/protected location).
     */
    public function getConvertedURL(DBFile $file, string $sourceURL, string $format = 'webp'): ?string
    {
        $key = $format . '|' . $sourceURL;
        if (!array_key_exists($key, $this->urlMemo)) {
            $converted = $this->convert($file, $format);
            # getSourceURL, not getURL: no second pass through the updateURL hook. No grant needed:
            # grants are per original file, and the caller's getURL() already handled that.
            $this->urlMemo[$key] = $converted ? (string) $converted->getSourceURL(false) : '';
        }
        return $this->urlMemo[$key] !== '' ? $this->urlMemo[$key] : null;
    }

    /**
     * Convert a file to $format through core's FileConverterManager, with this module's quality.
     * Returns null (and logs) when no converter can do it, eg AVIF without GD imageavif support.
     */
    public function convert(DBFile|File $file, string $format): ?DBFile
    {
        if (!$file->exists()) {
            return null;
        }
        $options = [];
        $quality = $this->getQuality($format);
        if ($quality !== null) {
            # the converter rejects a non-int quality outright
            $options['quality'] = $quality;
        }
        try {
            return Injector::inst()->get(FileConverterManager::class)->convert($file, $format, $options);
        } catch (FileConverterException $e) {
            # same handling as core's ImageManipulation::Convert(): log, serve the source instead
            Injector::inst()->get(LoggerInterface::class)->warning(
                sprintf('webp-images: could not convert %s to %s: %s', $file->getFilename(), $format, $e->getMessage())
            );
            return null;
        }
    }

    /**
     * Quality for a format: `quality` for webp, `avif_quality` for avif, null = backend default.
     * The deprecated 2.x Injector property (WeDevelop\WebpImages\WebpGenerator.quality) still
     * applies to webp when it was set.
     */
    public function getQuality(string $format): ?int
    {
        $format = strtolower($format);
        if ($format === 'avif') {
            $quality = static::config()->get('avif_quality');
        } else {
            $legacy = $this->getLegacySetting('quality');
            $quality = $legacy ?? static::config()->get('quality');
        }
        return $quality === null ? null : max(1, min(100, (int) $quality));
    }

    /**
     * Master switch, honouring the deprecated 2.x setting
     * (WeDevelop\WebpImages\WebpGenerator.enabled, YAML or setEnabled()) when that was used.
     */
    public function isEnabled(): bool
    {
        $legacy = $this->getLegacySetting('enabled');
        if ($legacy !== null) {
            return (bool) $legacy;
        }
        return (bool) static::config()->get('enabled');
    }

    /**
     * The enabled <picture> source formats, lowercased, in preference order (PICTURE_FORMAT_ORDER
     * first). A plain list in config (`[avif, webp]`) is read as "these are on".
     *
     * @return string[]
     */
    public function getPictureFormats(): array
    {
        $enabled = [];
        foreach ((array) static::config()->get('picture_formats') as $key => $value) {
            if (is_int($key)) {
                # list entry: the value is the format name
                $format = strtolower(trim((string) $value));
                $on = $format !== '';
            } else {
                $format = strtolower(trim($key));
                $on = (bool) $value;
            }
            if ($format !== '') {
                $enabled[$format] = $on;
            }
        }
        $enabled = array_keys(array_filter($enabled));
        $ordered = array_values(array_intersect(self::PICTURE_FORMAT_ORDER, $enabled));
        return array_merge($ordered, array_values(array_diff($enabled, self::PICTURE_FORMAT_ORDER)));
    }

    /**
     * Is the current request a CMS (admin) request? Decided on the request URL against the admin
     * route, so it covers every admin endpoint that renders thumbnails (LeftAndMain screens, and
     * admin/graphql on Silverstripe 5), not only LeftAndMain controllers. Memoised per request.
     * Extension hook: updateIsCmsRequest(bool &$isCms, ?HTTPRequest $request).
     */
    public function isCmsRequest(): bool
    {
        $request = $this->getCurrentRequest();
        $id = $request ? spl_object_id($request) : 0;
        if ($this->cmsRequestId === $id) {
            return $this->cmsRequest;
        }

        $isCms = false;
        if ($request && class_exists(AdminRootController::class)) {
            $adminRoute = trim((string) AdminRootController::get_admin_route(), '/');
            $url = trim((string) $request->getURL(), '/');
            $isCms = $adminRoute !== '' && ($url === $adminRoute || str_starts_with($url, $adminRoute . '/'));
        }
        $this->extend('updateIsCmsRequest', $isCms, $request);

        $this->cmsRequestId = $id;
        $this->cmsRequest = $isCms;
        return $isCms;
    }

    /**
     * Forget memoised URLs and the CMS-request answer (eg after deleting variants mid-request)
     */
    public function reset(): void
    {
        $this->urlMemo = [];
        $this->cmsRequestId = null;
        $this->cmsRequest = false;
    }

    /**
     * The request Director is handling, if any. Director::handleRequest() registers it with the
     * Injector; reading it from there avoids Controller::curr(), which raises a warning on
     * Silverstripe 5 when no controller is on the stack (CLI, queued jobs).
     */
    protected function getCurrentRequest(): ?HTTPRequest
    {
        $injector = Injector::inst();
        if (!$injector->has(HTTPRequest::class)) {
            return null;
        }
        try {
            $request = $injector->get(HTTPRequest::class);
        } catch (Throwable) {
            return null;
        }
        return $request instanceof HTTPRequest ? $request : null;
    }

    /**
     * A 2.x setting (enabled, quality) when a project still sets it on the deprecated
     * WeDevelop\WebpImages\WebpGenerator service, null when it does not.
     */
    protected function getLegacySetting(string $name): mixed
    {
        if (!class_exists(WebpGenerator::class, false) && !Injector::inst()->has(WebpGenerator::class)) {
            return null;
        }
        $value = WebpGenerator::singleton()->$name ?? null;
        if ($value !== null) {
            Deprecation::notice(
                '3.0.0',
                'Set ' . static::class . '.' . $name . ' in YAML config instead of WeDevelop\WebpImages\WebpGenerator.' . $name,
                Deprecation::SCOPE_GLOBAL
            );
        }
        return $value;
    }
}

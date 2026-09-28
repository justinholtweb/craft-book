<?php

namespace justinholtweb\book\services;

use Craft;
use craft\base\Component;
use craft\helpers\UrlHelper;
use justinholtweb\book\models\DocumentOptions;
use justinholtweb\book\models\Source;
use justinholtweb\book\Plugin;

/**
 * Decides what URL a viewer is handed, and whether Book itself serves the bytes.
 *
 * Two jobs, and they are the same job from different ends: a private asset has no URL, so Book
 * makes one; and a public asset sometimes *should not* be reachable by its volume path, so Book
 * makes one for that too.
 */
class Delivery extends Component
{
    /**
     * The query parameter a signature travels in.
     *
     * Not `p` — that is Craft's `pathParam`, so `?p=…` is read as the requested path and the
     * route 404s before any controller runs. Not `token` either, which Craft claims for preview
     * tokens. `bookref` is nobody's.
     */
    public const PARAM = 'bookref';

    public const DISPOSITION_INLINE = 'inline';
    public const DISPOSITION_ATTACHMENT = 'attachment';

    /**
     * Hosts and suffixes that are never reachable from the public internet.
     *
     * Used to keep `auto` from picking Google or Office on a machine Google cannot reach — the
     * single most common way a document embed “works locally and is blank on staging”, except
     * backwards.
     */
    private const PRIVATE_SUFFIXES = [
        '.local', '.localhost', '.test', '.dev', '.internal', '.invalid', '.example',
        '.ddev.site', '.lndo.site', '.docksal', '.vm', '.home.arpa',
    ];

    /**
     * The URL a viewer, a frame, a link or a download button should point at.
     *
     * @param string $disposition {@see self::DISPOSITION_INLINE} or
     *                            {@see self::DISPOSITION_ATTACHMENT}
     */
    public function fileUrl(Source $source, DocumentOptions $options, string $disposition = self::DISPOSITION_INLINE): ?string
    {
        if ($source->getIsEmpty()) {
            return null;
        }

        if (!$source->getIsAsset()) {
            // A URL is somebody else's file. Book neither serves it nor rewrites it; the only
            // thing it can honestly do is pass it along.
            return $source->getSourceUrl();
        }

        if (!$this->getServesThroughCraft($source, $options, $disposition)) {
            return $source->getAssetUrl();
        }

        return $this->craftUrl($source, $options, $disposition);
    }

    /** Whether this file goes through Book's own route rather than its volume URL. */
    public function getServesThroughCraft(Source $source, DocumentOptions $options, string $disposition = self::DISPOSITION_INLINE): bool
    {
        if (!$source->getIsAsset()) {
            return false;
        }

        // No volume URL means there is no other option, which is the whole reason this exists.
        if ($source->getAssetUrl() === null) {
            return true;
        }

        if ($options->access === DocumentOptions::ACCESS_LOGIN) {
            return true;
        }

        // Signing is meaningless on a URL Book did not mint. A site that has asked for signed
        // URLs and still gets bare volume paths has the setting and none of the effect.
        if (Plugin::getInstance()->getSettings()->signedUrls) {
            return true;
        }

        // A volume URL cannot be asked to send `Content-Disposition: attachment`, so a download
        // button that must actually download has to come through Craft.
        if ($disposition === self::DISPOSITION_ATTACHMENT) {
            return true;
        }

        return $options->serveThroughCraft ?? Plugin::getInstance()->getSettings()->serveAssetsThroughCraft;
    }

    /**
     * Book's own delivery URL for an asset.
     *
     * The filename is in the path rather than a parameter so that “save as” offers the right
     * name even when the response is being shown inline, and so the URL is readable in a log.
     *
     * A token is minted whenever the answer to “may this be served?” is anything other than
     * “yes, to anyone” — for a private volume, for a login-gated document, or because the site
     * has asked for signatures. The token is the only authentic carrier of that answer:
     * {@see \justinholtweb\book\controllers\FileController} refuses to serve a private file
     * without one, and reads the access rule out of it rather than out of a query parameter
     * anybody could edit.
     */
    public function craftUrl(Source $source, DocumentOptions $options, string $disposition = self::DISPOSITION_INLINE): ?string
    {
        $asset = $source->getAsset();

        if (!$asset) {
            return null;
        }

        $path = 'book/file/' . $asset->uid;
        $filename = $source->filename;

        if ($filename !== '') {
            $path .= '/' . rawurlencode($filename);
        }

        $params = [];
        $settings = Plugin::getInstance()->getSettings();
        $needsToken = $settings->signedUrls
            || $options->access === DocumentOptions::ACCESS_LOGIN
            || $source->getAssetUrl() === null;

        if ($needsToken) {
            // Only a site that asked for signatures gets an expiry. A private-volume URL that
            // expired on its own would break every page that embeds it, silently, later.
            $expires = $settings->signedUrls && $settings->signedUrlDuration > 0
                ? time() + $settings->signedUrlDuration
                : 0;

            $params[self::PARAM] = $this->sign($asset->uid, $disposition, $options->access, $expires);
        } elseif ($disposition === self::DISPOSITION_ATTACHMENT) {
            $params['dl'] = 1;
        }

        return UrlHelper::siteUrl($path, $params ?: null);
    }

    /** Whether a URL Book minted for this file will carry a token. */
    public function getNeedsToken(Source $source, DocumentOptions $options): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        return $settings->signedUrls
            || $options->access === DocumentOptions::ACCESS_LOGIN
            || ($source->getIsAsset() && $source->getAssetUrl() === null);
    }

    // Tokens
    // -------------------------------------------------------------------------

    /** `expires.access.disposition.hmac`, where an expiry of 0 means “never”. */
    public function sign(string $uid, string $disposition, string $access, int $expires): string
    {
        return implode('.', [
            $expires,
            $access,
            $disposition,
            $this->hmac($uid, $disposition, $access, $expires),
        ]);
    }

    /**
     * @return array{valid: bool, reason: string, access: string, disposition: string}
     */
    public function verify(?string $token, string $uid): array
    {
        $fail = fn(string $reason) => [
            'valid' => false,
            'reason' => $reason,
            'access' => DocumentOptions::ACCESS_LOGIN,
            'disposition' => self::DISPOSITION_INLINE,
        ];

        if (!$token) {
            return $fail('missing');
        }

        $parts = explode('.', $token, 4);

        if (count($parts) !== 4) {
            return $fail('malformed');
        }

        [$expires, $access, $disposition, $hmac] = $parts;
        $expires = (int)$expires;

        if (!in_array($access, DocumentOptions::ACCESSES, true)) {
            return $fail('malformed');
        }

        if (!in_array($disposition, [self::DISPOSITION_INLINE, self::DISPOSITION_ATTACHMENT], true)) {
            return $fail('malformed');
        }

        // hash_equals, not `===`: a plain comparison leaks how much of the digest matched, and
        // this one is checked on a public route as fast as anybody cares to ask.
        if (!hash_equals($this->hmac($uid, $disposition, $access, $expires), $hmac)) {
            return $fail('signature');
        }

        if ($expires > 0 && $expires < time()) {
            return $fail('expired');
        }

        return [
            'valid' => true,
            'reason' => '',
            'access' => $access,
            'disposition' => $disposition,
        ];
    }

    private function hmac(string $uid, string $disposition, string $access, int $expires): string
    {
        $key = Craft::$app->getConfig()->getGeneral()->securityKey;
        $parts = ['book', $uid, $disposition, $access, $expires];

        // Only when set, so that a site which has never used it keeps every link it already
        // published. Setting or changing it is how every outstanding link is revoked at once.
        $secret = Plugin::getInstance()->getSettings()->getLinkSecret();

        if ($secret !== '') {
            $parts[] = $secret;
        }

        return hash_hmac('sha256', implode('|', $parts), $key);
    }

    // Reachability
    // -------------------------------------------------------------------------

    /**
     * Whether a URL could plausibly be fetched by a server on the other side of the internet.
     *
     * Deliberately a *syntactic* judgement — Book makes no request to find out, and could not
     * answer the real question anyway (whether Google's crawler in particular is allowed in).
     * It catches what actually goes wrong: local domains, private addresses, and relative URLs.
     */
    public function isPubliclyReachable(?string $url): bool
    {
        $url = trim((string)$url);

        if ($url === '' || !preg_match('~^https?://~i', $url)) {
            return false;
        }

        $host = strtolower((string)parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        if ($host === 'localhost' || !str_contains($host, '.')) {
            return false;
        }

        foreach (self::PRIVATE_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool)filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        // Credentials in the URL would be handed to whichever viewer gets it.
        if (parse_url($url, PHP_URL_USER) !== null) {
            return false;
        }

        return true;
    }

    /**
     * Whether a signed or login-gated URL can be handed to a third-party viewer at all.
     *
     * It cannot: Google fetches the document from its own servers, as nobody, so a URL that
     * needs a session is a URL it gets 403 from. A signed URL works only while it is unexpired,
     * which makes for an embed that silently dies — worth a warning either way.
     */
    public function getIsFetchableByOthers(Source $source, DocumentOptions $options): bool
    {
        if ($options->access === DocumentOptions::ACCESS_LOGIN) {
            return false;
        }

        return $this->isPubliclyReachable($this->fileUrl($source, $options));
    }

    /**
     * Whether handing this file to a third-party viewer would publish something that is not
     * otherwise published.
     *
     * True for an asset on a volume with no public URLs: Book can serve it, and a viewer that
     * fetches it will therefore download a file nobody could have reached by its own path.
     * That is a legitimate choice and a surprising one, so it is a warning rather than a rule.
     */
    public function getWouldExposePrivateFile(Source $source): bool
    {
        return $source->getIsAsset() && $source->getAsset() !== null && $source->getAssetUrl() === null;
    }

}

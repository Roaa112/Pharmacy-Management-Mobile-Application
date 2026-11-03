<?php

namespace App\Modules\DataTransfer\Support;

use DomainException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Images named in import files: either a path the store already serves (under public/, e.g.
 * "products/abc.png") or an http(s) URL that is downloaded once, checked and stored like an
 * uploaded image (raster only, by detected content, at most 5 MB).
 *
 * Downloads never reach the server's own network: the host must resolve only to public
 * addresses, redirects are not followed (a redirect could point inside), and the connection is
 * pinned to the address that was checked.
 */
class ImageFetcher
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public static function isUrl(?string $value): bool
    {
        return $value !== null && preg_match('#^https?://#i', $value) === 1;
    }

    /** Problems visible without downloading (preview), or null. */
    public function check(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (self::isUrl($value)) {
            return filter_var($value, FILTER_VALIDATE_URL) ? null : 'Not a valid URL.';
        }

        return $this->existingPath($value) ? null : 'No image at this path (use a full http(s) URL or a path under public/).';
    }

    /** A path relative to public/ for the image column. Throws DomainException when unusable. */
    public function fetch(string $value, string $folder): string
    {
        if (!self::isUrl($value)) {
            return $this->existingPath($value) ?? throw new DomainException("No image at {$value}.");
        }

        $parts = parse_url($value);
        $host = $parts['host'] ?? '';
        $ip = $this->publicAddress($host);

        try {
            $response = Http::timeout(10)->withOptions([
                'allow_redirects' => false,
                'stream' => false,
                'curl' => [CURLOPT_RESOLVE => ["{$host}:".($parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80)).":{$ip}"]],
            ])->get($value);
        } catch (Throwable $e) {
            throw new DomainException('The image could not be downloaded: '.Str::limit($e->getMessage(), 120));
        }

        if (!$response->successful()) {
            throw new DomainException("The image URL answered HTTP {$response->status()}.");
        }

        $body = $response->body();
        if (strlen($body) === 0 || strlen($body) > self::MAX_BYTES) {
            throw new DomainException('The image must be between 1 byte and 5 MB.');
        }

        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
        if (!isset(self::TYPES[$type])) {
            throw new DomainException('The image must be a JPG, PNG or WebP file (got '.($type ?: 'unknown').').');
        }

        $relative = trim($folder, '/').'/'.Str::uuid().'.'.self::TYPES[$type];
        $absolute = public_path($relative);
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }
        file_put_contents($absolute, $body);

        return $relative;
    }

    /** Remove an image this import downloaded (its record failed). */
    public function discard(?string $relative): void
    {
        if ($relative && is_file(public_path($relative))) {
            @unlink(public_path($relative));
        }
    }

    /** The IP to connect to; refuses hosts that resolve to private, loopback or reserved ranges. */
    protected function publicAddress(string $host): string
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            throw new DomainException("The image host {$host} could not be resolved.");
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new DomainException('Images cannot be downloaded from private or local addresses.');
            }
        }

        return $ips[0];
    }

    private function existingPath(string $value): ?string
    {
        $path = ltrim(str_replace('\\', '/', $value), '/');

        // A path inside public/ only: no "..", no absolute paths.
        if ($path === '' || str_contains($path, '..') || !preg_match('#^[A-Za-z0-9_\-./]+$#', $path)) {
            return null;
        }

        return is_file(public_path($path)) ? $path : null;
    }
}

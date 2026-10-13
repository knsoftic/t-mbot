<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Validates media URLs before they are handed to Telegram.
 *
 * Telegram downloads URL media itself, so we only check the URL is well
 * formed, uses an allowed scheme and does not point at a private network
 * address. probe() optionally issues a short HEAD request so the admin can
 * see the content type and size before saving.
 */
class MediaUrlValidator
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return string|null Error message, or null when the URL is acceptable.
     */
    public function validate(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return 'The media URL is not a valid URL.';
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $allowHttp = (bool) $this->settings->get('telegram.allow_http_media');

        if ($scheme !== 'https' && ! ($allowHttp && $scheme === 'http')) {
            return $allowHttp ? 'Only http:// and https:// URLs are supported.' : 'Media URLs must use HTTPS.';
        }

        $host = strtolower($parts['host'] ?? '');

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return 'The media URL must point to a public host.';
        }

        if (filter_var($host, FILTER_VALIDATE_IP) && ! $this->isPublicIp($host)) {
            return 'The media URL must not point to a private or reserved IP address.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Media URLs must not contain credentials.';
        }

        return null;
    }

    /**
     * Resolve the host and issue a HEAD request (short timeout).
     *
     * @return array{ok: bool, message: string, content_type?: string|null, size?: int|null}
     */
    public function probe(string $url): array
    {
        if ($error = $this->validate($url)) {
            return ['ok' => false, 'message' => $error];
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $ips = @gethostbynamel($host) ?: [];

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return ['ok' => false, 'message' => 'The media host resolves to a private network address.'];
            }
        }

        try {
            $response = Http::timeout(8)->connectTimeout(5)->withoutRedirecting()->head($url);

            if ($response->redirect()) {
                $location = (string) $response->header('Location');

                return ['ok' => true, 'message' => 'URL redirects to '.$location.'. Telegram follows redirects, but a direct link is more reliable.', 'content_type' => null, 'size' => null];
            }

            if (! $response->successful()) {
                return ['ok' => false, 'message' => "The URL returned HTTP {$response->status()}. Telegram will not be able to download it."];
            }

            $type = $response->header('Content-Type') ?: null;
            $size = $response->header('Content-Length') !== '' ? (int) $response->header('Content-Length') : null;
            $message = 'Reachable'.($type ? " · {$type}" : '').($size ? ' · '.number_format($size / 1048576, 2).' MB' : '');

            if ($type && str_starts_with($type, 'text/html')) {
                return ['ok' => false, 'message' => 'The URL returns an HTML page, not a media file. Use a direct link to the file.', 'content_type' => $type, 'size' => $size];
            }

            if ($size && $size > 20 * 1048576) {
                $message .= ' — warning: Telegram only downloads URL media up to 20 MB (5 MB for photos). Upload the file instead.';
            }

            return ['ok' => true, 'message' => $message, 'content_type' => $type, 'size' => $size];
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'The URL could not be reached (timeout or network error).'];
        }
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}

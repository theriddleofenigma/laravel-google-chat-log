<?php

declare(strict_types=1);

namespace Enigma\Support;

/**
 * Helpers that keep webhook secrets (the "key" and "token" query params) out
 * of exception messages, fallback log lines and console output.
 */
class WebhookUrl
{
    /**
     * Mask a webhook url, e.g. "spaces/AAAA/messages?key=***&token=***".
     */
    public static function mask(string $url): string
    {
        $parts = parse_url(trim($url));

        if ($parts === false) {
            return '***';
        }

        $path = $parts['path'] ?? '';

        if (preg_match('~/(spaces/[^/?#]+(?:/[^?#]*)?)$~', $path, $matches)) {
            $masked = $matches[1];
        } else {
            $masked = isset($parts['host']) ? "{$parts['host']}/***" : '***';
        }

        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);

            $masked .= '?'.implode('&', array_map(
                fn ($name) => "{$name}=***",
                array_keys($query)
            ));
        }

        return $masked;
    }

    /**
     * The Google Chat space id from a webhook url, or null when there is none.
     */
    public static function spaceId(string $url): ?string
    {
        return preg_match('~/spaces/([^/?#]+)~', $url, $matches) ? $matches[1] : null;
    }

    /**
     * Remove webhook secrets from arbitrary text such as exception messages.
     *
     * @param  array<int, string>  $urls  Known raw urls to replace with their masked form.
     */
    public static function scrub(string $text, array $urls = []): string
    {
        foreach ($urls as $url) {
            if ($url === '') {
                continue;
            }

            $text = str_replace([$url, str_replace('/', '\/', $url)], self::mask($url), $text);

            // The secrets themselves may be echoed back as free text.
            foreach (self::secrets($url) as $secret) {
                $text = str_replace([$secret, rawurldecode($secret)], '***', $text);
            }
        }

        $text = (string) preg_replace_callback(
            '~https?://[^\s"\'<>]+~i',
            fn (array $matches) => self::mask($matches[0]),
            $text
        );

        return (string) preg_replace('~\b(key|token)=[^&\s"\'<>]+~i', '$1=***', $text);
    }

    /**
     * The raw "key" and "token" query values of a webhook url.
     *
     * Very short values are skipped so masking never mangles ordinary words.
     *
     * @return array<int, string>
     */
    protected static function secrets(string $url): array
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);
        $secrets = [];

        foreach (explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if (in_array(strtolower($name), ['key', 'token'], true) && strlen($value) >= 6) {
                $secrets[] = $value;
            }
        }

        return $secrets;
    }
}

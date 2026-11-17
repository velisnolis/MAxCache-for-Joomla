<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

\defined('_JEXEC') or die;

final class ResponseSafety
{
    // Joomla adds the session form token to the script options of every page
    // that loads core.js; Joomla.request() sends it with non-GET requests.
    private const SCRIPT_OPTION_TOKEN = '/("csrf\.token"\s*:\s*)"[a-f0-9]{32}"/i';

    public static function hasPrivateCacheControl(array $headers): bool
    {
        foreach ($headers as $header) {
            if (
                strcasecmp((string) ($header['name'] ?? ''), 'Cache-Control') === 0
                && preg_match('/(?:^|,)\s*(?:private|no-store)(?:\s*=|\s*(?:,|$))/i', (string) ($header['value'] ?? ''))
            ) {
                return true;
            }
        }

        return false;
    }

    public static function findJoomlaFormToken(string $html): ?string
    {
        if ($html === '' || stripos($html, '<input') === false) {
            return null;
        }

        if (!preg_match_all('/<input\b[^>]*>/i', $html, $matches)) {
            return null;
        }

        foreach ($matches[0] as $input) {
            if (
                !preg_match('/\bname\s*=\s*(["\'])([a-f0-9]{32})\1/i', $input, $name)
                || !preg_match('/\bvalue\s*=\s*(["\'])1\1/i', $input)
            ) {
                continue;
            }

            return strtolower((string) $name[2]);
        }

        return null;
    }

    public static function containsJoomlaFormToken(string $html): bool
    {
        return self::findJoomlaFormToken($html) !== null;
    }

    /**
     * Replaces every Joomla form-token input with a nameless placeholder that the
     * browser fills with the visitor's own token when the form is sent.
     *
     * Only the input Joomla prints is replaced: type="hidden", a 32-character hex
     * name and value "1", read from the real attributes. A form="" association is
     * kept. Returns null when a replaced token still appears elsewhere in the
     * document, raw or as HTML entities, for example in a link, an inline script or
     * a second input: that copy cannot be isolated, so the page must not be stored.
     */
    public static function deferFormTokens(string $html, ?int &$count = null): ?string
    {
        $count = 0;
        $tokens = [];

        $deferred = preg_replace_callback(
            '/<input\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i',
            static function (array $match) use (&$count, &$tokens): string {
                $attributes = self::parseAttributes($match[0]);

                if (
                    strtolower($attributes['type'] ?? '') !== 'hidden'
                    || !preg_match('/^[a-f0-9]{32}$/i', $attributes['name'] ?? '')
                    || ($attributes['value'] ?? '') !== '1'
                ) {
                    return $match[0];
                }

                ++$count;
                $tokens[strtolower($attributes['name'])] = true;
                $owner = isset($attributes['form']) && $attributes['form'] !== ''
                    ? ' form="' . htmlspecialchars($attributes['form'], ENT_QUOTES, 'UTF-8') . '"'
                    : '';

                return '<input type="hidden"' . $owner . ' data-maxcache-form-token>';
            },
            $html
        );

        if ($deferred === null) {
            return null;
        }

        $decoded = html_entity_decode($deferred, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach (array_keys($tokens) as $token) {
            if (stripos($deferred, (string) $token) !== false || stripos($decoded, (string) $token) !== false) {
                return null;
            }
        }

        return $deferred;
    }

    /**
     * Attributes of one start tag, names lowercased and values entity-decoded. As in
     * a browser, the first occurrence of a repeated attribute wins.
     *
     * @return array<string, string>
     */
    private static function parseAttributes(string $tag): array
    {
        $attributes = [];
        $inner = (string) preg_replace('/^<[a-z0-9]+|\/?>$/i', '', $tag);

        preg_match_all(
            '/([^\s"\'=<>\/]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/',
            $inner,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $name = strtolower($match[1]);

            if (isset($attributes[$name])) {
                continue;
            }

            $value = $match[2] ?? '';

            if ($value === '' && ($match[3] ?? '') !== '') {
                $value = $match[3];
            } elseif ($value === '' && ($match[4] ?? '') !== '') {
                $value = $match[4];
            }

            $attributes[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $attributes;
    }

    public static function containsScriptOptionToken(string $html): bool
    {
        return preg_match(self::SCRIPT_OPTION_TOKEN, $html) === 1;
    }

    public static function removeScriptOptionToken(string $html, ?int &$count = null): string
    {
        $count = 0;

        return (string) preg_replace(self::SCRIPT_OPTION_TOKEN, '$1""', $html, -1, $count);
    }
}

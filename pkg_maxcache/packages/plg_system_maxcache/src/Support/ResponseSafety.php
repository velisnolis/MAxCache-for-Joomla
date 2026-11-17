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
                // An empty form="" means "no owner" and must stay that way.
                $owner = isset($attributes['form'])
                    ? ' form="' . htmlspecialchars($attributes['form'], ENT_QUOTES, 'UTF-8') . '"'
                    : '';

                return '<input type="hidden"' . $owner . ' data-maxcache-form-token>';
            },
            $html
        );

        if ($deferred === null) {
            return null;
        }

        $decoded = self::decodeEntities($deferred);

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
        $inner = substr((string) preg_replace('/^<[a-z0-9]+/i', '', $tag), 0, -1);
        // A trailing slash closes the tag only after whitespace or a quote;
        // in value=1/ it belongs to the value.
        $inner = (string) preg_replace('/(^|[\s"\'])\/\s*$/', '$1', $inner);

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

            $attributes[$name] = self::decodeEntities($value);
        }

        return $attributes;
    }

    /**
     * Character references as a browser reads them, in one pass so that decoded
     * text is never read as a reference again ("&#38;copy;" is "&copy;", not "©").
     * Numeric ones also count without their closing semicolon, which
     * html_entity_decode() leaves alone.
     */
    private static function decodeEntities(string $text): string
    {
        return (string) preg_replace_callback(
            '/&(?:#x([0-9a-f]+)|#([0-9]+)|[a-z][a-z0-9]*);?/i',
            static function (array $match): string {
                $isHex = ($match[1] ?? '') !== '';

                if (!$isHex && ($match[2] ?? '') === '') {
                    return html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }

                // Like the HTML parser, take every digit; an out-of-range number
                // becomes the replacement character, not a shorter reference.
                $digits = ltrim($isHex ? $match[1] : (string) $match[2], '0');

                if ($digits === '' || \strlen($digits) > ($isHex ? 6 : 7)) {
                    return "\u{FFFD}";
                }

                $code = $isHex ? hexdec($digits) : (int) $digits;

                return $code <= 0x10FFFF && ($code < 0xD800 || $code > 0xDFFF)
                    ? (string) mb_chr((int) $code, 'UTF-8')
                    : "\u{FFFD}";
            },
            $text
        );
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

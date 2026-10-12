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

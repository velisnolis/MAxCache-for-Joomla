<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

\defined('_JEXEC') or die;

final class RegexPatternMatcher
{
    /**
     * @param string[] $patterns
     */
    public static function matchesAny(array $patterns, string $subject): bool
    {
        foreach ($patterns as $pattern) {
            $result = self::pregMatch(self::wrap((string) $pattern), $subject);

            if ($result === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $patterns
     *
     * @return string[]
     */
    public static function filterValid(array $patterns): array
    {
        return array_values(array_filter(
            $patterns,
            static fn (string $pattern): bool => self::isValid($pattern)
        ));
    }

    /**
     * @param string[] $patterns
     *
     * @return string[]
     */
    public static function findInvalid(array $patterns): array
    {
        return array_values(array_filter(
            $patterns,
            static fn (string $pattern): bool => !self::isValid($pattern)
        ));
    }

    public static function isValid(string $pattern): bool
    {
        return self::pregMatch(self::wrap($pattern), '') !== false;
    }

    private static function wrap(string $pattern): string
    {
        return '#' . $pattern . '#i';
    }

    private static function pregMatch(string $regex, string $subject): int|false
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($regex, $subject);
        } finally {
            restore_error_handler();
        }
    }
}

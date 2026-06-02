<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

\defined('_JEXEC') or die;

final class CachePathBuilder
{
    /**
     * @param string[] $languageSefs
     */
    public static function build(array $options): ?string
    {
        $root = rtrim((string) ($options['cache_root'] ?? ''), '/');
        $host = strtolower(trim((string) ($options['host'] ?? '')));
        $requestPath = (string) ($options['request_path'] ?? '/');

        if ($root === '' || $host === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim($requestPath, '/')), 'strlen'));
        $parts = [$root, self::sanitizePathSegment($host)];
        $languageSefs = self::normalizeLanguageSefs((array) ($options['language_sefs'] ?? []));
        $hasLanguagePrefix = self::requestHasLanguagePrefix($segments, $languageSefs);

        if ((int) ($options['vary_language'] ?? 1) === 1 && $hasLanguagePrefix) {
            $parts[] = strtolower((string) $segments[0]);
            array_shift($segments);
        }

        foreach ($segments as $segment) {
            $parts[] = self::sanitizePathSegment((string) $segment);
        }

        $filename = 'index';

        if ((int) ($options['vary_mobile'] ?? 0) === 1 && (bool) ($options['is_mobile'] ?? false)) {
            $filename .= '-mobile';
        }

        if (strtolower((string) ($options['scheme'] ?? '')) === 'https') {
            $filename .= '-https';
        }

        $variantSuffix = trim((string) ($options['variant_suffix'] ?? ''));

        if ($variantSuffix !== '') {
            $filename .= '-v-' . self::sanitizePathSegment($variantSuffix);
        }

        $filename .= '.html';

        return implode('/', $parts) . '/' . $filename;
    }

    public static function sanitizePathSegment(string $segment): string
    {
        $segment = rawurlencode(rawurldecode(trim($segment)));

        return $segment === '' ? 'index' : $segment;
    }

    /**
     * @param string[] $segments
     * @param string[] $languageSefs
     */
    private static function requestHasLanguagePrefix(array $segments, array $languageSefs): bool
    {
        if ($segments === []) {
            return false;
        }

        $segment = strtolower((string) $segments[0]);

        if ($languageSefs !== []) {
            return \in_array($segment, $languageSefs, true);
        }

        return (bool) preg_match('#^[a-z]{2,3}(?:-[a-z]{2})?$#', $segment);
    }

    /**
     * @param string[] $languageSefs
     * @return string[]
     */
    private static function normalizeLanguageSefs(array $languageSefs): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($sef): string => strtolower(trim((string) $sef)),
            $languageSefs
        ))));
    }
}

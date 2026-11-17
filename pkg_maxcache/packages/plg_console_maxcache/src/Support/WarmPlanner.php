<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Console.maxcache
 */

namespace Vendor\Plugin\Console\Maxcache\Support;

\defined('_JEXEC') or die;

/**
 * Builds the public URLs of top-level menu items without booting the site router,
 * which is not available to console commands.
 */
final class WarmPlanner
{
    /**
     * @return array{scheme: string, host: string, base: string}|null
     */
    public static function parseBaseUrl(string $baseUrl): ?array
    {
        $parts = parse_url(trim($baseUrl));

        if (!\is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (!\in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $authority = $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');

        return [
            'scheme' => $scheme,
            'host' => $authority,
            'base' => $scheme . '://' . $authority . rtrim((string) ($parts['path'] ?? ''), '/'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items   Menu rows: id, path, home, language, type, level.
     * @param array<string, mixed>             $options base_url, sef_rewrite, sef_suffix, language_filter,
     *                                                  remove_default_prefix, default_language,
     *                                                  languages (lang_code => sef), excluded_menu_items.
     *
     * @return array<int, array{menu_id: int, url: string, path: string}>
     */
    public static function plan(array $items, array $options): array
    {
        $base = self::parseBaseUrl((string) ($options['base_url'] ?? ''));

        if ($base === null) {
            return [];
        }

        $basePath = (string) parse_url($base['base'], PHP_URL_PATH);
        $entry = !empty($options['sef_rewrite']) ? '' : '/index.php';
        $suffix = !empty($options['sef_suffix']) ? '.html' : '';
        $languages = [];

        foreach ((array) ($options['languages'] ?? []) as $code => $sef) {
            $sef = strtolower(trim((string) $sef));

            if ($sef !== '') {
                $languages[(string) $code] = $sef;
            }
        }

        $prefixed = !empty($options['language_filter']) && $languages !== [];
        $removeDefault = !empty($options['remove_default_prefix']);
        $defaultLanguage = (string) ($options['default_language'] ?? '');
        $excluded = array_map('intval', (array) ($options['excluded_menu_items'] ?? []));
        $targets = [];

        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            $menuPath = trim((string) ($item['path'] ?? ''), '/');
            $isHome = (int) ($item['home'] ?? 0) === 1;

            if (
                $id <= 0
                || (string) ($item['type'] ?? '') !== 'component'
                || (int) ($item['level'] ?? 1) !== 1
                || \in_array($id, $excluded, true)
                || ($menuPath === '' && !$isHome)
            ) {
                continue;
            }

            $language = (string) ($item['language'] ?? '*');

            if (!$prefixed) {
                $prefixes = [''];
            } elseif ($language === '*') {
                $prefixes = [];

                foreach ($languages as $code => $sef) {
                    $prefixes[] = $removeDefault && $code === $defaultLanguage ? '' : '/' . $sef;
                }
            } elseif (isset($languages[$language])) {
                $prefixes = [$removeDefault && $language === $defaultLanguage ? '' : '/' . $languages[$language]];
            } else {
                // The item's language is not published: Joomla does not route it.
                continue;
            }

            foreach ($prefixes as $prefix) {
                if ($isHome) {
                    $path = $entry . $prefix . '/';
                } else {
                    $segments = implode('/', array_map('rawurlencode', explode('/', $menuPath)));
                    $path = $entry . $prefix . '/' . $segments . $suffix;
                }

                $path = $basePath . $path;

                if (isset($targets[$path])) {
                    continue;
                }

                $targets[$path] = [
                    'menu_id' => $id,
                    'url' => $base['scheme'] . '://' . $base['host'] . $path,
                    'path' => $path,
                ];
            }
        }

        return array_values($targets);
    }
}

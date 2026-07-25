<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Console.maxcache
 */

namespace Vendor\Plugin\Console\Maxcache\Support;

use Joomla\Database\DatabaseInterface;
use Vendor\Plugin\System\Maxcache\Support\AtomicFileWriter;
use Vendor\Plugin\System\Maxcache\Support\CacheOperations;
use Vendor\Plugin\System\Maxcache\Support\JoomlaCacheCleaner;

\defined('_JEXEC') or die;

final class MaxcacheState
{
    private const BUILT_IN_EXCLUSIONS = [
        '/administrator',
        '/api',
        '/component/ajax',
        '/component/users',
        'option=com_ajax',
        'option=com_users',
        'format=feed|json|raw',
        'tmpl=component',
    ];

    public function __construct(private DatabaseInterface $db)
    {
    }

    public function snapshot(): array
    {
        $plugin = $this->loadPlugin('system', 'maxcache');

        if ($plugin === null) {
            return [
                'installed' => false,
                'enabled' => false,
                'version' => null,
                'issues' => [
                    [
                        'severity' => 'error',
                        'code' => 'system-plugin-missing',
                        'message' => 'System - MAxCache is not installed.',
                    ],
                ],
            ];
        }

        $params = $this->decodeJson((string) ($plugin->params ?? '{}'));
        $manifest = $this->decodeJson((string) ($plugin->manifest_cache ?? '{}'));
        $cacheRoot = rtrim((string) ($params['cache_root'] ?? (JPATH_ROOT . '/maxcache')), '/');
        $stats = $this->cacheStats($cacheRoot);
        $htaccess = is_file(JPATH_ROOT . '/.htaccess')
            ? (string) file_get_contents(JPATH_ROOT . '/.htaccess')
            : '';
        $customExclusions = $this->normalizeLines((string) ($params['exclude'] ?? ''));
        $invalidExclusions = array_values(array_filter(
            $customExclusions,
            static fn (string $pattern): bool => @preg_match('#' . $pattern . '#i', '') === false
        ));
        $systemCache = $this->loadPlugin('system', 'cache');
        $issues = [];

        if ((int) ($plugin->enabled ?? 0) !== 1) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'system-plugin-disabled',
                'message' => 'System - MAxCache is installed but disabled.',
            ];
        }

        if (!$stats['safe']) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'unsafe-cache-root',
                'message' => 'The configured cache root is unsafe and purge operations are blocked.',
            ];
        }

        if ($systemCache !== null && (int) ($systemCache->enabled ?? 0) === 1) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'joomla-system-cache-enabled',
                'message' => 'Joomla System - Cache is enabled and may serve a response before MAxCache runs.',
            ];
        }

        if ($invalidExclusions !== []) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'invalid-exclusion-patterns',
                'message' => 'One or more custom exclusion regex patterns are invalid.',
                'patterns' => $invalidExclusions,
            ];
        }

        if (!str_contains($htaccess, '# BEGIN MAx Cache for Joomla')) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'managed-server-block-not-found',
                'message' => 'The generated MAxCache server block was not found in .htaccess.',
            ];
        }

        if (trim((string) ($params['site_hosts'] ?? '')) === '') {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'site-hosts-empty',
                'message' => 'No site hosts are configured for deterministic paths and targeted purge.',
            ];
        }

        return [
            'installed' => true,
            'enabled' => (int) ($plugin->enabled ?? 0) === 1,
            'version' => (string) ($manifest['version'] ?? 'unknown'),
            'cache' => $stats,
            'configuration' => [
                'path_mode' => (string) ($params['path_mode'] ?? 'host-sef'),
                'vary_language' => (bool) ($params['vary_language'] ?? false),
                'purge_strategy' => (string) ($params['purge_strategy'] ?? 'targeted'),
                'server_snippet_mode' => (string) ($params['server_snippet_mode'] ?? 'apache'),
                'site_hosts' => $this->normalizeLines((string) ($params['site_hosts'] ?? '')),
                'bypass_cookies' => $this->normalizeLines((string) ($params['bypass_cookies'] ?? '')),
                'excluded_menu_items' => array_values(array_map('intval', (array) ($params['exclude_menu_items'] ?? []))),
                'custom_exclusions' => $customExclusions,
                'built_in_exclusions' => self::BUILT_IN_EXCLUSIONS,
            ],
            'server' => [
                'htaccess_path' => JPATH_ROOT . '/.htaccess',
                'managed_block_present' => str_contains($htaccess, '# BEGIN MAx Cache for Joomla')
                    && str_contains($htaccess, '# END MAx Cache for Joomla'),
                'joomla_system_cache_enabled' => $systemCache !== null && (int) ($systemCache->enabled ?? 0) === 1,
            ],
            'issues' => $issues,
        ];
    }

    public function purge(): array
    {
        $snapshot = $this->snapshot();

        if (!$snapshot['installed']) {
            throw new \RuntimeException('System - MAxCache is not installed.');
        }

        $cache = $snapshot['cache'];
        $this->loadSystemSupport();
        $result = CacheOperations::purge((string) $cache['root'], JPATH_ROOT);
        $cleanedCaches = count(array_filter(
            JoomlaCacheCleaner::clean(),
            static fn (array $item): bool => (bool) ($item['cleaned'] ?? false)
        ));
        $markerPath = rtrim((string) JPATH_CACHE, '/') . '/plg_system_maxcache_full_purge.timestamp';

        return [
            ...$result,
            'joomla_caches_cleaned' => $cleanedCaches,
            'marker_written' => AtomicFileWriter::write($markerPath, (string) time()),
        ];
    }

    private function loadPlugin(string $folder, string $element): ?object
    {
        $query = $this->db->getQuery(true)
            ->select([
                $this->db->quoteName('extension_id'),
                $this->db->quoteName('enabled'),
                $this->db->quoteName('ordering'),
                $this->db->quoteName('params'),
                $this->db->quoteName('manifest_cache'),
            ])
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
            ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote($folder))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote($element));

        $this->db->setQuery($query);
        $result = $this->db->loadObject();

        return \is_object($result) ? $result : null;
    }

    private function cacheStats(string $cacheRoot): array
    {
        $this->loadSystemSupport();
        $stats = CacheOperations::stats($cacheRoot);

        return [
            'root' => $cacheRoot,
            'safe' => CacheOperations::isSafeRoot($cacheRoot, JPATH_ROOT),
            ...$stats,
        ];
    }

    private function loadSystemSupport(): void
    {
        $classes = [
            CacheOperations::class => 'CacheOperations.php',
            JoomlaCacheCleaner::class => 'JoomlaCacheCleaner.php',
            AtomicFileWriter::class => 'AtomicFileWriter.php',
        ];

        foreach ($classes as $class => $file) {
            if (class_exists($class)) {
                continue;
            }

            $path = JPATH_ROOT . '/plugins/system/maxcache/src/Support/' . $file;

            if (!is_file($path)) {
                throw new \RuntimeException('MAxCache support class is missing: ' . $path);
            }

            require_once $path;
        }
    }

    private function decodeJson(string $value): array
    {
        $decoded = json_decode($value, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function normalizeLines(string $value): array
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return array_values(array_filter(
            array_map('trim', explode("\n", $value)),
            static fn (string $line): bool => $line !== ''
        ));
    }
}

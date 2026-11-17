<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Console.maxcache
 */

namespace Vendor\Plugin\Console\Maxcache\Support;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Vendor\Plugin\System\Maxcache\Support\AtomicFileWriter;
use Vendor\Plugin\System\Maxcache\Support\BuiltInExclusions;
use Vendor\Plugin\System\Maxcache\Support\BypassCookieNames;
use Vendor\Plugin\System\Maxcache\Support\CacheOperations;
use Vendor\Plugin\System\Maxcache\Support\CachePathBuilder;
use Vendor\Plugin\System\Maxcache\Support\HtaccessManager;
use Vendor\Plugin\System\Maxcache\Support\JoomlaCacheCleaner;
use Vendor\Plugin\System\Maxcache\Support\ResponseSafety;
use Vendor\Plugin\System\Maxcache\Support\SnippetBuilder;
use Vendor\Plugin\System\Maxcache\Support\SnippetParams;
use Vendor\Plugin\System\Maxcache\Support\SystemCacheSettings;

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
        $tokenArtifacts = CacheOperations::joomlaFormTokenArtifacts($cacheRoot);
        $customExclusions = $this->normalizeLines((string) ($params['exclude'] ?? ''));
        $invalidExclusions = array_values(array_filter(
            $customExclusions,
            static fn (string $pattern): bool => @preg_match('#' . $pattern . '#i', '') === false
        ));
        $systemCache = $this->loadPlugin('system', 'cache');
        $issues = [];
        $snippetStatus = $this->getSnippetStatus($params);

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

        if ($tokenArtifacts['files'] > 0) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'csrf-token-in-cache',
                'message' => 'Static MAxCache artifacts contain Joomla form tokens and must be purged.',
                'files' => $tokenArtifacts['files'],
                'samples' => $tokenArtifacts['samples'],
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

        if (($snippetStatus['state'] ?? 'unknown') === 'not_applied') {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'managed-server-block-not-found',
                'message' => 'The generated MAxCache server block was not found in .htaccess.',
            ];
        }

        if (($snippetStatus['state'] ?? 'unknown') === 'outdated') {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'managed-server-block-outdated',
                'message' => 'The applied MAxCache server block is outdated. Review and explicitly apply the current snippet before relying on its exclusions.',
                'expected_hash' => $snippetStatus['expected_hash'] ?? null,
                'applied_hash' => $snippetStatus['applied_hash'] ?? null,
            ];
        }

        if (($snippetStatus['state'] ?? 'unknown') === 'unknown') {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'managed-server-block-unverified',
                'message' => 'The applied MAxCache server block could not be verified.',
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
            'response_safety' => [
                'csrf_token_files' => $tokenArtifacts['files'],
                'csrf_token_samples' => $tokenArtifacts['samples'],
                'unreadable_files' => $tokenArtifacts['unreadable'],
            ],
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
                'managed_block_present' => \in_array(
                    (string) ($snippetStatus['state'] ?? 'unknown'),
                    ['applied', 'outdated'],
                    true
                ),
                'snippet_state' => (string) ($snippetStatus['state'] ?? 'unknown'),
                'expected_hash' => $snippetStatus['expected_hash'] ?? null,
                'applied_hash' => $snippetStatus['applied_hash'] ?? null,
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

    /**
     * Top-level published site menu items as guest URLs.
     *
     * @return array{base_url: string, targets: array<int, array{menu_id: int, url: string, path: string}>}
     */
    public function warmPlan(string $baseUrl = ''): array
    {
        $plugin = $this->loadPlugin('system', 'maxcache');

        if ($plugin === null || (int) ($plugin->enabled ?? 0) !== 1) {
            throw new \RuntimeException('System - MAxCache is not installed and enabled.');
        }

        $app = Factory::getApplication();

        if (!(int) $app->get('sef', 1)) {
            throw new \RuntimeException('Search engine friendly URLs are off; MAxCache only stores SEF paths.');
        }

        $params = $this->decodeJson((string) ($plugin->params ?? '{}'));

        if (trim($baseUrl) === '') {
            $baseUrl = trim((string) $app->get('live_site', ''));
        }

        if ($baseUrl === '') {
            $hosts = $this->normalizeLines((string) ($params['site_hosts'] ?? ''));
            $baseUrl = $hosts !== [] ? 'https://' . strtolower($hosts[0]) : '';
        }

        $base = WarmPlanner::parseBaseUrl($baseUrl);

        if ($base === null) {
            throw new \RuntimeException('Pass --base-url=https://www.example.com: no usable live_site or site host is configured.');
        }

        $languageFilter = $this->loadPlugin('system', 'languagefilter');
        $languageFilterParams = $this->decodeJson((string) ($languageFilter?->params ?? '{}'));

        $query = $this->db->getQuery(true)
            ->select([$this->db->quoteName('lang_code'), $this->db->quoteName('sef')])
            ->from($this->db->quoteName('#__languages'))
            ->where($this->db->quoteName('published') . ' = 1')
            ->order($this->db->quoteName('ordering') . ' ASC');
        $this->db->setQuery($query);
        $languages = [];

        foreach ((array) $this->db->loadAssocList() as $language) {
            $languages[(string) $language['lang_code']] = (string) $language['sef'];
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('params'))
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('com_languages'));
        $this->db->setQuery($query);
        $languageComponent = $this->decodeJson((string) $this->db->loadResult());

        $query = $this->db->getQuery(true)
            ->select([
                $this->db->quoteName('id'),
                $this->db->quoteName('path'),
                $this->db->quoteName('home'),
                $this->db->quoteName('language'),
                $this->db->quoteName('type'),
                $this->db->quoteName('level'),
            ])
            ->from($this->db->quoteName('#__menu'))
            ->where($this->db->quoteName('client_id') . ' = 0')
            ->where($this->db->quoteName('published') . ' = 1')
            ->where($this->db->quoteName('level') . ' = 1')
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
            ->order($this->db->quoteName('home') . ' DESC, ' . $this->db->quoteName('lft') . ' ASC');
        $this->db->setQuery($query);

        $this->loadSystemSupport();

        return [
            'base_url' => $base['base'],
            'targets' => WarmPlanner::plan((array) $this->db->loadAssocList(), [
                'base_url' => $base['base'],
                'sef_rewrite' => (int) $app->get('sef_rewrite', 0),
                'sef_suffix' => (int) $app->get('sef_suffix', 0),
                'language_filter' => $languageFilter !== null && (int) ($languageFilter->enabled ?? 0) === 1,
                'remove_default_prefix' => (int) ($languageFilterParams['remove_default_prefix'] ?? 0),
                'default_language' => (string) ($languageComponent['site'] ?? 'en-GB'),
                'languages' => $languages,
                'excluded_menu_items' => SystemCacheSettings::mergeMenuItems((array) ($params['exclude_menu_items'] ?? [])),
            ]),
        ];
    }

    /**
     * Where the system plugin stores a desktop guest request for this URL, ignoring
     * pagecache-plugin variants, which only exist inside a site request.
     */
    public function artifactPath(string $url): ?string
    {
        $plugin = $this->loadPlugin('system', 'maxcache');
        $base = WarmPlanner::parseBaseUrl((string) preg_replace('#^(https?://[^/]+).*$#i', '$1', $url));

        if ($plugin === null || $base === null) {
            return null;
        }

        $params = $this->decodeJson((string) ($plugin->params ?? '{}'));
        $this->loadSystemSupport();

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('sef'))
            ->from($this->db->quoteName('#__languages'))
            ->where($this->db->quoteName('published') . ' = 1');
        $this->db->setQuery($query);

        return CachePathBuilder::build([
            'cache_root' => (string) ($params['cache_root'] ?? (JPATH_ROOT . '/maxcache')),
            'host' => $base['host'],
            'request_path' => (string) (parse_url($url, PHP_URL_PATH) ?: '/'),
            'scheme' => $base['scheme'],
            'vary_language' => (int) ($params['vary_language'] ?? 1),
            'vary_mobile' => (int) ($params['vary_mobile'] ?? 0),
            'is_mobile' => false,
            'variant_suffix' => '',
            'language_sefs' => array_values(array_filter(array_map(
                static fn ($sef): string => strtolower(trim((string) $sef)),
                (array) $this->db->loadColumn()
            ))),
        ]);
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
            CachePathBuilder::class => 'CachePathBuilder.php',
            JoomlaCacheCleaner::class => 'JoomlaCacheCleaner.php',
            AtomicFileWriter::class => 'AtomicFileWriter.php',
            BuiltInExclusions::class => 'BuiltInExclusions.php',
            BypassCookieNames::class => 'BypassCookieNames.php',
            HtaccessManager::class => 'HtaccessManager.php',
            SnippetBuilder::class => 'SnippetBuilder.php',
            SnippetParams::class => 'SnippetParams.php',
            SystemCacheSettings::class => 'SystemCacheSettings.php',
            ResponseSafety::class => 'ResponseSafety.php',
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

    private function getSnippetStatus(array $params): array
    {
        try {
            $this->loadSystemSupport();
            $snippet = SnippetParams::buildSnippet($params);

            return HtaccessManager::getStatus($snippet);
        } catch (\Throwable $exception) {
            return [
                'state' => 'unknown',
                'expected_hash' => null,
                'applied_hash' => null,
            ];
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

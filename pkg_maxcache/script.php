<?php

/**
 * @package     Joomla.Package
 * @subpackage  pkg_maxcache
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\Database\DatabaseInterface;

final class Pkg_MaxcacheInstallerScript extends InstallerScript
{
    private const BEGIN_MARKER = '# BEGIN MAx Cache for Joomla';
    private const END_MARKER = '# END MAx Cache for Joomla';
    private const MOD_MAXCACHE_MODULE_PATHS = [
        '/etc/apache2/modules/mod_maxcache.so',
        '/usr/lib64/apache2/modules/mod_maxcache.so',
        '/usr/lib64/httpd/modules/mod_maxcache.so',
    ];

    public function postflight(string $type, $parent): bool
    {
        $this->applyDetectedLanguageDefaults($type);
        $this->moveMaxcachePluginToLast();

        return true;
    }

    public function uninstall($parent): bool
    {
        $this->removeManagedServerConfig();

        return true;
    }

    private function applyDetectedLanguageDefaults(string $type): void
    {
        if (!\in_array($type, ['install', 'update'], true)) {
            return;
        }

        try {
            /** @var DatabaseInterface $db */
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $query = $db->getQuery(true)
                ->select([$db->quoteName('extension_id'), $db->quoteName('params'), $db->quoteName('enabled')])
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('maxcache'));

            $db->setQuery($query);
            $plugin = $db->loadObject();

            if (!$plugin || empty($plugin->extension_id)) {
                return;
            }

            $params = json_decode((string) ($plugin->params ?? '{}'), true);

            if (!\is_array($params)) {
                $params = [];
            }

            $shouldSave = $this->normalizeEscapedTextareaDefaults($params);

            if ($this->shouldApplyDetectedDefaults($type, $plugin, $params)) {
                $detected = $this->detectLanguageRoutingProfile($db);
                $params['path_mode'] = $detected['recommended_path_mode'];
                $params['vary_language'] = $detected['recommended_vary_language'];
                $params['autodetected_language_routing'] = $detected['state'];
                $params['server_snippet_mode'] = $this->detectModMaxcacheAvailability() ? 'mod_maxcache' : 'apache';
                $shouldSave = true;
            }

            if (!$shouldSave) {
                return;
            }

            $plugin->params = json_encode($params, JSON_UNESCAPED_SLASHES);
            $db->updateObject('#__extensions', $plugin, 'extension_id');
        } catch (\Throwable $exception) {
            // Keep installation resilient if defaults could not be inferred.
        }
    }

    private function normalizeEscapedTextareaDefaults(array &$params): bool
    {
        $changed = false;

        foreach (['bypass_cookies', 'site_hosts', 'allowed_query_params', 'exclude'] as $key) {
            if (!isset($params[$key]) || !\is_string($params[$key])) {
                continue;
            }

            if (!str_contains($params[$key], '\n')) {
                continue;
            }

            $params[$key] = str_replace('\n', "\n", $params[$key]);
            $changed = true;
        }

        return $changed;
    }

    private function shouldApplyDetectedDefaults(string $type, object $plugin, array $params): bool
    {
        if ($type === 'install') {
            return true;
        }

        $cacheRoot = (string) ($params['cache_root'] ?? '/var/cache/joomla-maxcache');
        $pathMode = (string) ($params['path_mode'] ?? 'host-sef');
        $varyLanguage = (int) ($params['vary_language'] ?? 0);
        $snippetMode = (string) ($params['server_snippet_mode'] ?? 'apache');
        $siteHosts = trim((string) ($params['site_hosts'] ?? ''));
        $exclude = trim((string) ($params['exclude'] ?? ''));
        $excludeMenuItems = $params['exclude_menu_items'] ?? [];
        $enabled = (int) ($plugin->enabled ?? 0) === 1;

        if ($enabled) {
            return false;
        }

        if ($cacheRoot !== '/var/cache/joomla-maxcache') {
            return false;
        }

        if ($pathMode !== 'host-sef' || $varyLanguage !== 0 || $snippetMode !== 'apache') {
            return false;
        }

        if ($siteHosts !== '' || $exclude !== '') {
            return false;
        }

        return empty($excludeMenuItems);
    }

    private function detectLanguageRoutingProfile(DatabaseInterface $db): array
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName('enabled'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('languagefilter'));
        $db->setQuery($query);
        $languageFilterEnabled = (int) $db->loadResult() === 1;

        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__languages'))
            ->where($db->quoteName('published') . ' = 1');
        $db->setQuery($query);
        $publishedLanguages = (int) $db->loadResult();

        $query = $db->getQuery(true)
            ->select($db->quoteName('path'))
            ->from($db->quoteName('#__menu'))
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('path') . ' <> ' . $db->quote(''))
            ->where($db->quoteName('path') . ' <> ' . $db->quote('/'));
        $db->setQuery($query);

        foreach ((array) $db->loadColumn() as $path) {
            $first = strtolower((string) strtok((string) $path, '/'));

            if ($first !== '' && (bool) preg_match('#^[a-z]{2}(?:-[a-z]{2})?$#', $first)) {
                return [
                    'state' => 'prefixed',
                    'recommended_path_mode' => 'host-language-sef',
                    'recommended_vary_language' => 1,
                ];
            }
        }

        if ($languageFilterEnabled && $publishedLanguages > 1 && $this->frontendRedirectShowsLanguagePrefix()) {
            return [
                'state' => 'prefixed',
                'recommended_path_mode' => 'host-language-sef',
                'recommended_vary_language' => 1,
            ];
        }

        if ($languageFilterEnabled && $publishedLanguages > 1) {
            return [
                'state' => 'multilingual_hidden',
                'recommended_path_mode' => 'host-sef',
                'recommended_vary_language' => 0,
            ];
        }

        return [
            'state' => 'single_language',
            'recommended_path_mode' => 'host-sef',
            'recommended_vary_language' => 0,
        ];
    }

    private function moveMaxcachePluginToLast(): void
    {
        try {
            /** @var DatabaseInterface $db */
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $query = $db->getQuery(true)
                ->select([$db->quoteName('extension_id'), $db->quoteName('ordering')])
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('maxcache'));

            $db->setQuery($query);
            $plugin = $db->loadObject();

            if (!$plugin || empty($plugin->extension_id)) {
                return;
            }

            $query = $db->getQuery(true)
                ->select('MAX(' . $db->quoteName('ordering') . ')')
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('extension_id') . ' <> ' . (int) $plugin->extension_id);

            $db->setQuery($query);
            $maxOrdering = (int) $db->loadResult();

            $plugin->ordering = $maxOrdering + 1;
            $db->updateObject('#__extensions', $plugin, 'extension_id');
        } catch (\Throwable $exception) {
            // Leave install/update successful even if ordering could not be adjusted.
        }
    }

    private function frontendRedirectShowsLanguagePrefix(): bool
    {
        $url = $this->getFrontendRootUrl();

        if ($url === null) {
            return false;
        }

        $headers = $this->fetchHeaders($url);

        if ($headers === []) {
            return false;
        }

        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) !== 'location') {
                continue;
            }

            foreach ((array) $value as $candidate) {
                $path = (string) parse_url((string) $candidate, PHP_URL_PATH);

                if ((bool) preg_match('#^/[a-z]{2}(?:-[a-z]{2})?(/|$)#i', $path)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function getFrontendRootUrl(): ?string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

        if ($host === '') {
            return null;
        }

        return $scheme . '://' . $host . '/';
    }

    private function fetchHeaders(string $url): array
    {
        if (\function_exists('curl_init')) {
            $ch = curl_init($url);

            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_NOBODY => true,
                    CURLOPT_HEADER => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                ]);

                $raw = curl_exec($ch);

                if (\is_string($raw) && $raw !== '') {
                    curl_close($ch);

                    return $this->parseRawHeaders($raw);
                }

                curl_close($ch);
            }
        }

        $result = @get_headers($url, true);

        return \is_array($result) ? $result : [];
    }

    private function parseRawHeaders(string $raw): array
    {
        $headers = [];
        $lines = preg_split("/\r\n|\n|\r/", trim($raw)) ?: [];

        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if (isset($headers[$name])) {
                $headers[$name] = array_merge((array) $headers[$name], [$value]);
            } else {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    private function detectModMaxcacheAvailability(): bool
    {
        foreach (self::MOD_MAXCACHE_MODULE_PATHS as $path) {
            if (is_readable($path)) {
                return true;
            }
        }

        return false;
    }

    private function removeManagedServerConfig(): void
    {
        try {
            if ($this->isAdminToolsAvailable()) {
                $this->removeManagedBlockFromAdminToolsFooter();

                return;
            }

            $this->removeManagedBlockFromHtaccess();
        } catch (\Throwable $exception) {
            // Uninstall should remain resilient even if cleanup fails.
        }
    }

    private function removeManagedBlockFromAdminToolsFooter(): void
    {
        $currentFooter = $this->getAdminToolsFooter();

        if ($currentFooter === null) {
            return;
        }

        $updatedFooter = $this->removeManagedBlockFromText($currentFooter);

        if ($updatedFooter === null) {
            return;
        }

        $this->runCliCommand([
            $this->getPhpBinary(),
            JPATH_ROOT . '/cli/joomla.php',
            'admintools:htmaker:set',
            '--key=custfoot',
            '--value=' . $updatedFooter,
        ]);

        $this->runCliCommand([
            $this->getPhpBinary(),
            JPATH_ROOT . '/cli/joomla.php',
            'admintools:htmaker:make',
        ]);
    }

    private function removeManagedBlockFromHtaccess(): void
    {
        $path = JPATH_ROOT . '/.htaccess';

        if (!is_file($path)) {
            return;
        }

        $current = (string) file_get_contents($path);
        $updated = $this->removeManagedBlockFromText($current);

        if ($updated === null) {
            return;
        }

        file_put_contents($path, $updated);
    }

    private function removeManagedBlockFromText(string $contents): ?string
    {
        $pattern = '/' . preg_quote(self::BEGIN_MARKER, '/') . '.*?' . preg_quote(self::END_MARKER, '/') . '\n?/s';

        if (!preg_match($pattern, $contents)) {
            return null;
        }

        $updated = preg_replace($pattern, '', $contents, 1);
        $updated = preg_replace("/\n{3,}/", "\n\n", (string) $updated);

        return rtrim((string) $updated) . "\n";
    }

    private function isAdminToolsAvailable(): bool
    {
        return is_dir(JPATH_ADMINISTRATOR . '/components/com_admintools')
            && is_file(JPATH_ROOT . '/cli/joomla.php');
    }

    private function getAdminToolsFooter(): ?string
    {
        $result = $this->runCliCommand([
            $this->getPhpBinary(),
            JPATH_ROOT . '/cli/joomla.php',
            'admintools:htmaker:get',
            '--option=custfoot',
        ], true);

        if (($result['exit_code'] ?? 1) !== 0) {
            return null;
        }

        $payload = json_decode(trim((string) ($result['stdout'] ?? '')), true);

        if (!is_array($payload) || !array_key_exists('custfoot', $payload)) {
            return null;
        }

        return (string) $payload['custfoot'];
    }

    private function getPhpBinary(): string
    {
        foreach ([PHP_BINARY, '/usr/local/bin/php', '/usr/bin/php', '/opt/cpanel/ea-php84/root/usr/bin/php', 'php'] as $candidate) {
            if (
                $candidate === 'php'
                || (
                    is_string($candidate)
                    && $candidate !== ''
                    && is_executable($candidate)
                    && !preg_match('#(?:php-cgi|lsphp|php-fpm)$#i', $candidate)
                )
            ) {
                return $candidate;
            }
        }

        return 'php';
    }

    private function runCliCommand(array $command, bool $allowFailure = false): array
    {
        if (!$this->canExecuteCommands()) {
            return ['stdout' => '', 'stderr' => '', 'exit_code' => 1];
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, JPATH_ROOT);

        if (!is_resource($process)) {
            return ['stdout' => '', 'stderr' => '', 'exit_code' => 1];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 && !$allowFailure) {
            throw new \RuntimeException(trim((string) $stderr) !== '' ? trim((string) $stderr) : 'CLI command failed.');
        }

        return [
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
            'exit_code' => (int) $exitCode,
        ];
    }

    private function canExecuteCommands(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists('proc_open') && !in_array('proc_open', $disabled, true);
    }
}

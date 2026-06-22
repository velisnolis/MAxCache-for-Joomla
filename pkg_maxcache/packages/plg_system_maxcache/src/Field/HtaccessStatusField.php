<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Field;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Factory;
use Vendor\Plugin\System\Maxcache\Support\AdminToolsManager;
use Vendor\Plugin\System\Maxcache\Support\BuiltInExclusions;
use Vendor\Plugin\System\Maxcache\Support\HtaccessManager;
use Vendor\Plugin\System\Maxcache\Support\RegexPatternMatcher;
use Vendor\Plugin\System\Maxcache\Support\ServerCapabilityDetector;
use Vendor\Plugin\System\Maxcache\Support\SiteHostDetector;
use Vendor\Plugin\System\Maxcache\Support\SnippetBuilder;
use Vendor\Plugin\System\Maxcache\Support\SystemCacheStatus;
use Vendor\Plugin\System\Maxcache\Support\SystemCacheSettings;

\defined('_JEXEC') or die;

final class HtaccessStatusField extends FormField
{
    protected $type = 'HtaccessStatus';

    protected function getInput(): string
    {
        $mode = (string) $this->form->getValue('server_snippet_mode', 'params', 'mod_maxcache');
        $params = [
            'cache_root' => $this->form->getValue('cache_root', 'params'),
            'site_hosts' => implode("\n", SiteHostDetector::detect((string) $this->form->getValue('site_hosts', 'params'))),
            'exclude' => implode("\n", SystemCacheSettings::mergeUrlPatterns(BuiltInExclusions::filterCustomPatterns(
                $this->normalizeLineList((string) $this->form->getValue('exclude', 'params'))
            ))),
            'bypass_cookies' => $this->form->getValue('bypass_cookies', 'params'),
            ...$this->getJoomlaCookieSnippetParams(),
            'allowed_query_params' => $this->form->getValue('allowed_query_params', 'params'),
            'write_gzip' => (int) $this->form->getValue('write_gzip', 'params', 0),
        ];

        $snippet = SnippetBuilder::build($mode, $params);
        $adminToolsAvailable = AdminToolsManager::isAvailable();
        $status = $adminToolsAvailable
            ? AdminToolsManager::getStatus($snippet)
            : HtaccessManager::getStatus($snippet);
        $modMaxcache = ServerCapabilityDetector::detectModMaxcache();

        $stateLabel = match ($status['state']) {
            'applied' => 'Applied',
            'outdated' => 'Applied snippet is outdated',
            'unknown' => 'Could not verify current apply state',
            default => 'Snippet not applied',
        };

        $html = [];
        $guestSessionWarning = $this->getGuestSessionTrackingWarning();
        $systemCacheStatus = SystemCacheStatus::getStatus();
        $invalidExclusions = RegexPatternMatcher::findInvalid($this->getEffectiveCustomExcludePatterns());

        if ($guestSessionWarning !== null) {
            $html[] = '<div class="alert alert-danger">';
            $html[] = '<p><strong>Guest-page caching warning:</strong> ' . htmlspecialchars($guestSessionWarning, ENT_QUOTES, 'UTF-8') . '</p>';
            $html[] = '<p><strong>Action needed:</strong> Disable <code>Guest Session Tracking</code> in Joomla Global Configuration if you want MAx Cache to serve static cache consistently to anonymous visitors.</p>';
            $html[] = '</div>';
        }

        if (($systemCacheStatus['enabled'] ?? false) === true) {
            $html[] = '<div class="alert alert-warning">';
            $html[] = '<p><strong>System - Cache conflict:</strong> Joomla System - Cache is enabled and can serve full pages before MAx Cache runs.</p>';
            $html[] = '<p><strong>Action needed:</strong> Disable <code>System - Cache</code>. MAx Cache will still inherit its saved URL and menu exclusions from the plugin configuration.</p>';
            $html[] = '</div>';
        }

        if ($invalidExclusions !== []) {
            $html[] = '<div class="alert alert-warning">';
            $html[] = '<p><strong>Invalid exclusion regex:</strong> These patterns are ignored by MAx Cache until fixed:</p>';
            $html[] = '<ul>';

            foreach ($invalidExclusions as $pattern) {
                $html[] = '<li><code>' . htmlspecialchars($pattern, ENT_QUOTES, 'UTF-8') . '</code></li>';
            }

            $html[] = '</ul>';
            $html[] = '</div>';
        }

        $html[] = '<div class="alert alert-info">';
        $html[] = '<p><strong>mod_maxcache:</strong> ' . htmlspecialchars($this->buildModMaxcacheMessage($modMaxcache), ENT_QUOTES, 'UTF-8') . '</p>';
        $html[] = '<p><strong>Apply target:</strong> ' . htmlspecialchars($adminToolsAvailable ? 'Akeeba Admin Tools custom footer + .htaccess rebuild' : 'Direct .htaccess managed block', ENT_QUOTES, 'UTF-8') . '</p>';
        $html[] = '<p><strong>.htaccess status:</strong> ' . htmlspecialchars($stateLabel, ENT_QUOTES, 'UTF-8') . '</p>';
        if ($mode === 'mod_maxcache' && (int) $params['write_gzip'] === 0) {
            $html[] = '<p><strong>Cloudflare:</strong> Proxied setups are supported. When precompressed artifacts are disabled, MAx Cache omits gzip-specific path suffixes automatically.</p>';
        }

        if ($adminToolsAvailable) {
            $html[] = '<p><strong>Admin Tools:</strong> Detected. Apply writes the managed MAx Cache block into "Custom .htaccess rules at the bottom of the file", adds the public cache path to Server Protection exceptions, and then rebuilds .htaccess.</p>';
        } elseif (($status['akeeba_detected'] ?? false) === true) {
            $html[] = '<p><strong>Warning:</strong> Akeeba Admin Tools markers were detected in the current .htaccess. Keep apply/manual actions explicit and verify rule order before changing server config.</p>';
        }

        if ($status['applied_hash']) {
            $html[] = '<p><strong>Applied hash:</strong> <code>' . htmlspecialchars($status['applied_hash'], ENT_QUOTES, 'UTF-8') . '</code></p>';
        }

        $html[] = '<p><strong>Expected hash:</strong> <code>' . htmlspecialchars($status['expected_hash'], ENT_QUOTES, 'UTF-8') . '</code></p>';

        $lastApply = Factory::getApplication()->getUserState('plg_system_maxcache.last_apply_result');

        if (is_array($lastApply) && !empty($lastApply['message'])) {
            $html[] = '<hr>';
            $html[] = '<p><strong>Last apply result:</strong> ' . htmlspecialchars((string) $lastApply['message'], ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $lastPurge = Factory::getApplication()->getUserState('plg_system_maxcache.last_purge_result');

        if (is_array($lastPurge) && !empty($lastPurge['message'])) {
            $html[] = '<p><strong>Last purge result:</strong> ' . htmlspecialchars((string) $lastPurge['message'], ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $html[] = '<p>This action remains explicit. Saving the plugin never rewrites server config automatically.</p>';
        $html[] = '</div>';

        return implode('', $html);
    }

    private function buildModMaxcacheMessage(array $status): string
    {
        return match ($status['state'] ?? 'unknown') {
            'detected' => 'Detected on this server. CloudLinux mod_maxcache is the recommended snippet mode.',
            'configured' => 'Configured on this server, but Joomla could not verify the loaded Apache modules directly. CloudLinux mod_maxcache is likely available.',
            'not_detected' => 'Not detected from Joomla. Use Apache Rewrite unless your host confirms CloudLinux mod_maxcache is enabled.',
            default => 'Could not be verified from Joomla. Use Apache Rewrite unless your host confirms CloudLinux mod_maxcache is enabled.',
        };
    }

    private function getGuestSessionTrackingWarning(): ?string
    {
        try {
            $config = Factory::getConfig();

            if (!(int) $config->get('session_metadata_for_guest', 0)) {
                return null;
            }

            $sessionName = (string) Factory::getApplication()->getSession()->getName();
            $cookieLabel = $sessionName !== '' ? ' (' . $sessionName . ')' : '';

            return 'Joomla guest session tracking is enabled in Global Configuration. Anonymous visitors may receive the Joomla session cookie'
                . $cookieLabel
                . ', and MAx Cache will bypass those requests. Disable guest session tracking if you want full guest-page caching.';
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function getEffectiveCustomExcludePatterns(): array
    {
        return SystemCacheSettings::mergeUrlPatterns(BuiltInExclusions::filterCustomPatterns(
            $this->normalizeLineList((string) $this->form->getValue('exclude', 'params'))
        ));
    }

    private function getJoomlaCookieSnippetParams(): array
    {
        try {
            $config = Factory::getConfig();

            return [
                'joomla_secret' => (string) $config->get('secret', ''),
                'joomla_session_name' => (string) $config->get('session_name', ''),
            ];
        } catch (\Throwable $exception) {
            return [
                'joomla_secret' => '',
                'joomla_session_name' => '',
            ];
        }
    }

    private function normalizeLineList(string $value): array
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = array_map('trim', explode("\n", $value));

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }
}

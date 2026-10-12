<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

use Joomla\CMS\Factory;

\defined('_JEXEC') or die;

/**
 * Turns saved plugin settings into the server snippet. Admin apply, admin status,
 * field previews and the console must all build it here: any difference between
 * them makes a freshly applied snippet look outdated from another client.
 */
final class SnippetParams
{
    public const DEFAULT_CACHE_ROOT = '/var/cache/joomla-maxcache';

    /** Saved plugin settings that influence the generated snippet. */
    public const SETTING_KEYS = [
        'server_snippet_mode',
        'cache_root',
        'site_hosts',
        'exclude',
        'bypass_cookies',
        'tracking_query_params',
        'write_gzip',
    ];

    public static function buildSnippet(array $settings, ?string $mode = null): string
    {
        return SnippetBuilder::build(
            $mode ?? (string) ($settings['server_snippet_mode'] ?? 'mod_maxcache'),
            self::fromSettings($settings)
        );
    }

    public static function fromSettings(array $settings): array
    {
        return [
            'cache_root' => (string) ($settings['cache_root'] ?? self::DEFAULT_CACHE_ROOT),
            // Saved hosts only: detection depends on the current request and
            // returns different hosts in the console.
            'site_hosts' => (string) ($settings['site_hosts'] ?? ''),
            'exclude' => implode("\n", self::effectiveExclusions((string) ($settings['exclude'] ?? ''))),
            'bypass_cookies' => (string) ($settings['bypass_cookies'] ?? ''),
            ...self::joomlaCookieParams(),
            'tracking_query_params' => (string) ($settings['tracking_query_params'] ?? ''),
            'write_gzip' => (int) ($settings['write_gzip'] ?? 0),
        ];
    }

    public static function effectiveExclusions(string $exclude): array
    {
        $exclude = str_replace(["\r\n", "\r"], "\n", $exclude);
        $patterns = array_values(array_filter(
            array_map('trim', explode("\n", $exclude)),
            static fn (string $line): bool => $line !== ''
        ));

        return SystemCacheSettings::mergeUrlPatterns(BuiltInExclusions::filterCustomPatterns($patterns));
    }

    private static function joomlaCookieParams(): array
    {
        try {
            $config = Factory::getConfig();
            $secret = (string) $config->get('secret', '');
            $sessionName = (string) $config->get('session_name', '');
        } catch (\Throwable $exception) {
            $secret = '';
            $sessionName = '';
        }

        return [
            'joomla_secret' => $secret,
            'joomla_session_name' => $sessionName,
            'joomla_session_cookie_names' => BypassCookieNames::factorySessionCookieNames(),
        ];
    }
}

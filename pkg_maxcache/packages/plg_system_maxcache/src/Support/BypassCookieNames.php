<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

use Joomla\CMS\Factory;

\defined('_JEXEC') or die;

final class BypassCookieNames
{
    /**
     * @param string[] $configured
     *
     * @return string[]
     */
    public static function mergeWithJoomlaSessionCookies(array $configured, string $secret = '', string $sessionName = ''): array
    {
        $cookies = array_merge($configured, self::joomlaSessionCookieNames($secret, $sessionName));

        return self::normalize($cookies);
    }

    /**
     * @param string[] $configured
     *
     * @return string[]
     */
    public static function mergeWithFactoryConfig(array $configured): array
    {
        try {
            $config = Factory::getConfig();

            return self::mergeWithJoomlaSessionCookies(
                $configured,
                (string) $config->get('secret', ''),
                (string) $config->get('session_name', '')
            );
        } catch (\Throwable $exception) {
            return self::normalize($configured);
        }
    }

    /**
     * @return string[]
     */
    public static function joomlaSessionCookieNames(string $secret, string $sessionName = ''): array
    {
        if ($secret === '') {
            return [];
        }

        $seeds = [
            $sessionName,
            'Joomla\\CMS\\Application\\SiteApplication',
        ];

        return self::normalize(array_map(
            static fn (string $seed): string => $seed !== '' ? md5($secret . $seed) : '',
            $seeds
        ));
    }

    /**
     * @param string[] $cookies
     *
     * @return string[]
     */
    public static function normalize(array $cookies): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($cookie): string => trim((string) $cookie), $cookies),
            static fn (string $cookie): bool => $cookie !== ''
        )));
    }
}

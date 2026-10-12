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
    public static function mergeWithJoomlaSessionCookies(
        array $configured,
        string $secret = '',
        string $sessionName = '',
        array $sessionCookieNames = []
    ): array
    {
        $cookies = array_merge(
            $configured,
            $sessionCookieNames,
            self::joomlaSessionCookieNames($secret, $sessionName)
        );

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
    public static function factorySessionCookieNames(): array
    {
        $names = [];

        // Only read the current session. Constructing another client's service
        // shares config and PHP session state, so it cannot discover that
        // client's effective cookie reliably and may alter the current session.
        try {
            $application = Factory::getApplication();

            // Console uses RuntimeStorage, not an HTTP cookie. Including its
            // session name makes CLI status disagree with a web-applied block.
            if (is_object($application) && method_exists($application, 'isClient') && $application->isClient('cli')) {
                return [];
            }

            if (is_object($application) && method_exists($application, 'getSession')) {
                $session = $application->getSession();

                if (is_object($session) && method_exists($session, 'getName')) {
                    $names[] = (string) $session->getName();
                }
            }
        } catch (\Throwable $exception) {
            // The application session is best-effort; hash fallback still covers standard Joomla installs.
        }

        return self::normalize($names);
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
            'site',
            'administrator',
            'Joomla\\CMS\\Application\\SiteApplication',
            'Joomla\\CMS\\Application\\AdministratorApplication',
        ];

        // Joomla 5/6's provider hashes secret + configured/client name; CMS
        // Session hashes it again for Joomla 3 compatibility. Include both
        // clients independently of the application generating the snippet.
        // Class seeds cover session services resolved before CMS app setup.
        return self::normalize(array_map(
            static fn (string $seed): string => $seed !== '' ? md5(md5($secret . $seed)) : '',
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

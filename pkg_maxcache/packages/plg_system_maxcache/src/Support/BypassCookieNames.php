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

        try {
            $container = Factory::getContainer();

            foreach (['session.web.site', 'session.web.administrator'] as $serviceName) {
                try {
                    if (method_exists($container, 'has') && !$container->has($serviceName)) {
                        continue;
                    }

                    $session = $container->get($serviceName);

                    if (is_object($session) && method_exists($session, 'getName')) {
                        $names[] = (string) $session->getName();
                    }
                } catch (\Throwable $exception) {
                    continue;
                }
            }
        } catch (\Throwable $exception) {
            // Joomla can be partially bootstrapped when field previews are rendered.
        }

        try {
            $application = Factory::getApplication();

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

<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

use Joomla\CMS\Factory;

\defined('_JEXEC') or die;

final class JoomlaCacheCleaner
{
    public static function clean(): array
    {
        $results = [];

        if (!\is_callable([Factory::class, 'getCache'])) {
            return $results;
        }

        foreach (self::targets() as $target) {
            [$group, $handler] = $target;

            try {
                $cache = Factory::getCache($group, $handler);
                $cleaned = \is_object($cache) && method_exists($cache, 'clean')
                    ? (bool) $cache->clean()
                    : false;

                $results[] = [
                    'group' => $group,
                    'handler' => $handler,
                    'cleaned' => $cleaned,
                ];
            } catch (\Throwable $exception) {
                $results[] = [
                    'group' => $group,
                    'handler' => $handler,
                    'cleaned' => false,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $results;
    }

    private static function targets(): array
    {
        return [
            ['', 'callback'],
            ['page', 'output'],
            ['_system', 'callback'],
            ['com_plugins', 'callback'],
            ['plg_system_maxcache', 'callback'],
        ];
    }
}

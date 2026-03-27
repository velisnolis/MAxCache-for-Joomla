<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

\defined('_JEXEC') or die;

final class ServerCapabilityDetector
{
    public static function detectModMaxcache(): array
    {
        if (\function_exists('apache_get_modules')) {
            $modules = array_map('strtolower', apache_get_modules());

            if (\in_array('maxcache_module', $modules, true)) {
                return [
                    'state' => 'detected',
                    'source' => 'apache_get_modules',
                ];
            }
        }

        foreach ([
            'apache2ctl -M 2>/dev/null',
            'apachectl -M 2>/dev/null',
            'httpd -M 2>/dev/null',
            '/usr/sbin/httpd -M 2>/dev/null',
        ] as $command) {
            $output = @shell_exec($command);

            if (!\is_string($output) || trim($output) === '') {
                continue;
            }

            if (stripos($output, 'maxcache_module') !== false) {
                return [
                    'state' => 'detected',
                    'source' => trim(strtok($command, ' ')),
                ];
            }

            return [
                'state' => 'not_detected',
                'source' => trim(strtok($command, ' ')),
            ];
        }

        foreach ([
            '/etc/apache2/conf.modules.d/mod_maxcache.conf',
            '/etc/httpd/conf.modules.d/mod_maxcache.conf',
            '/etc/apache2/conf.modules.d/999-maxcache.conf',
            '/etc/httpd/conf.modules.d/999-maxcache.conf',
        ] as $configPath) {
            if (!is_readable($configPath)) {
                continue;
            }

            $contents = @file_get_contents($configPath);

            if (\is_string($contents) && stripos($contents, 'LoadModule maxcache_module') !== false) {
                return [
                    'state' => 'configured',
                    'source' => $configPath,
                ];
            }
        }

        foreach ([
            '/etc/apache2/modules/mod_maxcache.so',
            '/usr/lib64/apache2/modules/mod_maxcache.so',
            '/usr/lib64/httpd/modules/mod_maxcache.so',
        ] as $modulePath) {
            if (is_readable($modulePath)) {
                return [
                    'state' => 'configured',
                    'source' => $modulePath,
                ];
            }
        }

        return [
            'state' => 'unknown',
            'source' => null,
        ];
    }
}

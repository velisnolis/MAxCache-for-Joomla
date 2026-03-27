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

            return [
                'state' => 'not_detected',
                'source' => 'apache_get_modules',
            ];
        }

        foreach (['apachectl -M 2>/dev/null', 'httpd -M 2>/dev/null'] as $command) {
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

        return [
            'state' => 'unknown',
            'source' => null,
        ];
    }
}

<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

\defined('_JEXEC') or die;

final class AtomicFileWriter
{
    public static function write(string $path, string $contents, int $mode = 0644): bool
    {
        $directory = \dirname($path);

        if (!is_dir($directory) || !is_writable($directory)) {
            return false;
        }

        if (is_file($path)) {
            $permissions = fileperms($path);

            if ($permissions !== false) {
                $mode = $permissions & 0777;
            }
        }

        $temporary = $directory
            . '/.'
            . basename($path)
            . '.'
            . getmypid()
            . '.'
            . str_replace('.', '', uniqid('', true))
            . '.tmp';

        $bytes = @file_put_contents($temporary, $contents, LOCK_EX);

        if ($bytes === false || $bytes !== strlen($contents)) {
            if (is_file($temporary)) {
                @unlink($temporary);
            }

            return false;
        }

        @chmod($temporary, $mode);

        if (!@rename($temporary, $path)) {
            if (is_file($temporary)) {
                @unlink($temporary);
            }

            return false;
        }

        return true;
    }
}

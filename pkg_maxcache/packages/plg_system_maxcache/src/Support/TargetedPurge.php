<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

\defined('_JEXEC') or die;

/**
 * Removes the cache directories of specific public paths under every known host.
 */
final class TargetedPurge
{
    /**
     * @param string[] $hosts
     * @param string[] $paths Public paths; "/" removes the whole host directory.
     */
    public static function purgePaths(string $root, array $hosts, array $paths): void
    {
        $root = rtrim($root, '/');

        if ($root === '' || !is_dir($root)) {
            return;
        }

        foreach ($paths as $path) {
            $segments = array_values(array_filter(explode('/', trim((string) $path, '/')), 'strlen'));

            foreach ($hosts as $host) {
                $directory = $root . '/' . CachePathBuilder::sanitizePathSegment($host);

                foreach ($segments as $segment) {
                    $directory .= '/' . CachePathBuilder::sanitizePathSegment($segment);
                }

                if (is_dir($directory)) {
                    DirectoryOperations::delete($directory);
                }
            }
        }
    }

    /**
     * Fallback when no path could be resolved: every directory named like the alias.
     */
    public static function purgeAlias(string $root, string $alias): void
    {
        $root = rtrim($root, '/');

        if ($root === '' || !is_dir($root)) {
            return;
        }

        $safeAlias = CachePathBuilder::sanitizePathSegment($alias);

        foreach (DirectoryOperations::listDirectories($root) as $directory) {
            if (basename($directory) === $safeAlias) {
                DirectoryOperations::delete($directory);
            }
        }
    }
}

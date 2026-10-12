<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

\defined('_JEXEC') or die;

final class DirectoryOperations
{
    public static function ensure(string $path, int $permissions = 0750): bool
    {
        return is_dir($path) || (@mkdir($path, $permissions, true) && is_dir($path));
    }

    public static function ensurePublicCache(string $path): bool
    {
        // Apache may read as a different user from PHP. A restrictive PHP umask
        // must not remove traversal permissions from new public cache paths.
        $originalUmask = umask(0);

        try {
            return self::ensure($path, 0755);
        } finally {
            umask($originalUmask);
        }
    }

    /**
     * @return list<string>
     */
    public static function listDirectories(string $root): array
    {
        if (!is_dir($root) || is_link($root)) {
            return [];
        }

        $directories = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isDir() && !$item->isLink()) {
                    $directories[] = $item->getPathname();
                }
            }
        } catch (\UnexpectedValueException $exception) {
            return $directories;
        }

        return $directories;
    }

    public static function delete(string $path): bool
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }

        if (!is_dir($path)) {
            return true;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                $itemPath = $item->getPathname();

                if ($item->isLink() || $item->isFile()) {
                    if (!@unlink($itemPath)) {
                        return false;
                    }

                    continue;
                }

                if (!@rmdir($itemPath)) {
                    return false;
                }
            }
        } catch (\UnexpectedValueException $exception) {
            return false;
        }

        return @rmdir($path);
    }
}

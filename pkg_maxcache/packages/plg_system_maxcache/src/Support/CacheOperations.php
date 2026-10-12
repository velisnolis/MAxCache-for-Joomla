<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

\defined('_JEXEC') or die;

final class CacheOperations
{
    /**
     * @return array{exists: bool, files: int, bytes: int}
     */
    public static function stats(string $path): array
    {
        $root = self::normalizeRoot($path);

        if ($root === '' || !is_dir($root) || is_link($root)) {
            return [
                'exists' => false,
                'files' => 0,
                'bytes' => 0,
            ];
        }

        $files = 0;
        $bytes = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $item) {
                if (!$item->isFile() || $item->isLink()) {
                    continue;
                }

                ++$files;
                $bytes += max(0, (int) $item->getSize());
            }
        } catch (\UnexpectedValueException $exception) {
            // Return the readable portion instead of making status unusable.
        }

        return [
            'exists' => true,
            'files' => $files,
            'bytes' => $bytes,
        ];
    }

    /**
     * @return array{files: int, samples: list<string>, unreadable: int}
     */
    public static function joomlaFormTokenArtifacts(string $path, int $sampleLimit = 5): array
    {
        $root = self::normalizeRoot($path);
        $result = [
            'files' => 0,
            'samples' => [],
            'unreadable' => 0,
        ];

        if ($root === '' || !is_dir($root) || is_link($root)) {
            return $result;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $item) {
                if (
                    !$item->isFile()
                    || $item->isLink()
                    || !str_ends_with(strtolower($item->getFilename()), '.html')
                ) {
                    continue;
                }

                $body = @file_get_contents($item->getPathname());

                if ($body === false) {
                    ++$result['unreadable'];
                    continue;
                }

                if (!ResponseSafety::containsJoomlaFormToken($body) && !ResponseSafety::containsScriptOptionToken($body)) {
                    continue;
                }

                ++$result['files'];

                if (\count($result['samples']) < max(0, $sampleLimit)) {
                    $result['samples'][] = ltrim(substr($item->getPathname(), \strlen($root)), '/');
                }
            }
        } catch (\UnexpectedValueException $exception) {
            ++$result['unreadable'];
        }

        return $result;
    }

    /**
     * @return array{root: string, removed: bool, files: int, bytes: int}
     */
    public static function purge(string $path, ?string $siteRoot = null): array
    {
        $root = self::normalizeRoot($path);

        if (!self::isSafeRoot($root, $siteRoot)) {
            throw new \RuntimeException('Refusing to purge unsafe MAx Cache root: ' . ($root !== '' ? $root : '(empty)'));
        }

        $before = self::stats($root);

        if (!$before['exists']) {
            return [
                'root' => $root,
                'removed' => false,
                'files' => 0,
                'bytes' => 0,
            ];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $pathName = $item->getPathname();

            if ($item->isLink() || $item->isFile()) {
                if (!@unlink($pathName)) {
                    throw new \RuntimeException('Could not remove MAx Cache file: ' . $pathName);
                }

                continue;
            }

            if (!@rmdir($pathName)) {
                throw new \RuntimeException('Could not remove MAx Cache directory: ' . $pathName);
            }
        }

        if (!@rmdir($root)) {
            throw new \RuntimeException('Could not remove MAx Cache root: ' . $root);
        }

        return [
            'root' => $root,
            'removed' => true,
            'files' => $before['files'],
            'bytes' => $before['bytes'],
        ];
    }

    public static function isSafeRoot(string $path, ?string $siteRoot = null): bool
    {
        $root = self::normalizeRoot($path);

        if ($root === '' || !str_starts_with($root, '/') || is_link($root)) {
            return false;
        }

        $resolved = realpath($root);
        $candidate = $resolved !== false && $resolved !== '/' ? rtrim($resolved, '/') : $root;
        $dangerous = [
            '/',
            '/tmp',
            '/var',
            '/etc',
            '/home',
            '/root',
            '/usr',
            '/bin',
            '/sbin',
            '/opt',
            '/boot',
            '/dev',
            '/proc',
            '/sys',
            '/run',
            '/var/log',
        ];

        if (\in_array($candidate, $dangerous, true)) {
            return false;
        }

        if ($siteRoot !== null && $siteRoot !== '') {
            $resolvedSiteRoot = realpath($siteRoot);

            if ($resolvedSiteRoot !== false && $candidate === rtrim($resolvedSiteRoot, '/')) {
                return false;
            }
        }

        return true;
    }

    private static function normalizeRoot(string $path): string
    {
        $path = trim($path);

        if ($path === '/') {
            return '/';
        }

        return rtrim($path, '/');
    }
}

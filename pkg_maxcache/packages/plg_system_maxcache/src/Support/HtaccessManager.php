<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

use Joomla\CMS\Date\Date;
use Joomla\CMS\Filesystem\Folder;

\defined('_JEXEC') or die;

final class HtaccessManager
{
    public const BEGIN_MARKER = '# BEGIN MAx Cache for Joomla';
    public const END_MARKER = '# END MAx Cache for Joomla';

    public static function getHtaccessPath(): string
    {
        return JPATH_ROOT . '/.htaccess';
    }

    public static function buildManagedBlock(string $snippet): string
    {
        return self::BEGIN_MARKER . "\n"
            . '# hash: ' . self::buildHash($snippet) . "\n"
            . trim($snippet) . "\n"
            . self::END_MARKER;
    }

    public static function buildHash(string $snippet): string
    {
        return hash('sha256', trim($snippet));
    }

    public static function readCurrentContents(): string
    {
        $path = self::getHtaccessPath();

        if (!is_file($path)) {
            return '';
        }

        return (string) file_get_contents($path);
    }

    public static function getManagedBlock(string $contents): ?string
    {
        if (!preg_match('/' . preg_quote(self::BEGIN_MARKER, '/') . '(.*?)' . preg_quote(self::END_MARKER, '/') . '/s', $contents, $matches)) {
            return null;
        }

        return trim(self::BEGIN_MARKER . $matches[1] . self::END_MARKER);
    }

    public static function getManagedHash(string $contents): ?string
    {
        if (!preg_match('/^\# hash:\s*([a-f0-9]{64})$/mi', $contents, $matches)) {
            return null;
        }

        return strtolower($matches[1]);
    }

    public static function detectAkeebaManagedHtaccess(string $contents): bool
    {
        $needles = [
            'Akeeba Admin Tools',
            '### AKEEBA',
            '.htaccess Maker',
            'admintools',
        ];

        $haystack = strtolower($contents);

        foreach ($needles as $needle) {
            if (str_contains($haystack, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    public static function getStatus(string $snippet): array
    {
        $contents = self::readCurrentContents();
        $block = self::getManagedBlock($contents);
        $expectedHash = self::buildHash($snippet);
        $appliedHash = $block !== null ? self::getManagedHash($block) : null;
        $akeebaDetected = self::detectAkeebaManagedHtaccess($contents);

        if ($block === null) {
            return [
                'state' => 'not_applied',
                'expected_hash' => $expectedHash,
                'applied_hash' => null,
                'akeeba_detected' => $akeebaDetected,
            ];
        }

        return [
            'state' => $appliedHash === $expectedHash ? 'applied' : 'outdated',
            'expected_hash' => $expectedHash,
            'applied_hash' => $appliedHash,
            'akeeba_detected' => $akeebaDetected,
        ];
    }

    public static function buildBackupPath(): string
    {
        $date = (new Date())->format('Ymd-His');

        return self::getBackupDirectory() . '/htaccess.' . $date . '.bak';
    }

    private static function getBackupDirectory(): string
    {
        $dir = JPATH_ROOT . '/administrator/cache/maxcache-backups';

        if (!is_dir($dir)) {
            Folder::create($dir);
        }

        return $dir;
    }

    public static function applySnippet(string $snippet): array
    {
        $path = self::getHtaccessPath();
        $managedBlock = self::buildManagedBlock($snippet);
        $current = self::readCurrentContents();
        $backupPath = null;

        if ($current !== '') {
            $backupPath = self::buildBackupPath();
            if (!@copy($path, $backupPath)) {
                throw new \RuntimeException('Could not create .htaccess backup at ' . $backupPath . '.');
            }
        }

        $existingBlock = self::getManagedBlock($current);

        if ($existingBlock !== null) {
            $updated = str_replace($existingBlock, $managedBlock, $current);
        } elseif ($current === '') {
            $updated = $managedBlock . "\n";
        } else {
            $updated = rtrim($current) . "\n\n" . $managedBlock . "\n";
        }

        $bytes = @file_put_contents($path, $updated);

        if ($bytes === false) {
            throw new \RuntimeException('Could not write the managed MAx Cache block to ' . $path . '. Check file ownership and permissions.');
        }

        $written = self::readCurrentContents();

        if (self::getManagedHash((string) self::getManagedBlock($written)) !== self::buildHash($snippet)) {
            throw new \RuntimeException('The managed MAx Cache block could not be verified after writing ' . $path . '.');
        }

        return [
            'path' => $path,
            'backup_path' => $backupPath,
            'hash' => self::buildHash($snippet),
        ];
    }

    public static function removeManagedBlock(): ?string
    {
        $path = self::getHtaccessPath();
        $current = self::readCurrentContents();
        $updated = self::removeManagedBlockFromContents($current);

        if ($updated === null) {
            return null;
        }

        $backupPath = null;

        if ($current !== '') {
            $backupPath = self::buildBackupPath();
            if (!@copy($path, $backupPath)) {
                throw new \RuntimeException('Could not create .htaccess backup at ' . $backupPath . '.');
            }
        }

        $bytes = @file_put_contents($path, $updated);

        if ($bytes === false) {
            throw new \RuntimeException('Could not remove the managed MAx Cache block from ' . $path . '. Check file ownership and permissions.');
        }

        return $backupPath;
    }

    public static function removeManagedBlockFromContents(string $contents): ?string
    {
        $block = self::getManagedBlock($contents);

        if ($block === null) {
            return null;
        }

        $updated = str_replace($block, '', $contents);
        $updated = preg_replace("/\n{3,}/", "\n\n", (string) $updated);

        return rtrim((string) $updated) . "\n";
    }
}

<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

use Joomla\CMS\Date\Date;

\defined('_JEXEC') or die;

final class AdminToolsManager
{
    private const OPTION_KEY = 'custfoot';

    public static function isAvailable(): bool
    {
        return is_dir(JPATH_ADMINISTRATOR . '/components/com_admintools')
            && is_file(JPATH_ROOT . '/cli/joomla.php');
    }

    public static function getStatus(string $snippet): array
    {
        $expectedHash = HtaccessManager::buildHash($snippet);
        $htaccessStatus = HtaccessManager::getStatus($snippet);

        if (!self::isAvailable()) {
            return [
                'available' => false,
                'state' => 'unavailable',
                'expected_hash' => $expectedHash,
                'applied_hash' => null,
                'target' => 'htaccess',
                'htaccess_state' => $htaccessStatus['state'],
            ];
        }

        $currentFooter = self::getCurrentFooter();

        if ($currentFooter === null) {
            return [
                'available' => true,
                'state' => 'unknown',
                'expected_hash' => $expectedHash,
                'applied_hash' => null,
                'target' => 'admintools',
                'htaccess_state' => $htaccessStatus['state'],
            ];
        }

        $block = HtaccessManager::getManagedBlock($currentFooter);
        $appliedHash = $block !== null ? HtaccessManager::getManagedHash($block) : null;

        if ($block === null) {
            return [
                'available' => true,
                'state' => 'not_applied',
                'expected_hash' => $expectedHash,
                'applied_hash' => null,
                'target' => 'admintools',
                'htaccess_state' => $htaccessStatus['state'],
            ];
        }

        return [
            'available' => true,
            'state' => $appliedHash === $expectedHash ? 'applied' : 'outdated',
            'expected_hash' => $expectedHash,
            'applied_hash' => $appliedHash,
            'target' => 'admintools',
            'htaccess_state' => $htaccessStatus['state'],
        ];
    }

    public static function applySnippet(string $snippet): array
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException('Admin Tools is not available on this Joomla installation.');
        }

        $currentFooter = self::getCurrentFooter();

        if ($currentFooter === null) {
            throw new \RuntimeException('Could not read the current Admin Tools .htaccess footer.');
        }

        $managedBlock = HtaccessManager::buildManagedBlock($snippet);
        $existingBlock = HtaccessManager::getManagedBlock($currentFooter);

        if ($existingBlock !== null) {
            $updatedFooter = str_replace($existingBlock, $managedBlock, $currentFooter);
        } elseif (trim($currentFooter) === '') {
            $updatedFooter = $managedBlock . "\n";
        } else {
            $updatedFooter = rtrim($currentFooter) . "\n\n" . $managedBlock . "\n";
        }

        $footerBackup = self::buildFooterBackupPath();
        @file_put_contents($footerBackup, $currentFooter);

        $htaccessBackup = null;
        $htaccessPath = HtaccessManager::getHtaccessPath();

        if (is_file($htaccessPath)) {
            $htaccessBackup = HtaccessManager::buildBackupPath();
            @copy($htaccessPath, $htaccessBackup);
        }

        self::runCliCommand([
            self::getPhpBinary(),
            JPATH_ROOT . '/cli/joomla.php',
            'admintools:htmaker:set',
            '--key=' . self::OPTION_KEY,
            '--value=' . $updatedFooter,
        ]);

        self::runCliCommand([
            self::getPhpBinary(),
            JPATH_ROOT . '/cli/joomla.php',
            'admintools:htmaker:make',
        ]);

        return [
            'target' => 'admintools',
            'hash' => HtaccessManager::buildHash($snippet),
            'backup_path' => $htaccessBackup,
            'footer_backup_path' => $footerBackup,
        ];
    }

    public static function removeManagedBlock(): array
    {
        if (!self::isAvailable()) {
            return [
                'removed' => false,
                'backup_path' => null,
                'footer_backup_path' => null,
            ];
        }

        $currentFooter = self::getCurrentFooter();

        if ($currentFooter === null) {
            return [
                'removed' => false,
                'backup_path' => null,
                'footer_backup_path' => null,
            ];
        }

        $updatedFooter = HtaccessManager::removeManagedBlockFromContents($currentFooter);

        if ($updatedFooter === null) {
            return [
                'removed' => false,
                'backup_path' => null,
                'footer_backup_path' => null,
            ];
        }

        $footerBackup = self::buildFooterBackupPath();
        @file_put_contents($footerBackup, $currentFooter);

        $htaccessBackup = null;
        $htaccessPath = HtaccessManager::getHtaccessPath();

        if (is_file($htaccessPath)) {
            $htaccessBackup = HtaccessManager::buildBackupPath();
            @copy($htaccessPath, $htaccessBackup);
        }

        self::runCliCommand([
            self::getPhpBinary(),
            JPATH_ROOT . '/cli/joomla.php',
            'admintools:htmaker:set',
            '--key=' . self::OPTION_KEY,
            '--value=' . $updatedFooter,
        ]);

        self::runCliCommand([
            self::getPhpBinary(),
            JPATH_ROOT . '/cli/joomla.php',
            'admintools:htmaker:make',
        ]);

        return [
            'removed' => true,
            'backup_path' => $htaccessBackup,
            'footer_backup_path' => $footerBackup,
        ];
    }

    private static function getCurrentFooter(): ?string
    {
        $result = self::runCliCommand([
            self::getPhpBinary(),
            JPATH_ROOT . '/cli/joomla.php',
            'admintools:htmaker:get',
            '--option=' . self::OPTION_KEY,
        ], true);

        if ($result['exit_code'] !== 0) {
            return null;
        }

        $payload = json_decode(trim($result['stdout']), true);

        if (!is_array($payload) || !array_key_exists(self::OPTION_KEY, $payload)) {
            return null;
        }

        return (string) $payload[self::OPTION_KEY];
    }

    private static function buildFooterBackupPath(): string
    {
        $date = (new Date())->format('Ymd-His');

        return JPATH_ROOT . '/.htaccess.maxcache-admintools-footer.' . $date . '.bak';
    }

    private static function getPhpBinary(): string
    {
        $candidates = [];

        if (\defined('PHP_BINARY') && PHP_BINARY) {
            $candidates[] = PHP_BINARY;
        }

        $candidates = array_merge($candidates, [
            '/usr/local/bin/php',
            '/usr/bin/php',
            '/opt/cpanel/ea-php84/root/usr/bin/php',
            'php',
        ]);

        foreach (array_unique($candidates) as $candidate) {
            if (
                $candidate === 'php'
                || (
                    is_executable($candidate)
                    && !preg_match('#(?:php-cgi|lsphp|php-fpm)$#i', $candidate)
                )
            ) {
                return $candidate;
            }
        }

        return 'php';
    }

    private static function runCliCommand(array $command, bool $allowFailure = false): array
    {
        if (!self::canExecuteCommands()) {
            throw new \RuntimeException('PHP command execution is not available on this host.');
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, JPATH_ROOT);

        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start the Admin Tools CLI process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $result = [
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
            'exit_code' => (int) $exitCode,
        ];

        if ($exitCode !== 0 && !$allowFailure) {
            $message = trim($result['stderr']) !== '' ? trim($result['stderr']) : 'Admin Tools CLI command failed.';
            throw new \RuntimeException($message);
        }

        return $result;
    }

    private static function canExecuteCommands(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return \function_exists('proc_open') && !\in_array('proc_open', $disabled, true);
    }
}

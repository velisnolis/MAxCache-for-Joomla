<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

\defined('_JEXEC') or die;

final class AdminToolsManager
{
    private const OPTION_KEY = 'custfoot';
    private const EXCEPTION_DIRS_KEY = 'exceptiondirs';

    public static function isAvailable(): bool
    {
        return is_dir(JPATH_ADMINISTRATOR . '/components/com_admintools');
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

    public static function applySnippet(string $snippet, string $cacheRoot): array
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
        self::writeBackup($footerBackup, $currentFooter);
        $exceptionDirsBackup = self::buildExceptionDirsBackupPath();
        self::writeBackup($exceptionDirsBackup, json_encode(self::getCurrentExceptionDirs(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $htaccessBackup = null;
        $htaccessPath = HtaccessManager::getHtaccessPath();

        if (is_file($htaccessPath)) {
            $htaccessBackup = HtaccessManager::buildBackupPath() . '.' . bin2hex(random_bytes(4));
            self::writeBackup($htaccessBackup, self::readHtaccessForBackup($htaccessPath));
        }

        self::runCommand('set', [
            '--key' => self::OPTION_KEY,
            '--value' => $updatedFooter,
        ]);

        self::ensurePublicCachePathException($cacheRoot);

        self::runCommand('make');

        $status = self::getStatus($snippet);

        if ($status['state'] !== 'applied' || $status['htaccess_state'] !== 'applied') {
            throw new \RuntimeException('The MAx Cache block could not be verified after the Admin Tools rebuild.');
        }

        return [
            'target' => 'admintools',
            'hash' => HtaccessManager::buildHash($snippet),
            'backup_path' => $htaccessBackup,
            'footer_backup_path' => $footerBackup,
            'exceptiondirs_backup_path' => $exceptionDirsBackup,
        ];
    }

    public static function removeManagedBlock(string $cacheRoot = ''): array
    {
        if (!self::isAvailable()) {
            return [
                'removed' => false,
                'backup_path' => null,
                'footer_backup_path' => null,
                'exceptiondirs_backup_path' => null,
            ];
        }

        $currentFooter = self::getCurrentFooter();

        if ($currentFooter === null) {
            return [
                'removed' => false,
                'backup_path' => null,
                'footer_backup_path' => null,
                'exceptiondirs_backup_path' => null,
            ];
        }

        $updatedFooter = HtaccessManager::removeManagedBlockFromContents($currentFooter);

        if ($updatedFooter === null) {
            $footerBackup = null;
        } else {
            $footerBackup = self::buildFooterBackupPath();
            self::writeBackup($footerBackup, $currentFooter);
        }

        $exceptionDirsBackup = self::buildExceptionDirsBackupPath();
        self::writeBackup($exceptionDirsBackup, json_encode(self::getCurrentExceptionDirs(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $htaccessBackup = null;
        $htaccessPath = HtaccessManager::getHtaccessPath();

        if (is_file($htaccessPath)) {
            $htaccessBackup = HtaccessManager::buildBackupPath() . '.' . bin2hex(random_bytes(4));
            self::writeBackup($htaccessBackup, self::readHtaccessForBackup($htaccessPath));
        }

        if ($updatedFooter !== null) {
            self::runCommand('set', [
                '--key' => self::OPTION_KEY,
                '--value' => $updatedFooter,
            ]);
        }

        self::removePublicCachePathException($cacheRoot);

        self::runCommand('make');

        $remainingFooter = self::getCurrentFooter();

        if ($remainingFooter === null
            || HtaccessManager::getManagedBlock($remainingFooter) !== null
            || HtaccessManager::getManagedBlock(HtaccessManager::readCurrentContents()) !== null) {
            throw new \RuntimeException('The MAx Cache block is still present after the Admin Tools rebuild.');
        }

        return [
            'removed' => true,
            'backup_path' => $htaccessBackup,
            'footer_backup_path' => $footerBackup,
            'exceptiondirs_backup_path' => $exceptionDirsBackup,
        ];
    }

    private static function getCurrentFooter(): ?string
    {
        try {
            $footer = self::getSavedConfiguration()->{self::OPTION_KEY} ?? '';

            return is_string($footer) ? $footer : null;
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * Read only saved values: loading form defaults dispatches editor plugins,
     * which can require an HTML document even during console status checks.
     */
    private static function getSavedConfiguration(): object
    {
        $model = Factory::getApplication()->bootComponent('com_admintools')
            ->getMVCFactory()->createModel('Htaccessmaker', 'Administrator');

        return $model->loadConfiguration(false);
    }

    /**
     * @return string[]
     */
    private static function getCurrentExceptionDirs(): array
    {
        $dirs = self::getSavedConfiguration()->{self::EXCEPTION_DIRS_KEY} ?? [];

        if (!is_array($dirs)) {
            throw new \RuntimeException('Could not read the current Admin Tools directory exceptions.');
        }

        return array_values(array_filter(array_map('strval', $dirs), static fn ($value) => trim($value) !== ''));
    }

    private static function buildFooterBackupPath(): string
    {
        $date = (new Date())->format('Ymd-His');

        return self::getBackupDirectory() . '/admintools-footer.' . $date . '.' . bin2hex(random_bytes(4)) . '.bak';
    }

    private static function buildExceptionDirsBackupPath(): string
    {
        $date = (new Date())->format('Ymd-His');

        return self::getBackupDirectory() . '/admintools-exceptiondirs.' . $date . '.' . bin2hex(random_bytes(4)) . '.bak';
    }

    private static function getBackupDirectory(): string
    {
        $dir = JPATH_ROOT . '/administrator/cache/maxcache-backups';

        if (!DirectoryOperations::ensure($dir)) {
            throw new \RuntimeException('Could not create backup directory: ' . $dir);
        }

        return $dir;
    }

    private static function writeBackup(string $path, string $contents): void
    {
        if (@file_put_contents($path, $contents) !== strlen($contents)
            || @file_get_contents($path) !== $contents) {
            throw new \RuntimeException('Could not create and verify the Admin Tools backup: ' . $path);
        }
    }

    private static function readHtaccessForBackup(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException('Could not read .htaccess for backup.');
        }

        return $contents;
    }

    private static function ensurePublicCachePathException(string $cacheRoot): void
    {
        $publicPath = CachePathHelper::buildPublicPath($cacheRoot);

        if ($publicPath === null) {
            return;
        }

        $current = self::getCurrentExceptionDirs();

        if (\in_array(ltrim($publicPath, '/'), array_map(static fn ($item) => ltrim((string) $item, '/'), $current), true)) {
            return;
        }

        self::runCommand('set', [
            '--key' => self::EXCEPTION_DIRS_KEY,
            '--add' => ltrim($publicPath, '/'),
        ]);
    }

    private static function removePublicCachePathException(string $cacheRoot): void
    {
        $publicPath = CachePathHelper::buildPublicPath($cacheRoot);

        if ($publicPath === null) {
            return;
        }

        self::runCommand('set', [
            '--key' => self::EXCEPTION_DIRS_KEY,
            '--remove' => ltrim($publicPath, '/'),
        ]);
    }

    /**
     * Use Admin Tools' own validated commands in the current administrator
     * application. This preserves its authorised save/write call chain without
     * booting a separate CLI application where HTML-only plugins can crash.
     * Output stays buffered; none of the command output enters the HTML page.
     */
    private static function runCommand(string $operation, array $options = []): void
    {
        $app = Factory::getApplication();

        if (!$app->isClient('administrator')) {
            throw new \RuntimeException('Apply Admin Tools server rules from the Joomla administrator.');
        }

        $factory = $app->bootComponent('com_admintools')->getMVCFactory();
        // JPATH_COMPONENT still points to com_plugins in the plugin editor.
        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_admintools/forms');
        $classes = [
            'set' => 'Akeeba\\Component\\AdminTools\\Administrator\\CliCommand\\HtaccessMakerSet',
            'make' => 'Akeeba\\Component\\AdminTools\\Administrator\\CliCommand\\HtaccessMakerMake',
        ];
        $class = $classes[$operation];
        $command = new $class();
        $command->setMVCFactory($factory);
        $input = new ArrayInput($options);
        $input->setInteractive(false);
        $output = new BufferedOutput();
        $exitCode = $command->execute($input, $output);

        if ($exitCode !== 0) {
            throw new \RuntimeException('Admin Tools .htaccess ' . $operation . ' failed (exit ' . $exitCode . ').');
        }
    }
}

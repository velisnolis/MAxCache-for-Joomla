<?php

declare(strict_types=1);

namespace Joomla\CMS {
    class Factory {
        public static object $application;
        public static function getApplication(): object { return self::$application; }
    }
}

namespace Joomla\CMS\Date {
    class Date extends \DateTimeImmutable {}
}

namespace Joomla\CMS\Form {
    class Form {
        public static array $paths = [];
        public static function addFormPath(string $path): void { self::$paths[] = $path; }
    }
}

namespace Symfony\Component\Console\Input {
    class ArrayInput {
        public bool $interactive = true;
        public function __construct(public array $options) {}
        public function setInteractive(bool $interactive): void { $this->interactive = $interactive; }
    }
}

namespace Symfony\Component\Console\Output {
    class BufferedOutput {
        public string $buffer = '';
    }
}

namespace Akeeba\Component\AdminTools\Administrator\CliCommand {
    class HtaccessMakerSet {
        private object $factory;
        public function setMVCFactory(object $factory): void { $this->factory = $factory; }
        public function execute(object $input, object $output): int {
            \AdminToolsFixture::prepareForm($input, $this->factory);
            $options = $input->options;
            \AdminToolsFixture::$commands[] = ['set', $options];
            $output->buffer = 'Do not leak command output into the admin HTML';
            if (\AdminToolsFixture::$failSet) { return 30; }
            $key = $options['--key'];
            if (isset($options['--value'])) {
                \AdminToolsFixture::$config[$key] = $options['--value'];
            } elseif (isset($options['--add'])) {
                \AdminToolsFixture::$config[$key][] = $options['--add'];
            } elseif (isset($options['--remove'])) {
                \AdminToolsFixture::$config[$key] = array_values(array_filter(
                    \AdminToolsFixture::$config[$key], static fn ($dir) => $dir !== $options['--remove']));
            }
            return 0;
        }
    }

    class HtaccessMakerMake {
        private object $factory;
        public function setMVCFactory(object $factory): void { $this->factory = $factory; }
        public function execute(object $input, object $output): int {
            \AdminToolsFixture::prepareForm($input, $this->factory);
            \AdminToolsFixture::$commands[] = ['make', $input->options];
            if (\AdminToolsFixture::$failMake) { return 1; }
            if (!\AdminToolsFixture::$skipWrite) {
                file_put_contents(JPATH_ROOT . '/.htaccess', '# Akeeba Admin Tools' . "\n" . (\AdminToolsFixture::$config['custfoot'] ?? ''));
            }
            return 0;
        }
    }
}

namespace {
    use Joomla\CMS\Factory;
    use Vendor\Plugin\System\Maxcache\Support\AdminToolsManager;
    use Vendor\Plugin\System\Maxcache\Support\DirectoryOperations;
    use Vendor\Plugin\System\Maxcache\Support\HtaccessManager;

    define('_JEXEC', 1);
    define('JPATH_ROOT', sys_get_temp_dir() . '/maxcache-admintools-' . bin2hex(random_bytes(8)));
    define('JPATH_ADMINISTRATOR', JPATH_ROOT . '/administrator');

    foreach (['DirectoryOperations', 'CachePathHelper', 'HtaccessManager', 'AdminToolsManager'] as $helper) {
        require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/' . $helper . '.php';
    }
    DirectoryOperations::ensure(JPATH_ADMINISTRATOR . '/components/com_admintools');
    register_shutdown_function(static fn () => DirectoryOperations::delete(JPATH_ROOT));

    class AdminToolsFixture {
        public static array $config = ['custfoot' => '', 'exceptiondirs' => ['images'], 'security_setting' => 'keep'];
        public static array $commands = [];
        public static array $defaultReads = [];
        public static bool $failRead = false;
        public static bool $failSet = false;
        public static bool $failMake = false;
        public static bool $skipWrite = false;
        public static bool $html = true;
        public static function prepareForm(object $input, object $factory): void {
            if (!self::$html) {
                throw new \Error('Call to undefined method ConsoleApplication::getDocument()');
            }
            if ($input->interactive || !$factory instanceof AdminToolsFactory
                || !in_array(JPATH_ADMINISTRATOR . '/components/com_admintools/forms', \Joomla\CMS\Form\Form::$paths, true)) {
                throw new \RuntimeException('Invalid command context');
            }
        }
    }
    class AdminToolsFactory {
        public function createModel(string $name, string $prefix): object {
            if ($name !== 'Htaccessmaker' || $prefix !== 'Administrator') { throw new \RuntimeException('Wrong model'); }
            return new class {
                public function loadConfiguration(bool $withDefaults = true): object {
                    AdminToolsFixture::$defaultReads[] = $withDefaults;
                    if (AdminToolsFixture::$failRead) { throw new \RuntimeException('Read failed'); }
                    if ($withDefaults && !AdminToolsFixture::$html) {
                        throw new \Error('Call to undefined method ConsoleApplication::getDocument()');
                    }
                    return (object) AdminToolsFixture::$config;
                }
            };
        }
    }
    class AdminToolsApplication {
        public function isClient(string $client): bool { return $client === (AdminToolsFixture::$html ? 'administrator' : 'cli'); }
        public function bootComponent(string $component): object {
            if ($component !== 'com_admintools') { throw new \RuntimeException('Wrong component'); }
            return new class {
                public function getMVCFactory(): object { return new AdminToolsFactory(); }
            };
        }
    }
    Factory::$application = new AdminToolsApplication();
    $assertions = 0;
    function verify(string $label, mixed $expected, mixed $actual): void {
        global $assertions;
        ++$assertions;
        if ($expected !== $actual) {
            throw new \RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }
    function failure(callable $action): string {
        try { $action(); } catch (\Throwable $error) { return $error->getMessage(); }
        throw new \RuntimeException('Expected an error');
    }

    $snippet = "MaxCache On\n# literal \$value `text` 'quotes'";
    $root = JPATH_ROOT . '/maxcache';
    file_put_contents(JPATH_ROOT . '/.htaccess', '# original rules');

    verify('Admin Tools no longer needs a CLI entrypoint', true, AdminToolsManager::isAvailable());
    AdminToolsFixture::$html = false;
    verify('CLI status reads an empty footer without HTML form events', 'not_applied', AdminToolsManager::getStatus($snippet)['state']);
    verify('CLI status invokes no modifying commands', [], AdminToolsFixture::$commands);
    AdminToolsFixture::$failRead = true;
    verify('read failure remains unknown, not empty', 'unknown', AdminToolsManager::getStatus($snippet)['state']);
    verify('apply refuses unknown footer', true, str_contains(failure(static fn () => AdminToolsManager::applySnippet($snippet, $root)), 'Could not read'));
    AdminToolsFixture::$failRead = false;
    verify('console cannot apply server rules', true, str_contains(failure(static fn () => AdminToolsManager::applySnippet($snippet, $root)), 'Joomla administrator'));
    verify('console apply invokes no modifying commands', [], AdminToolsFixture::$commands);
    AdminToolsFixture::$html = true;

    ob_start();
    $first = AdminToolsManager::applySnippet($snippet, $root);
    $htmlOutput = ob_get_clean();
    verify('command output is not rendered', '', $htmlOutput);
    verify('empty footer backup is valid', '', file_get_contents($first['footer_backup_path']));
    verify('htaccess backed up before rebuild', '# original rules', file_get_contents($first['backup_path']));
    verify('exceptions backed up before modification', ['images'], json_decode(file_get_contents($first['exceptiondirs_backup_path']), true));
    verify('literal multiline footer reaches the command unchanged', HtaccessManager::buildManagedBlock($snippet) . "\n", AdminToolsFixture::$commands[0][1]['--value']);
    verify('existing exception preserved', ['images', 'maxcache'], AdminToolsFixture::$config['exceptiondirs']);
    verify('unrelated configuration preserved', 'keep', AdminToolsFixture::$config['security_setting']);
    verify('footer and htaccess verified', ['applied', 'applied'], array_values(array_intersect_key(AdminToolsManager::getStatus($snippet), ['state' => true, 'htaccess_state' => true])));

    $second = AdminToolsManager::applySnippet($snippet . "\n# revised", $root);
    verify('second apply has a distinct backup', false, $first['footer_backup_path'] === $second['footer_backup_path']);
    verify('first htaccess backup is not overwritten', '# original rules', file_get_contents($first['backup_path']));
    verify('reapply replaces one block', 1, substr_count(AdminToolsFixture::$config['custfoot'], HtaccessManager::BEGIN_MARKER));
    verify('reapply does not duplicate exception', ['images', 'maxcache'], AdminToolsFixture::$config['exceptiondirs']);

    AdminToolsFixture::$config['custfoot'] = "# custom before\n" . AdminToolsFixture::$config['custfoot'] . "# custom after\n";
    AdminToolsManager::removeManagedBlock($root);
    verify('remove preserves custom footer', true, str_contains(AdminToolsFixture::$config['custfoot'], '# custom before') && str_contains(AdminToolsFixture::$config['custfoot'], '# custom after'));
    verify('remove rebuilds without managed block', null, HtaccessManager::getManagedBlock(file_get_contents(JPATH_ROOT . '/.htaccess')));
    verify('remove preserves other exceptions', ['images'], AdminToolsFixture::$config['exceptiondirs']);

    AdminToolsFixture::$commands = [];
    AdminToolsFixture::$failSet = true;
    verify('nonzero command code propagates', true, str_contains(failure(static fn () => AdminToolsManager::applySnippet($snippet, $root)), 'exit 30'));
    verify('failed save never rebuilds', ['set'], array_column(AdminToolsFixture::$commands, 0));
    AdminToolsFixture::$failSet = false;
    AdminToolsFixture::$failMake = true;
    verify('rebuild failure propagates', true, str_contains(failure(static fn () => AdminToolsManager::applySnippet($snippet, $root)), 'exit 1'));
    AdminToolsFixture::$failMake = false;
    AdminToolsFixture::$skipWrite = true;
    verify('false success from rebuild is rejected', true, str_contains(failure(static fn () => AdminToolsManager::applySnippet($snippet, $root)), 'could not be verified'));
    AdminToolsFixture::$skipWrite = false;

    AdminToolsFixture::$commands = [];
    AdminToolsFixture::$config['exceptiondirs'] = 'invalid';
    verify('malformed exceptions fail closed', true, str_contains(failure(static fn () => AdminToolsManager::applySnippet($snippet, $root)), 'directory exceptions'));
    verify('no write after malformed exceptions', [], AdminToolsFixture::$commands);
    AdminToolsFixture::$config['exceptiondirs'] = ['images'];

    $backupDir = JPATH_ADMINISTRATOR . '/cache/maxcache-backups';
    DirectoryOperations::delete($backupDir);
    file_put_contents($backupDir, 'block directory creation');
    verify('backup failure stops apply', true, str_contains(failure(static fn () => AdminToolsManager::applySnippet($snippet, $root)), 'backup directory'));
    verify('backup failure invokes no write commands', [], AdminToolsFixture::$commands);
    verify('saved configuration reads never load form defaults', [false], array_values(array_unique(AdminToolsFixture::$defaultReads, SORT_REGULAR)));
    echo 'Admin Tools regressions: ' . $assertions . " assertions passed.\n";
}

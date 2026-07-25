<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Console.maxcache
 */

namespace Vendor\Plugin\Console\Maxcache\CliCommand;

use Joomla\Console\Command\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vendor\Plugin\Console\Maxcache\Support\MaxcacheState;

\defined('_JEXEC') or die;

final class StatusCommand extends AbstractCommand
{
    protected static $defaultName = 'maxcache:status';

    public function __construct(private MaxcacheState $state)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Show MAxCache plugin, configuration, server block, and static file status.');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json.', 'text');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $snapshot = $this->state->snapshot();

        if ((string) $input->getOption('format') === 'json') {
            $output->writeln((string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $snapshot['installed'] ? 0 : 1;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('MAxCache status');

        if (!$snapshot['installed']) {
            $io->error('System - MAxCache is not installed.');

            return 1;
        }

        $cache = $snapshot['cache'];
        $configuration = $snapshot['configuration'];
        $server = $snapshot['server'];

        $io->definitionList(
            ['Version' => $snapshot['version']],
            ['System plugin' => $snapshot['enabled'] ? 'enabled' : 'disabled'],
            ['Cache root' => $cache['root']],
            ['Cache root safe' => $cache['safe'] ? 'yes' : 'no'],
            ['Static files' => (string) $cache['files']],
            ['Static bytes' => (string) $cache['bytes']],
            ['Purge strategy' => $configuration['purge_strategy']],
            ['Snippet mode' => $configuration['server_snippet_mode']],
            ['Managed server block' => $server['managed_block_present'] ? 'present' : 'not found'],
            ['Managed snippet state' => $server['snippet_state']],
            ['Joomla System - Cache' => $server['joomla_system_cache_enabled'] ? 'enabled' : 'disabled']
        );

        $io->section('Effective safeguards');
        $io->listing([
            'Built-in exclusions: ' . implode(', ', $configuration['built_in_exclusions']),
            'Custom exclusions: ' . count($configuration['custom_exclusions']),
            'Excluded menu items: ' . count($configuration['excluded_menu_items']),
            'Bypass cookies: ' . implode(', ', $configuration['bypass_cookies']),
        ]);

        if ($snapshot['issues'] !== []) {
            $io->warning(array_map(
                static fn (array $issue): string => $issue['code'] . ': ' . $issue['message'],
                $snapshot['issues']
            ));
        }

        return $snapshot['enabled'] ? 0 : 1;
    }
}

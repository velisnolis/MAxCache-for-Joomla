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

final class PurgeCommand extends AbstractCommand
{
    protected static $defaultName = 'maxcache:purge';

    public function __construct(private MaxcacheState $state)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Purge the configured MAxCache static root after explicit confirmation.');
        $this->addOption('confirm', null, InputOption::VALUE_NONE, 'Confirm deletion of the reported cache root.');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json.', 'text');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $snapshot = $this->state->snapshot();

        if (!$snapshot['installed']) {
            $output->writeln('<error>System - MAxCache is not installed.</error>');

            return 1;
        }

        $cache = $snapshot['cache'];

        if (!$cache['safe']) {
            $output->writeln('<error>Refusing unsafe cache root: ' . $cache['root'] . '</error>');

            return 1;
        }

        if (!$input->getOption('confirm')) {
            $output->writeln(sprintf(
                '<comment>Dry run only. Would remove %d files (%d bytes) from %s.</comment>',
                $cache['files'],
                $cache['bytes'],
                $cache['root']
            ));
            $output->writeln('<comment>Run again with --confirm to purge this exact root.</comment>');

            return 2;
        }

        $result = $this->state->purge();

        if ((string) $input->getOption('format') === 'json') {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $io = new SymfonyStyle($input, $output);
        $io->success(sprintf(
            'Purged %d files (%d bytes) from %s; cleaned %d Joomla cache group(s).',
            $result['files'],
            $result['bytes'],
            $result['root'],
            $result['joomla_caches_cleaned']
        ));

        return 0;
    }
}

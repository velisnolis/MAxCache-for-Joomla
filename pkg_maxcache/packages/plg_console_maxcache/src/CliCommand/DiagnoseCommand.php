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

final class DiagnoseCommand extends AbstractCommand
{
    protected static $defaultName = 'maxcache:diagnose';

    public function __construct(private MaxcacheState $state)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Diagnose unsafe or incomplete MAxCache configuration.');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json.', 'text');
        $this->addOption('strict', null, InputOption::VALUE_NONE, 'Return non-zero when warnings are present.');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $snapshot = $this->state->snapshot();
        $issues = (array) ($snapshot['issues'] ?? []);
        $errors = array_values(array_filter(
            $issues,
            static fn (array $issue): bool => ($issue['severity'] ?? '') === 'error'
        ));
        $warnings = array_values(array_filter(
            $issues,
            static fn (array $issue): bool => ($issue['severity'] ?? '') === 'warning'
        ));
        $payload = [
            'healthy' => $errors === [] && (!$input->getOption('strict') || $warnings === []),
            'errors' => $errors,
            'warnings' => $warnings,
            'status' => $snapshot,
        ];

        if ((string) $input->getOption('format') === 'json') {
            $output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->title('MAxCache diagnosis');

            if ($errors === [] && $warnings === []) {
                $io->success('No MAxCache configuration issues detected.');
            } else {
                foreach (array_merge($errors, $warnings) as $issue) {
                    $io->writeln(sprintf(
                        '<%s>%s</%s> %s',
                        $issue['severity'] === 'error' ? 'error' : 'comment',
                        strtoupper((string) $issue['severity']) . ' ' . $issue['code'],
                        $issue['severity'] === 'error' ? 'error' : 'comment',
                        $issue['message']
                    ));
                }
            }
        }

        if ($errors !== []) {
            return 1;
        }

        return $input->getOption('strict') && $warnings !== [] ? 1 : 0;
    }
}

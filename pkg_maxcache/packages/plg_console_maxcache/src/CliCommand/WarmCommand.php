<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Console.maxcache
 */

namespace Vendor\Plugin\Console\Maxcache\CliCommand;

use Joomla\CMS\Http\HttpFactory;
use Joomla\Console\Command\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vendor\Plugin\Console\Maxcache\Support\MaxcacheState;

\defined('_JEXEC') or die;

final class WarmCommand extends AbstractCommand
{
    protected static $defaultName = 'maxcache:warm';

    public function __construct(private MaxcacheState $state)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Request the top-level menu pages as a guest so MAxCache stores them again.');
        $this->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Public site URL, e.g. https://www.example.com. Defaults to live_site or the first configured site host over https.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the URLs without requesting them.');
        $this->addOption('delay-ms', null, InputOption::VALUE_REQUIRED, 'Pause between requests in milliseconds.', '200');
        $this->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Per-request timeout in seconds.', '20');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json.', 'text');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $plan = $this->state->warmPlan((string) ($input->getOption('base-url') ?? ''));
        } catch (\RuntimeException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return 1;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $delay = max(0, (int) $input->getOption('delay-ms')) * 1000;
        $timeout = max(1, (int) $input->getOption('timeout'));
        $results = [];
        $requested = 0;

        foreach ($plan['targets'] as $target) {
            $artifact = $this->state->artifactPath($target['url']);
            $row = ['menu_id' => $target['menu_id'], 'url' => $target['url'], 'http' => null];

            if ($artifact !== null && is_file($artifact)) {
                $results[] = $row + ['result' => 'cached'];
                continue;
            }

            if ($dryRun) {
                $results[] = $row + ['result' => 'would-request'];
                continue;
            }

            if ($requested++ > 0 && $delay > 0) {
                usleep($delay);
            }

            try {
                $row['http'] = $this->request($target['url'], $timeout);
            } catch (\Throwable $exception) {
                $results[] = $row + ['result' => 'error', 'error' => $exception->getMessage()];
                continue;
            }

            clearstatcache();

            if ($row['http'] !== 200) {
                $row['result'] = 'http-' . $row['http'];
            } else {
                $row['result'] = $artifact !== null && is_file($artifact) ? 'stored' : 'not-stored';
            }

            $results[] = $row;
        }

        $summary = array_count_values(array_column($results, 'result'));
        $failed = \count(array_filter(
            $results,
            static fn (array $row): bool => $row['result'] === 'error' || (int) $row['http'] >= 500
        ));

        if ((string) $input->getOption('format') === 'json') {
            $output->writeln((string) json_encode([
                'base_url' => $plan['base_url'],
                'dry_run' => $dryRun,
                'summary' => $summary,
                'results' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed > 0 ? 1 : 0;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('MAxCache warm-up: top-level menu items of ' . $plan['base_url']);

        if ($results === []) {
            $io->warning('No published top-level menu items to request.');

            return 0;
        }

        $io->table(
            ['Menu', 'URL', 'HTTP', 'Result'],
            array_map(
                static fn (array $row): array => [
                    $row['menu_id'],
                    $row['url'],
                    $row['http'] ?? '-',
                    $row['result'] . (isset($row['error']) ? ': ' . $row['error'] : ''),
                ],
                $results
            )
        );

        if (isset($summary['not-stored'])) {
            $io->note('not-stored: Joomla answered 200 but no artifact appeared at the expected path. The page is excluded, bypassed, or stored under a pagecache variant.');
        }

        return $failed > 0 ? 1 : 0;
    }

    private function request(string $url, int $timeout): int
    {
        // No cookies and no redirects: a redirect target is another URL, and following
        // it would hide that the menu path itself is not a cacheable page.
        $http = (new HttpFactory())->getHttp([
            'follow_location' => false,
            'userAgent' => 'MAxCache-Warm/1 (maxcache:warm)',
        ]);
        $response = $http->get($url, ['Accept' => 'text/html'], $timeout);

        return (int) $response->getStatusCode();
    }
}

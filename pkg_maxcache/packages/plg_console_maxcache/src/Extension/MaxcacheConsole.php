<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Console.maxcache
 */

namespace Vendor\Plugin\Console\Maxcache\Extension;

use Joomla\Application\ApplicationEvents;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;
use Vendor\Plugin\Console\Maxcache\CliCommand\DiagnoseCommand;
use Vendor\Plugin\Console\Maxcache\CliCommand\PurgeCommand;
use Vendor\Plugin\Console\Maxcache\CliCommand\StatusCommand;
use Vendor\Plugin\Console\Maxcache\Support\MaxcacheState;

\defined('_JEXEC') or die;

final class MaxcacheConsole extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ApplicationEvents::BEFORE_EXECUTE => 'registerCommands',
        ];
    }

    public function registerCommands(): void
    {
        /** @var DatabaseInterface $db */
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $state = new MaxcacheState($db);
        $app = $this->getApplication();

        $app->addCommand(new StatusCommand($state));
        $app->addCommand(new DiagnoseCommand($state));
        $app->addCommand(new PurgeCommand($state));
    }
}

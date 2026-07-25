<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Console.maxcache
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Vendor\Plugin\Console\Maxcache\Extension\MaxcacheConsole;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new MaxcacheConsole(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('console', 'maxcache')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};

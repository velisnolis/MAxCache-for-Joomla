<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Ajax.maxcachefragment
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Vendor\Plugin\Ajax\MaxcacheFragment\Extension\MaxcacheFragment;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new MaxcacheFragment((array) PluginHelper::getPlugin('ajax', 'maxcachefragment'));
                $plugin->setDispatcher($container->get(DispatcherInterface::class));
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};

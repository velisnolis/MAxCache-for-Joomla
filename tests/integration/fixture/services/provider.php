<?php

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use MaxcacheTest\Plugin\System\Lab\Extension\Lab;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, function (Container $container) {
            $plugin = new Lab((array) PluginHelper::getPlugin('system', 'maxcachelab'));
            $plugin->setApplication(Factory::getApplication());

            return $plugin;
        });
    }
};

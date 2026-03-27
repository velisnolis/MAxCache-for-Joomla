<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\SiteRouter;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Vendor\Plugin\System\Maxcache\Extension\Maxcache;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = PluginHelper::getPlugin('system', 'maxcache');
                $dispatcher = $container->get(DispatcherInterface::class);
                $router = $container->has(SiteRouter::class) ? $container->get(SiteRouter::class) : null;

                $plugin = new Maxcache((array) $plugin, $router);
                $plugin->setDispatcher($dispatcher);
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};

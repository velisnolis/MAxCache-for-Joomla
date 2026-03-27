<?php

/**
 * @package     Joomla.Package
 * @subpackage  pkg_maxcache
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\Database\DatabaseInterface;

final class Pkg_MaxcacheInstallerScript extends InstallerScript
{
    public function postflight(string $type, $parent): bool
    {
        $this->moveMaxcachePluginToLast();

        return true;
    }

    private function moveMaxcachePluginToLast(): void
    {
        try {
            /** @var DatabaseInterface $db */
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $query = $db->getQuery(true)
                ->select([$db->quoteName('extension_id'), $db->quoteName('ordering')])
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('maxcache'));

            $db->setQuery($query);
            $plugin = $db->loadObject();

            if (!$plugin || empty($plugin->extension_id)) {
                return;
            }

            $query = $db->getQuery(true)
                ->select('MAX(' . $db->quoteName('ordering') . ')')
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('extension_id') . ' <> ' . (int) $plugin->extension_id);

            $db->setQuery($query);
            $maxOrdering = (int) $db->loadResult();

            $plugin->ordering = $maxOrdering + 1;
            $db->updateObject('#__extensions', $plugin, 'extension_id');
        } catch (\Throwable $exception) {
            // Leave install/update successful even if ordering could not be adjusted.
        }
    }
}

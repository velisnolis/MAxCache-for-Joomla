<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

final class SystemCacheStatus
{
    public static function getStatus(): array
    {
        try {
            /** @var DatabaseInterface $db */
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select([$db->quoteName('extension_id'), $db->quoteName('enabled')])
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('cache'));

            $db->setQuery($query);
            $row = $db->loadAssoc();

            if (!$row) {
                return [
                    'installed' => false,
                    'enabled' => false,
                    'state' => 'missing',
                ];
            }

            $enabled = (int) ($row['enabled'] ?? 0) === 1;

            return [
                'installed' => true,
                'enabled' => $enabled,
                'state' => $enabled ? 'enabled' : 'disabled',
            ];
        } catch (\Throwable $exception) {
            return [
                'installed' => false,
                'enabled' => false,
                'state' => 'unknown',
            ];
        }
    }

    public static function isEnabled(): bool
    {
        return (bool) (self::getStatus()['enabled'] ?? false);
    }
}

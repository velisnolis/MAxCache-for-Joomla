<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Hands full regeneration over to Regular Labs Cache Cleaner: adds the static cache
 * folder to its custom folders and copies the MAx Cache save/interval settings.
 */
final class RegularLabsCacheCleanerConfigurator
{
    /**
     * @param array<string, mixed> $status Result of RegularLabsCacheCleanerDetector::detect().
     */
    public static function configure(DatabaseInterface $db, array $status, Registry $params): void
    {
        $query = $db->getQuery(true)
            ->select([$db->quoteName('extension_id'), $db->quoteName('params')])
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('cachecleaner'));

        $db->setQuery($query);
        $row = $db->loadAssoc();

        if (!$row || empty($row['extension_id'])) {
            throw new \RuntimeException('Could not locate the Regular Labs Cache Cleaner plugin.');
        }

        $cacheCleanerParams = new Registry((string) ($row['params'] ?? '{}'));
        $relativeFolder = ltrim((string) ($status['recommended_path'] ?? '/maxcache'), '/');
        $folders = self::lines((string) $cacheCleanerParams->get('clean_folders_selection', ''));

        if ($relativeFolder !== '' && !\in_array($relativeFolder, $folders, true)) {
            $folders[] = $relativeFolder;
        }

        $cacheCleanerParams->set('clean_folders', 1);
        $cacheCleanerParams->set('clean_folders_selection', implode("\n", $folders));
        $cacheCleanerParams->set('clean_folders_min_age', 0);
        $cacheCleanerParams->set('auto_save_admin', (int) $params->get('full_regenerate_on_save', 1));
        $cacheCleanerParams->set('auto_save_admin_msg', 0);
        $cacheCleanerParams->set('auto_save_front', 0);
        $cacheCleanerParams->set('auto_save_front_msg', 0);
        $cacheCleanerParams->set('auto_interval_admin', (int) $params->get('full_regenerate_on_interval', 0));
        $cacheCleanerParams->set(
            'auto_interval_admin_secs',
            max(60, (int) $params->get('full_regenerate_interval_seconds', 3600))
        );
        $cacheCleanerParams->set('auto_interval_admin_msg', 0);

        $update = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = ' . $db->quote((string) $cacheCleanerParams))
            ->where($db->quoteName('extension_id') . ' = ' . (int) $row['extension_id']);

        $db->setQuery($update);
        $db->execute();
    }

    /**
     * @return string[]
     */
    private static function lines(string $value): array
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return array_values(array_filter(
            array_map('trim', explode("\n", $value)),
            static fn (string $line): bool => $line !== ''
        ));
    }
}

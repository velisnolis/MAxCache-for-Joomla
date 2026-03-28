<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

final class RegularLabsCacheCleanerDetector
{
    public static function detect(string $cacheRoot): array
    {
        try {
            /** @var DatabaseInterface $db */
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $extensions = self::loadExtensions($db);
            $modulePublished = self::isAdminModulePublished($db);
            $publicPath = CachePathHelper::buildPublicPath($cacheRoot);
            $recommendedCacheRoot = CachePathHelper::recommendedCacheRoot();
            $recommendedPublicPath = $publicPath ?? CachePathHelper::recommendedPublicPath();

            $regularLabsSystemEnabled = self::isEnabled($extensions, 'plugin', 'system', 'regularlabs');
            $cacheCleanerSystemEnabled = self::isEnabled($extensions, 'plugin', 'system', 'cachecleaner');
            $cacheCleanerSystemInstalled = self::isInstalled($extensions, 'plugin', 'system', 'cachecleaner');
            $moduleInstalled = self::isInstalled($extensions, 'module', '', 'mod_cachecleaner');

            $active = $regularLabsSystemEnabled && $cacheCleanerSystemEnabled && $modulePublished;
            $cacheCleanerDetected = $moduleInstalled || $cacheCleanerSystemInstalled;

            return [
                'state' => $active ? 'active' : ($cacheCleanerDetected ? 'detected_inactive' : 'not_detected'),
                'module_installed' => $moduleInstalled,
                'module_published' => $modulePublished,
                'regularlabs_system_enabled' => $regularLabsSystemEnabled,
                'cachecleaner_system_installed' => $cacheCleanerSystemInstalled,
                'cachecleaner_system_enabled' => $cacheCleanerSystemEnabled,
                'recommended_path' => $recommendedPublicPath,
                'cache_root_is_public' => $publicPath !== null,
                'recommended_cache_root' => $recommendedCacheRoot,
            ];
        } catch (\Throwable) {
            return [
                'state' => 'unknown',
                'module_installed' => false,
                'module_published' => false,
                'regularlabs_system_enabled' => false,
                'cachecleaner_system_installed' => false,
                'cachecleaner_system_enabled' => false,
                'recommended_path' => CachePathHelper::recommendedPublicPath(),
                'cache_root_is_public' => CachePathHelper::buildPublicPath($cacheRoot) !== null,
                'recommended_cache_root' => CachePathHelper::recommendedCacheRoot(),
            ];
        }
    }

    /**
     * @return array<string, bool>
     */
    private static function loadExtensions(DatabaseInterface $db): array
    {
        $query = $db->getQuery(true)
            ->select([$db->quoteName('type'), $db->quoteName('folder'), $db->quoteName('element'), $db->quoteName('enabled')])
            ->from($db->quoteName('#__extensions'))
            ->where(
                '('
                . $db->quoteName('element') . ' IN (' . $db->quote('regularlabs') . ',' . $db->quote('cachecleaner') . ',' . $db->quote('mod_cachecleaner') . ')'
                . ' OR '
                . '(' . $db->quoteName('folder') . ' = ' . $db->quote('system')
                . ' AND ' . $db->quoteName('element') . ' IN (' . $db->quote('regularlabs') . ',' . $db->quote('cachecleaner') . '))'
                . ')'
            );

        $db->setQuery($query);
        $rows = (array) $db->loadAssocList();
        $map = [];

        foreach ($rows as $row) {
            $key = strtolower(($row['type'] ?? '') . ':' . ($row['folder'] ?? '') . ':' . ($row['element'] ?? ''));
            $map[$key] = (bool) ($row['enabled'] ?? false);
        }

        return $map;
    }

    private static function isAdminModulePublished(DatabaseInterface $db): bool
    {
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('module') . ' = ' . $db->quote('mod_cachecleaner'))
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('client_id') . ' = 1');

        $db->setQuery($query);

        return (int) $db->loadResult() > 0;
    }

    /**
     * @param array<string, bool> $extensions
     */
    private static function isEnabled(array $extensions, string $type, string $folder, string $element): bool
    {
        return $extensions[strtolower($type . ':' . $folder . ':' . $element)] ?? false;
    }

    /**
     * @param array<string, bool> $extensions
     */
    private static function isInstalled(array $extensions, string $type, string $folder, string $element): bool
    {
        return array_key_exists(strtolower($type . ':' . $folder . ':' . $element), $extensions);
    }
}

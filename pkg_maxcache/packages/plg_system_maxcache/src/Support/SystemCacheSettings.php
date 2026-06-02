<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

final class SystemCacheSettings
{
    public static function getExcludedMenuItems(): array
    {
        $params = self::getParams();
        $items = $params['exclude_menu_items'] ?? [];

        if (!\is_array($items)) {
            $items = [$items];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($item): int => (int) $item,
            $items
        ))));
    }

    public static function getExcludedUrlPatterns(): array
    {
        return self::normalizeLineList((string) (self::getParams()['exclude'] ?? ''));
    }

    public static function mergeMenuItems(array $menuItems): array
    {
        $menuItems = array_map(static fn ($item): int => (int) $item, $menuItems);

        return array_values(array_unique(array_filter(array_merge(
            $menuItems,
            self::getExcludedMenuItems()
        ))));
    }

    public static function mergeUrlPatterns(array $patterns): array
    {
        return array_values(array_unique(array_filter(array_merge(
            array_map('trim', $patterns),
            self::getExcludedUrlPatterns()
        ), static fn (string $pattern): bool => $pattern !== '')));
    }

    private static function getParams(): array
    {
        try {
            /** @var DatabaseInterface $db */
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $query = $db->getQuery(true)
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('cache'));
            $db->setQuery($query);

            return json_decode((string) $db->loadResult(), true) ?: [];
        } catch (\Throwable $exception) {
            return [];
        }
    }

    private static function normalizeLineList(string $value): array
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = array_map('trim', explode("\n", $value));

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }
}

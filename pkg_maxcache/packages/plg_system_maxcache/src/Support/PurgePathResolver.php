<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\Component\Content\Site\Helper\RouteHelper as ContentRouteHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Maps a changed article or menu item to the public paths whose artifacts are stale.
 */
final class PurgePathResolver
{
    /**
     * @return string[]
     */
    public static function resolve(string $context, object $item): array
    {
        $paths = [];

        if ($context === 'com_menus.item') {
            if (isset($item->path) && \is_string($item->path) && trim($item->path) !== '') {
                $paths[] = '/' . trim($item->path, '/');
            }

            if (isset($item->home) && (int) $item->home === 1) {
                $paths[] = '/';
            }

            return array_values(array_unique($paths));
        }

        if ($context === 'com_content.article') {
            if (
                class_exists(ContentRouteHelper::class)
                && isset($item->id, $item->catid)
            ) {
                $route = ContentRouteHelper::getArticleRoute((int) $item->id, (int) $item->catid, $item->language ?? '*');
                $paths[] = parse_url(Route::_($route), PHP_URL_PATH) ?: '';
            }

            $paths = array_merge($paths, self::menuPathsForArticle((int) ($item->id ?? 0)));

            if (isset($item->alias) && \is_string($item->alias) && trim($item->alias) !== '') {
                $paths[] = '/' . trim($item->alias, '/');
            }
        }

        return array_values(array_unique(array_filter($paths, static fn ($path): bool => \is_string($path))));
    }

    /**
     * Rows for a state change, which only carries primary keys.
     *
     * @return object[]
     */
    public static function loadItemsByIds(string $context, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        try {
            /** @var DatabaseInterface $db */
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            if ($context === 'com_content.article') {
                $query = $db->getQuery(true)
                    ->select([
                        $db->quoteName('id'),
                        $db->quoteName('catid'),
                        $db->quoteName('alias'),
                        $db->quoteName('language'),
                    ])
                    ->from($db->quoteName('#__content'))
                    ->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')');
            } else {
                $query = $db->getQuery(true)
                    ->select([
                        $db->quoteName('id'),
                        $db->quoteName('path'),
                        $db->quoteName('alias'),
                        $db->quoteName('home'),
                        $db->quoteName('link'),
                        $db->quoteName('language'),
                    ])
                    ->from($db->quoteName('#__menu'))
                    ->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')');
            }

            $db->setQuery($query);

            return (array) $db->loadObjectList();
        } catch (\Throwable $exception) {
            return [];
        }
    }

    /**
     * @return string[]
     */
    private static function menuPathsForArticle(int $articleId): array
    {
        if ($articleId <= 0) {
            return [];
        }

        try {
            /** @var DatabaseInterface $db */
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select($db->quoteName('path'))
                ->from($db->quoteName('#__menu'))
                ->where($db->quoteName('published') . ' = 1')
                ->where($db->quoteName('path') . ' <> ' . $db->quote(''))
                ->where('('
                    . $db->quoteName('link') . ' LIKE ' . $db->quote('%view=article%&id=' . $articleId)
                    . ' OR '
                    . $db->quoteName('link') . ' LIKE ' . $db->quote('%view=article%&id=' . $articleId . '&%')
                    . ')');

            $db->setQuery($query);

            return array_map(static fn (string $path): string => '/' . trim($path, '/'), (array) $db->loadColumn());
        } catch (\Throwable $exception) {
            return [];
        }
    }
}

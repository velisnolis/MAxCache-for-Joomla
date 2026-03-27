<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

\defined('_JEXEC') or die;

final class CachePathHelper
{
    public static function buildPublicPath(string $cacheRoot): ?string
    {
        $cacheRoot = rtrim($cacheRoot, '/');

        foreach (['/public_html/', '/htdocs/', '/www/'] as $marker) {
            $position = strpos($cacheRoot, $marker);

            if ($position === false) {
                continue;
            }

            $suffix = trim(substr($cacheRoot, $position + strlen($marker)), '/');

            return '/' . ($suffix === '' ? 'maxcache' : $suffix);
        }

        return null;
    }

    public static function recommendedCacheRoot(): string
    {
        return rtrim(JPATH_ROOT, '/') . '/maxcache';
    }

    public static function recommendedPublicPath(): string
    {
        return self::buildPublicPath(self::recommendedCacheRoot()) ?? '/maxcache';
    }
}

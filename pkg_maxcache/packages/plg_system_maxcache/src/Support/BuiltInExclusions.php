<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

\defined('_JEXEC') or die;

final class BuiltInExclusions
{
    public static function getRuntimePatterns(): array
    {
        return [
            '/administrator(?:/.*|$)',
            '/api(?:/.*|$)',
            '/component/ajax(?:/.*|$)',
            '(?:^|[?&])option=com_ajax(?:[=&]|$)',
            '(?:^|[?&])format=(?:feed|json|raw)(?:[=&]|$)',
            '(?:^|[?&])tmpl=component(?:[=&]|$)',
        ];
    }

    public static function getSnippetPatterns(): array
    {
        return [
            '/(?:administrator|api|component/ajax)(?:/.*|$)',
            '(?:^|[?&])option=com_ajax(?:[=&]|$)',
            '(?:^|[?&])format=(?:feed|json|raw)(?:[=&]|$)',
            '(?:^|[?&])tmpl=component(?:[=&]|$)',
        ];
    }

    public static function getLabels(): array
    {
        return [
            '/administrator',
            '/api',
            '/component/ajax',
            'option=com_ajax',
            'format=feed|json|raw',
            'tmpl=component',
        ];
    }

    public static function filterCustomPatterns(array $patterns): array
    {
        $builtIns = array_merge(self::getRuntimePatterns(), self::getSnippetPatterns(), self::getLabels());
        $builtIns = array_map('trim', $builtIns);

        return array_values(array_filter(
            array_map('trim', $patterns),
            static fn (string $pattern): bool => $pattern !== '' && !in_array($pattern, $builtIns, true)
        ));
    }
}

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
}

<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Field;

use Joomla\CMS\Form\FormField;
use Vendor\Plugin\System\Maxcache\Support\BuiltInExclusions;
use Vendor\Plugin\System\Maxcache\Support\SystemCacheSettings;

\defined('_JEXEC') or die;

final class BuiltInExclusionsField extends FormField
{
    protected $type = 'BuiltInExclusions';

    protected function getInput(): string
    {
        $items = array_map(
            static fn (string $label): string => '<li><code>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</code></li>',
            BuiltInExclusions::getLabels()
        );
        $systemCacheMenuItems = SystemCacheSettings::getExcludedMenuItems();
        $systemCacheUrlPatterns = SystemCacheSettings::getExcludedUrlPatterns();
        $systemCacheHtml = '';

        if ($systemCacheMenuItems !== [] || $systemCacheUrlPatterns !== []) {
            $systemCacheHtml .= '<hr><p><strong>Inherited from Joomla System - Cache:</strong> MAx Cache also applies these exclusions at runtime and in the generated server snippet.</p>';

            if ($systemCacheMenuItems !== []) {
                $systemCacheHtml .= '<p><strong>Menu items:</strong> <code>'
                    . htmlspecialchars(implode(', ', $systemCacheMenuItems), ENT_QUOTES, 'UTF-8')
                    . '</code></p>';
            }

            if ($systemCacheUrlPatterns !== []) {
                $systemCacheHtml .= '<p><strong>URL patterns:</strong></p><ul style="margin:0 0 0 1.2rem;">'
                    . implode('', array_map(
                        static fn (string $pattern): string => '<li><code>' . htmlspecialchars($pattern, ENT_QUOTES, 'UTF-8') . '</code></li>',
                        $systemCacheUrlPatterns
                    ))
                    . '</ul>';
            }
        }

        return '<div class="alert alert-info">'
            . '<p><strong>Common Joomla exclusions:</strong> These are always excluded automatically. Use <code>Exclude URLs</code> only for additional site-specific patterns.</p>'
            . '<ul style="margin:0 0 0 1.2rem;">' . implode('', $items) . '</ul>'
            . $systemCacheHtml
            . '</div>';
    }
}

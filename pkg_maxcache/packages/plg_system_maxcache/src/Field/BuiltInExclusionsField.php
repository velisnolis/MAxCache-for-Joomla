<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Field;

use Joomla\CMS\Form\FormField;
use Vendor\Plugin\System\Maxcache\Support\BuiltInExclusions;

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

        return '<div class="alert alert-info">'
            . '<p><strong>Common Joomla exclusions:</strong> These are always excluded automatically. Use <code>Exclude URLs</code> only for additional site-specific patterns.</p>'
            . '<ul style="margin:0 0 0 1.2rem;">' . implode('', $items) . '</ul>'
            . '</div>';
    }
}

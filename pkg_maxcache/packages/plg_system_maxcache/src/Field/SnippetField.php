<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Field;

use Joomla\CMS\Form\FormField;
use Vendor\Plugin\System\Maxcache\Support\SnippetParams;

\defined('_JEXEC') or die;

final class SnippetField extends FormField
{
    protected $type = 'Snippet';

    protected function getInput(): string
    {
        $mode = (string) ($this->element['mode'] ?? 'mod_maxcache');
        $settings = [];

        foreach (SnippetParams::SETTING_KEYS as $key) {
            $settings[$key] = $this->form->getValue($key, 'params');
        }

        $snippet = SnippetParams::buildSnippet($settings, $mode);
        $value = htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8');

        return '<textarea id="maxcache-snippet-preview" class="form-control" rows="12" readonly="readonly">' . $value . '</textarea>';
    }
}

<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Field;

use Joomla\CMS\Form\Field\FormField;
use Vendor\Plugin\System\Maxcache\Support\SnippetBuilder;

\defined('_JEXEC') or die;

final class SnippetField extends FormField
{
    protected $type = 'Snippet';

    protected function getInput(): string
    {
        $mode = (string) ($this->element['mode'] ?? 'mod_maxcache');
        $params = [
            'cache_root' => $this->form->getValue('cache_root', 'params'),
            'exclude' => $this->form->getValue('exclude', 'params'),
            'bypass_cookies' => $this->form->getValue('bypass_cookies', 'params'),
            'allowed_query_params' => $this->form->getValue('allowed_query_params', 'params'),
        ];

        $snippet = SnippetBuilder::build($mode, $params);
        $value = htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8');

        return '<textarea id="maxcache-snippet-preview" class="form-control" rows="12" readonly="readonly">' . $value . '</textarea>';
    }
}

<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Session\Session;

\defined('_JEXEC') or die;

final class HtaccessActionsField extends FormField
{
    protected $type = 'HtaccessActions';

    protected function getInput(): string
    {
        $app = Factory::getApplication();
        $extensionId = (int) $app->getInput()->getInt('extension_id');
        $token = Session::getFormToken();
        $confirmMessage = "Apply the current saved MAx Cache snippet to .htaccess? A backup will be created before writing.";
        $confirm = htmlspecialchars($confirmMessage, ENT_QUOTES, 'UTF-8');
        $applyScript = <<<JS
(function () {
    if (!confirm('{$confirm}')) {
        return;
    }

    const form = document.getElementById('adminForm');

    if (!form) {
        return;
    }

    let actionField = form.querySelector('input[name="maxcache_action"]');

    if (!actionField) {
        actionField = document.createElement('input');
        actionField.type = 'hidden';
        actionField.name = 'maxcache_action';
        form.appendChild(actionField);
    }

    actionField.value = 'apply_snippet';
    form.submit();
}());
JS;

        $html = [];
        $html[] = '<div class="btn-toolbar">';
        $html[] = '<div class="btn-group">';
        $html[] = '<button type="button" class="btn btn-secondary" onclick="document.getElementById(\'maxcache-snippet-preview\')?.scrollIntoView({behavior:\'smooth\', block:\'center\'});">Preview snippet</button>';
        $html[] = '</div>';
        $html[] = '<div class="btn-group">';
        $html[] = '<button type="button" class="btn btn-success" onclick="' . htmlspecialchars($applyScript, ENT_QUOTES, 'UTF-8') . '">Apply snippet to .htaccess</button>';
        $html[] = '<input type="hidden" name="' . $token . '" value="1">';
        $html[] = '</div>';
        $html[] = '</div>';
        $html[] = '<p class="small text-muted mt-2">This action uses the current saved plugin settings. Save the plugin first if you changed any options.</p>';

        return implode('', $html);
    }
}

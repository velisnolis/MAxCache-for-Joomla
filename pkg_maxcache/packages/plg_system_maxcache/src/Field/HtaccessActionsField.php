<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\FormField;
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
        $action = 'index.php?option=com_plugins&view=plugin&extension_id=' . $extensionId;
        $confirm = "return confirm('Apply the current saved MAx Cache snippet to .htaccess? A backup will be created before writing.');";

        $html = [];
        $html[] = '<div class="btn-toolbar">';
        $html[] = '<div class="btn-group">';
        $html[] = '<button type="button" class="btn btn-secondary" onclick="document.getElementById(\'maxcache-snippet-preview\')?.scrollIntoView({behavior:\'smooth\', block:\'center\'});">Preview snippet</button>';
        $html[] = '</div>';
        $html[] = '<div class="btn-group">';
        $html[] = '<form action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '" method="post" style="display:inline-block" onsubmit="' . htmlspecialchars($confirm, ENT_QUOTES, 'UTF-8') . '">';
        $html[] = '<input type="hidden" name="option" value="com_plugins">';
        $html[] = '<input type="hidden" name="view" value="plugin">';
        $html[] = '<input type="hidden" name="extension_id" value="' . $extensionId . '">';
        $html[] = '<input type="hidden" name="maxcache_action" value="apply_snippet">';
        $html[] = '<input type="hidden" name="' . $token . '" value="1">';
        $html[] = '<button type="submit" class="btn btn-success">Apply snippet to .htaccess</button>';
        $html[] = '</form>';
        $html[] = '</div>';
        $html[] = '</div>';
        $html[] = '<p class="small text-muted mt-2">This action uses the current saved plugin settings. Save the plugin first if you changed any options.</p>';

        return implode('', $html);
    }
}

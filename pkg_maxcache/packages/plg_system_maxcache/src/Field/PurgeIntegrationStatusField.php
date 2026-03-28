<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Router\Route;
use Vendor\Plugin\System\Maxcache\Support\RegularLabsCacheCleanerDetector;

\defined('_JEXEC') or die;

final class PurgeIntegrationStatusField extends FormField
{
    protected $type = 'PurgeIntegrationStatus';

    protected function getInput(): string
    {
        $cacheRoot = (string) $this->form->getValue('cache_root', 'params', '/var/cache/joomla-maxcache');
        $status = RegularLabsCacheCleanerDetector::detect($cacheRoot);
        $driver = (string) $this->form->getValue('purge_automation_driver', 'params', 'maxcache');
        $extensionId = Factory::getApplication()->getInput()->getInt('extension_id');
        $action = Route::_('index.php?option=com_plugins&task=plugin.edit&extension_id=' . $extensionId, false);

        $html = [];
        $html[] = '<div class="alert alert-info">';
        $html[] = $this->renderFullStrategyVisibilityScript();

        if (!$status['cache_root_is_public']) {
            $html[] = '<p><strong>Static Cache Root:</strong> the current value is not under the web root, so Regular Labs cannot purge it by public path.</p>';
            $html[] = '<p>Recommended before activation: set <code>Static Cache Root</code> to <code>'
                . htmlspecialchars((string) $status['recommended_cache_root'], ENT_QUOTES, 'UTF-8')
                . '</code>. That will expose the public purge path <code>'
                . htmlspecialchars((string) $status['recommended_path'], ENT_QUOTES, 'UTF-8')
                . '</code>.</p>';
        }

        if ($status['state'] === 'active') {
            $html[] = '<p><strong>Regular Labs Cache Cleaner:</strong> Active.</p>';
            $html[] = '<p>Recommended: add <code>' . htmlspecialchars((string) $status['recommended_path'], ENT_QUOTES, 'UTF-8') . '</code> to <strong>Custom Folders</strong> in Regular Labs Cache Cleaner.</p>';

            if (!$status['cache_root_is_public']) {
                $html[] = '<p>MAx Cache cannot fully defer to Cache Cleaner until the static cache root is inside the public web root.</p>';
            } elseif ($driver === 'regularlabs') {
                $html[] = '<p><strong>Integration mode:</strong> MAx Cache is delegating full regeneration to Cache Cleaner. Configure automatic cleaning on save and by interval in the Cache Cleaner plugin.</p>';
                $html[] = $this->renderActionForm($action, 'disable_regularlabs', 'Use MAx Cache automation');
            } else {
                $html[] = '<p>You can let Cache Cleaner manage full regeneration for MAx Cache. This action will first save the current MAx Cache settings, then copy the relevant full-regeneration options into Cache Cleaner.</p>';
                $html[] = $this->renderActionForm($action, 'enable_regularlabs', 'Save and integrate with Cache Cleaner');
            }
        } elseif ($status['state'] === 'detected_inactive') {
            $html[] = '<p><strong>Regular Labs Cache Cleaner:</strong> Detected but not fully active.</p>';
            $html[] = '<p>MAx Cache should only defer to it when all of these are true:</p>';
            $html[] = '<ul>'
                . '<li>Admin module <code>mod_cachecleaner</code> is published</li>'
                . '<li><code>System - Regular Labs</code> is enabled</li>'
                . '<li><code>System - Regular Labs - Cache Cleaner</code> is enabled</li>'
                . '</ul>';
            $html[] = '<p>If you activate it, add <code>' . htmlspecialchars((string) $status['recommended_path'], ENT_QUOTES, 'UTF-8') . '</code> to <strong>Custom Folders</strong>.</p>';
        } elseif ($status['state'] === 'not_detected') {
            $html[] = '<p><strong>Regular Labs Cache Cleaner:</strong> Not detected.</p>';
            $html[] = '<p>MAx Cache should provide its own manual purge fallback in this setup.</p>';
        } else {
            $html[] = '<p><strong>Regular Labs Cache Cleaner:</strong> Could not be verified from Joomla.</p>';
            $html[] = '<p>If you use it, the recommended custom folder for MAx Cache is <code>' . htmlspecialchars((string) $status['recommended_path'], ENT_QUOTES, 'UTF-8') . '</code>.</p>';
        }

        $html[] = '</div>';

        return implode('', $html);
    }

    private function renderActionForm(string $action, string $requestedAction, string $label): string
    {
        $jsonAction = json_encode($action, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $jsonRequestedAction = json_encode($requestedAction, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $onclick = <<<JS
var form=document.forms.adminForm || document.querySelector('form[name="adminForm"]') || document.getElementById('adminForm') || document.getElementById('style-form');
if(!form){return false;}
var actionField=form.querySelector('input[name="maxcache_action"]');
if(!actionField){
  actionField=document.createElement('input');
  actionField.type='hidden';
  actionField.name='maxcache_action';
  form.appendChild(actionField);
}
actionField.value={$jsonRequestedAction};
form.action={$jsonAction};
if(typeof Joomla!=='undefined' && typeof Joomla.submitbutton==='function'){
  Joomla.submitbutton('plugin.apply');
  return false;
}
form.submit();
return false;
JS;

        return '<div style="margin-top:1rem;">'
            . '<button type="button" class="btn" style="background:#1f4f82 !important;border:1px solid #1f4f82 !important;color:#fff !important;text-decoration:none !important;" onclick="' . htmlspecialchars($onclick, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</button>'
            . '</div>';
    }

    private function renderFullStrategyVisibilityScript(): string
    {
        $ids = json_encode([
            'strategy' => 'jform_params_purge_strategy',
            'on_save' => 'jform_params_full_regenerate_on_save',
            'on_interval' => 'jform_params_full_regenerate_on_interval',
            'interval_seconds' => 'jform_params_full_regenerate_interval_seconds',
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        return <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
    const ids = {$ids};
    const strategyField = document.getElementById(ids.strategy);

    if (!strategyField) {
        return;
    }

    const findWrapper = (id) => {
        const field = document.getElementById(id);

        if (!field) {
            return null;
        }

        return field.closest('.control-group, .control-field, .form-group, .mb-3') || field.parentElement;
    };

    const wrappers = [
        findWrapper(ids.on_save),
        findWrapper(ids.on_interval),
        findWrapper(ids.interval_seconds),
    ].filter(Boolean);

    const toggle = () => {
        const visible = strategyField.value === 'full';

        wrappers.forEach((wrapper) => {
            wrapper.style.display = visible ? '' : 'none';
        });
    };

    strategyField.addEventListener('change', toggle);
    toggle();
});
</script>
HTML;
    }
}

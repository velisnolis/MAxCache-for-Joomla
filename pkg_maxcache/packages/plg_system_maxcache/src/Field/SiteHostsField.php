<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Field;

use Joomla\CMS\Form\FormField;
use Vendor\Plugin\System\Maxcache\Support\SiteHostDetector;

\defined('_JEXEC') or die;

final class SiteHostsField extends FormField
{
    protected $type = 'SiteHosts';

    protected function getInput(): string
    {
        $name = (string) $this->name;
        $id = (string) $this->id;
        $rows = (int) ($this->element['rows'] ?? 4);
        $current = (string) $this->form->getValue('site_hosts', 'params', '');
        $detectedHosts = SiteHostDetector::detect($current);
        $displayValue = $current !== '' ? $current : implode("\n", $detectedHosts);
        $escapedValue = htmlspecialchars($displayValue, ENT_QUOTES, 'UTF-8');
        $jsonId = json_encode($id, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $jsonHosts = json_encode(implode("\n", $detectedHosts), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        return <<<HTML
<textarea name="{$name}" id="{$id}" class="form-control" rows="{$rows}">{$escapedValue}</textarea>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const field = document.getElementById({$jsonId});
    const enabledField = document.querySelector('select[name="jform[enabled]"], select[name="enabled"], [name$="[enabled]"]');

    if (!field || !enabledField || String(enabledField.value) !== '0') {
        return;
    }

    if (field.value.trim() !== '') {
        return;
    }

    const detected = {$jsonHosts};

    if (detected && String(detected).trim() !== '') {
        field.value = detected;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
    }
});
</script>
HTML;
    }
}

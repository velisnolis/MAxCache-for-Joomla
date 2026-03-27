<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Field;

use Joomla\CMS\Form\FormField;
use Vendor\Plugin\System\Maxcache\Support\LanguageRoutingDetector;

\defined('_JEXEC') or die;

final class LanguageRoutingStatusField extends FormField
{
    protected $type = 'LanguageRoutingStatus';

    protected function getInput(): string
    {
        $detected = LanguageRoutingDetector::detect();
        $currentPathMode = (string) $this->form->getValue('path_mode', 'params', 'host-sef');
        $currentVaryLanguage = (int) $this->form->getValue('vary_language', 'params', 0);

        $class = ($currentPathMode === $detected['recommended_path_mode']
            && $currentVaryLanguage === (int) $detected['recommended_vary_language'])
            ? 'alert alert-success'
            : 'alert alert-warning';

        $html = [];
        $html[] = '<div class="' . $class . '">';
        $html[] = '<p><strong>Language URL routing:</strong> ' . htmlspecialchars($detected['message'], ENT_QUOTES, 'UTF-8') . '</p>';
        $html[] = '<p><strong>Recommended:</strong> <code>Path Layout = '
            . htmlspecialchars($detected['recommended_path_mode'], ENT_QUOTES, 'UTF-8')
            . '</code>, <code>Vary by Language = '
            . (int) $detected['recommended_vary_language']
            . '</code>.</p>';
        $html[] = '</div>';

        return implode('', $html);
    }
}

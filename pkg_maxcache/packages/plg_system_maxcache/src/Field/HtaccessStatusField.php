<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Field;

use Joomla\CMS\Form\Field\FormField;
use Vendor\Plugin\System\Maxcache\Support\HtaccessManager;
use Vendor\Plugin\System\Maxcache\Support\SnippetBuilder;

\defined('_JEXEC') or die;

final class HtaccessStatusField extends FormField
{
    protected $type = 'HtaccessStatus';

    protected function getInput(): string
    {
        $mode = (string) $this->form->getValue('server_snippet_mode', 'params', 'mod_maxcache');
        $params = [
            'cache_root' => $this->form->getValue('cache_root', 'params'),
            'exclude' => $this->form->getValue('exclude', 'params'),
            'bypass_cookies' => $this->form->getValue('bypass_cookies', 'params'),
            'allowed_query_params' => $this->form->getValue('allowed_query_params', 'params'),
        ];

        $snippet = SnippetBuilder::build($mode, $params);
        $status = HtaccessManager::getStatus($snippet);

        $stateLabel = match ($status['state']) {
            'applied' => 'Applied',
            'outdated' => 'Applied snippet is outdated',
            default => 'Snippet not applied',
        };

        $html = [];
        $html[] = '<div class="alert alert-info">';
        $html[] = '<p><strong>.htaccess status:</strong> ' . htmlspecialchars($stateLabel, ENT_QUOTES, 'UTF-8') . '</p>';

        if ($status['akeeba_detected']) {
            $html[] = '<p><strong>Warning:</strong> Akeeba Admin Tools markers were detected in the current .htaccess. Keep apply/manual actions explicit and verify rule order before changing server config.</p>';
        }

        if ($status['applied_hash']) {
            $html[] = '<p><strong>Applied hash:</strong> <code>' . htmlspecialchars($status['applied_hash'], ENT_QUOTES, 'UTF-8') . '</code></p>';
        }

        $html[] = '<p><strong>Expected hash:</strong> <code>' . htmlspecialchars($status['expected_hash'], ENT_QUOTES, 'UTF-8') . '</code></p>';
        $html[] = '<p>This scaffold intentionally does not auto-write .htaccess on save. The future apply action should be explicit, create a backup, and require confirmation every time.</p>';
        $html[] = '</div>';

        return implode('', $html);
    }
}

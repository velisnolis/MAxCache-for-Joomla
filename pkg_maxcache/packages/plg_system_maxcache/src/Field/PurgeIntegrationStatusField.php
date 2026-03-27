<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Field;

use Joomla\CMS\Form\FormField;
use Vendor\Plugin\System\Maxcache\Support\RegularLabsCacheCleanerDetector;

\defined('_JEXEC') or die;

final class PurgeIntegrationStatusField extends FormField
{
    protected $type = 'PurgeIntegrationStatus';

    protected function getInput(): string
    {
        $cacheRoot = (string) $this->form->getValue('cache_root', 'params', '/var/cache/joomla-maxcache');
        $status = RegularLabsCacheCleanerDetector::detect($cacheRoot);

        $html = [];
        $html[] = '<div class="alert alert-info">';

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
            $html[] = '<p>Recommended: add <code>' . htmlspecialchars((string) $status['recommended_path'], ENT_QUOTES, 'UTF-8') . '</code> to <strong>Custom Folders</strong> in Regular Labs Cache Cleaner and use that button for manual MAx Cache purges.</p>';
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
}

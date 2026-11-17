<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

\defined('_JEXEC') or die;

/**
 * Adds the "Purge MAx Cache" action to a rendered administrator page: an item in the
 * header on wide screens and a floating button below 1200px.
 */
final class AdminPurgeButton
{
    /**
     * @param string[] $warnings Shown next to the button when server integration is in doubt.
     */
    public static function inject(string $body, string $token, string $action, array $warnings): string
    {
        if (stripos($body, 'data-maxcache-purge-action') !== false) {
            return $body;
        }

        $confirm = htmlspecialchars(
            'Purge all MAx Cache static files now? This will remove the entire static cache directory and the next public requests will rebuild it.',
            ENT_QUOTES,
            'UTF-8'
        );
        $warningText = implode("\n", $warnings);
        $warningTitle = $warnings !== []
            ? htmlspecialchars($warningText, ENT_QUOTES, 'UTF-8')
            : '';
        $headerWarning = $warnings !== []
            ? '<span class="badge bg-warning text-dark ms-1" title="' . $warningTitle . '">!</span>'
            : '';
        $buttonClass = $warnings !== [] ? 'btn btn-warning' : 'btn btn-danger';
        $floatingWarning = $warnings !== []
            ? '<div class="small text-dark bg-warning p-2 mt-2 rounded" style="max-width:280px;">' . nl2br($warningTitle, false) . '</div>'
            : '';

        $styleMarkup = <<<HTML
<style data-maxcache-purge-action-style>
@media (min-width: 1200px) {
  [data-maxcache-purge-action="floating"] { display: none !important; }
}
@media (max-width: 1199.98px) {
  [data-maxcache-purge-action="floating"] { display: block !important; }
}
</style>
HTML;

        $headerMarkup = <<<HTML
<div class="header-item" data-maxcache-purge-action="header">
  <a href="javascript:" onclick="return document.getElementById('maxcache-purge-form')?.requestSubmit();" class="header-item-content" title="Purge MAx Cache">
    <div class="header-item-icon">
      <span class="icon-trash" aria-hidden="true"></span>
    </div>
    <div class="header-item-text">Purge MAx Cache{$headerWarning}</div>
  </a>
  <form id="maxcache-purge-form" method="post" action="{$action}" onsubmit="return confirm('{$confirm}');" style="display:none;">
    <input type="hidden" name="maxcache_action" value="purge_cache">
    <input type="hidden" name="{$token}" value="1">
  </form>
</div>
HTML;

        $floatingMarkup = <<<HTML
<div data-maxcache-purge-action="floating" style="display:none;position:fixed;right:18px;bottom:18px;z-index:1080;">
  <form method="post" action="{$action}" onsubmit="return confirm('{$confirm}');" style="margin:0;">
    <input type="hidden" name="maxcache_action" value="purge_cache">
    <input type="hidden" name="{$token}" value="1">
    <button type="submit" class="{$buttonClass}">
      Purge MAx Cache
    </button>
    {$floatingWarning}
  </form>
</div>
HTML;

        $updated = preg_replace(
            '#<div class="header-items d-flex ms-auto">#i',
            '$0' . "\n" . $headerMarkup,
            $body,
            1,
            $headerMatches
        );

        $body = ($headerMatches ?? 0) > 0 ? (string) $updated : $body;

        return (string) preg_replace('#</body>#i', $styleMarkup . "\n" . $floatingMarkup . "\n</body>", $body, 1);
    }
}

<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Field;

use Joomla\CMS\Form\FormField;
use Vendor\Plugin\System\Maxcache\Support\CachePathHelper;
use Vendor\Plugin\System\Maxcache\Support\LanguageRoutingDetector;
use Vendor\Plugin\System\Maxcache\Support\ServerCapabilityDetector;

\defined('_JEXEC') or die;

final class LanguageRoutingStatusField extends FormField
{
    protected $type = 'LanguageRoutingStatus';

    protected function getInput(): string
    {
        $detected = LanguageRoutingDetector::detect();
        $currentPathMode = (string) $this->form->getValue('path_mode', 'params', 'host-sef');
        $currentVaryLanguage = (int) $this->form->getValue('vary_language', 'params', 0);
        $currentCacheRoot = (string) $this->form->getValue('cache_root', 'params', '/var/cache/joomla-maxcache');
        $currentSnippetMode = (string) $this->form->getValue('server_snippet_mode', 'params', 'apache');
        $recommendedCacheRoot = CachePathHelper::recommendedCacheRoot();
        $modMaxcache = ServerCapabilityDetector::detectModMaxcache();
        $recommendedSnippetMode = \in_array($modMaxcache['state'] ?? 'unknown', ['detected', 'configured'], true) ? 'mod_maxcache' : 'apache';

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
        $html[] = '<p class="small text-muted mb-0">On a fresh disabled install, MAx Cache can prefill these detected defaults before activation.</p>';
        $html[] = '</div>';
        $html[] = $this->buildPrefillScript(
            $detected['recommended_path_mode'],
            (int) $detected['recommended_vary_language'],
            $recommendedCacheRoot,
            $recommendedSnippetMode,
            $currentPathMode,
            $currentVaryLanguage,
            $currentCacheRoot,
            $currentSnippetMode
        );

        return implode('', $html);
    }

    private function buildPrefillScript(
        string $recommendedPathMode,
        int $recommendedVaryLanguage,
        string $recommendedCacheRoot,
        string $recommendedSnippetMode,
        string $currentPathMode,
        int $currentVaryLanguage,
        string $currentCacheRoot,
        string $currentSnippetMode
    ): string {
        $payload = [
            'recommended' => [
                'pathMode' => $recommendedPathMode,
                'varyLanguage' => $recommendedVaryLanguage,
                'cacheRoot' => $recommendedCacheRoot,
                'snippetMode' => $recommendedSnippetMode,
            ],
            'current' => [
                'pathMode' => $currentPathMode,
                'varyLanguage' => $currentVaryLanguage,
                'cacheRoot' => $currentCacheRoot,
                'snippetMode' => $currentSnippetMode,
            ],
        ];

        $json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        return <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
    const data = {$json};
    const enabledField = document.querySelector('select[name="jform[enabled]"], select[name="enabled"], [name$="[enabled]"]');

    if (!enabledField || String(enabledField.value) !== '0') {
        return;
    }

    const pathField = document.querySelector('select[name="jform[params][path_mode]"], [name$="[path_mode]"]');
    const varyLanguageNo = document.querySelector('input[name="jform[params][vary_language]"][value="0"], input[name$="[vary_language]"][value="0"]');
    const varyLanguageYes = document.querySelector('input[name="jform[params][vary_language]"][value="1"], input[name$="[vary_language]"][value="1"]');
    const cacheRootField = document.querySelector('input[name="jform[params][cache_root]"], [name$="[cache_root]"]');
    const snippetField = document.querySelector('select[name="jform[params][server_snippet_mode]"], [name$="[server_snippet_mode]"]');

    if (pathField && data.current.pathMode === 'host-sef' && data.recommended.pathMode !== data.current.pathMode) {
        pathField.value = data.recommended.pathMode;
        pathField.dispatchEvent(new Event('change', { bubbles: true }));
    }

    if (varyLanguageYes && varyLanguageNo && data.current.varyLanguage === 0 && data.recommended.varyLanguage === 1) {
        varyLanguageYes.checked = true;
        varyLanguageYes.dispatchEvent(new Event('change', { bubbles: true }));
    }

    if (cacheRootField && data.current.cacheRoot === '/var/cache/joomla-maxcache') {
        cacheRootField.value = data.recommended.cacheRoot;
        cacheRootField.dispatchEvent(new Event('input', { bubbles: true }));
        cacheRootField.dispatchEvent(new Event('change', { bubbles: true }));
    }

    if (snippetField && data.current.snippetMode === 'apache' && data.recommended.snippetMode === 'mod_maxcache') {
        snippetField.value = data.recommended.snippetMode;
        snippetField.dispatchEvent(new Event('change', { bubbles: true }));
    }
});
</script>
HTML;
    }
}

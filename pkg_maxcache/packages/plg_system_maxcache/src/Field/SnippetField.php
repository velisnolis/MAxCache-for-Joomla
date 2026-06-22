<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Vendor\Plugin\System\Maxcache\Support\BuiltInExclusions;
use Vendor\Plugin\System\Maxcache\Support\BypassCookieNames;
use Vendor\Plugin\System\Maxcache\Support\SiteHostDetector;
use Vendor\Plugin\System\Maxcache\Support\SnippetBuilder;
use Vendor\Plugin\System\Maxcache\Support\SystemCacheSettings;

\defined('_JEXEC') or die;

final class SnippetField extends FormField
{
    protected $type = 'Snippet';

    protected function getInput(): string
    {
        $mode = (string) ($this->element['mode'] ?? 'mod_maxcache');
        $params = [
            'cache_root' => $this->form->getValue('cache_root', 'params'),
            'site_hosts' => implode("\n", SiteHostDetector::detect((string) $this->form->getValue('site_hosts', 'params'))),
            'exclude' => implode("\n", SystemCacheSettings::mergeUrlPatterns(BuiltInExclusions::filterCustomPatterns(
                $this->normalizeLineList((string) $this->form->getValue('exclude', 'params'))
            ))),
            'bypass_cookies' => $this->form->getValue('bypass_cookies', 'params'),
            ...$this->getJoomlaCookieSnippetParams(),
            'allowed_query_params' => $this->form->getValue('allowed_query_params', 'params'),
            'write_gzip' => (int) $this->form->getValue('write_gzip', 'params', 0),
        ];

        $snippet = SnippetBuilder::build($mode, $params);
        $value = htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8');

        return '<textarea id="maxcache-snippet-preview" class="form-control" rows="12" readonly="readonly">' . $value . '</textarea>';
    }

    private function getJoomlaCookieSnippetParams(): array
    {
        try {
            $config = Factory::getConfig();

            return [
                'joomla_secret' => (string) $config->get('secret', ''),
                'joomla_session_name' => (string) $config->get('session_name', ''),
                'joomla_session_cookie_names' => BypassCookieNames::factorySessionCookieNames(),
            ];
        } catch (\Throwable $exception) {
            return [
                'joomla_secret' => '',
                'joomla_session_name' => '',
                'joomla_session_cookie_names' => BypassCookieNames::factorySessionCookieNames(),
            ];
        }
    }

    private function normalizeLineList(string $value): array
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = array_map('trim', explode("\n", $value));

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }
}

<?php

/**
 * @package     Joomla.Package
 * @subpackage  pkg_maxcache
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\Database\DatabaseInterface;

final class Pkg_MaxcacheInstallerScript extends InstallerScript
{
    public function postflight(string $type, $parent): bool
    {
        $this->applyDetectedLanguageDefaults($type);
        $this->moveMaxcachePluginToLast();

        return true;
    }

    private function applyDetectedLanguageDefaults(string $type): void
    {
        if ($type !== 'install') {
            return;
        }

        try {
            /** @var DatabaseInterface $db */
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $query = $db->getQuery(true)
                ->select([$db->quoteName('extension_id'), $db->quoteName('params')])
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('maxcache'));

            $db->setQuery($query);
            $plugin = $db->loadObject();

            if (!$plugin || empty($plugin->extension_id)) {
                return;
            }

            $params = json_decode((string) ($plugin->params ?? '{}'), true);

            if (!\is_array($params)) {
                $params = [];
            }

            $detected = $this->detectLanguageRoutingProfile($db);
            $params['path_mode'] = $detected['recommended_path_mode'];
            $params['vary_language'] = $detected['recommended_vary_language'];
            $params['autodetected_language_routing'] = $detected['state'];

            $plugin->params = json_encode($params, JSON_UNESCAPED_SLASHES);
            $db->updateObject('#__extensions', $plugin, 'extension_id');
        } catch (\Throwable $exception) {
            // Keep installation resilient if defaults could not be inferred.
        }
    }

    private function detectLanguageRoutingProfile(DatabaseInterface $db): array
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName('enabled'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('languagefilter'));
        $db->setQuery($query);
        $languageFilterEnabled = (int) $db->loadResult() === 1;

        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__languages'))
            ->where($db->quoteName('published') . ' = 1');
        $db->setQuery($query);
        $publishedLanguages = (int) $db->loadResult();

        $query = $db->getQuery(true)
            ->select($db->quoteName('path'))
            ->from($db->quoteName('#__menu'))
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('path') . ' <> ' . $db->quote(''))
            ->where($db->quoteName('path') . ' <> ' . $db->quote('/'));
        $db->setQuery($query);

        foreach ((array) $db->loadColumn() as $path) {
            $first = strtolower((string) strtok((string) $path, '/'));

            if ($first !== '' && (bool) preg_match('#^[a-z]{2}(?:-[a-z]{2})?$#', $first)) {
                return [
                    'state' => 'prefixed',
                    'recommended_path_mode' => 'host-language-sef',
                    'recommended_vary_language' => 1,
                ];
            }
        }

        if ($languageFilterEnabled && $publishedLanguages > 1) {
            return [
                'state' => 'multilingual_hidden',
                'recommended_path_mode' => 'host-sef',
                'recommended_vary_language' => 0,
            ];
        }

        return [
            'state' => 'single_language',
            'recommended_path_mode' => 'host-sef',
            'recommended_vary_language' => 0,
        ];
    }

    private function moveMaxcachePluginToLast(): void
    {
        try {
            /** @var DatabaseInterface $db */
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $query = $db->getQuery(true)
                ->select([$db->quoteName('extension_id'), $db->quoteName('ordering')])
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('maxcache'));

            $db->setQuery($query);
            $plugin = $db->loadObject();

            if (!$plugin || empty($plugin->extension_id)) {
                return;
            }

            $query = $db->getQuery(true)
                ->select('MAX(' . $db->quoteName('ordering') . ')')
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('extension_id') . ' <> ' . (int) $plugin->extension_id);

            $db->setQuery($query);
            $maxOrdering = (int) $db->loadResult();

            $plugin->ordering = $maxOrdering + 1;
            $db->updateObject('#__extensions', $plugin, 'extension_id');
        } catch (\Throwable $exception) {
            // Leave install/update successful even if ordering could not be adjusted.
        }
    }
}

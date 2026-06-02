<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

final class LanguageRoutingDetector
{
    public static function detect(): array
    {
        try {
            /** @var DatabaseInterface $db */
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $query = $db->getQuery(true)
                ->select([$db->quoteName('enabled'), $db->quoteName('params')])
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('languagefilter'));
            $db->setQuery($query);
            $languageFilter = $db->loadAssoc() ?: [];
            $languageFilterEnabled = (int) ($languageFilter['enabled'] ?? 0) === 1;
            $languageFilterParams = json_decode((string) ($languageFilter['params'] ?? '{}'), true) ?: [];

            $query = $db->getQuery(true)
                ->select([$db->quoteName('lang_code'), $db->quoteName('sef')])
                ->from($db->quoteName('#__languages'))
                ->where($db->quoteName('published') . ' = 1')
                ->order($db->quoteName('lang_id') . ' ASC');
            $db->setQuery($query);
            $languages = (array) $db->loadAssocList();
            $profile = LanguageRoutingProfile::detect($languageFilterEnabled, $languageFilterParams, $languages);
            $languageSefs = (array) ($profile['language_sefs'] ?? []);

            if (($profile['state'] ?? '') === 'prefixed') {
                return $profile + [
                    'message' => 'Language Filter is enabled and published site language SEF prefixes are exposed in URLs'
                        . self::formatLanguageSefs($languageSefs)
                        . '. Host + Language + SEF Path is the recommended default.',
                ];
            }

            if (($profile['state'] ?? '') === 'partially_prefixed') {
                return $profile + [
                    'message' => 'Language Filter is enabled with multiple published site languages'
                        . self::formatLanguageSefs($languageSefs)
                        . ', but the default language prefix is removed. Host + Language + SEF Path is still the recommended default for non-default language URLs.',
                ];
            }

            return $profile + [
                'message' => 'No language-prefixed URL structure was detected. Host + SEF Path is the recommended default.',
            ];
        } catch (\Throwable $exception) {
            return [
                'state' => 'unknown',
                'recommended_path_mode' => 'host-sef',
                'recommended_vary_language' => 0,
                'message' => 'Language URL structure could not be detected. Host + SEF Path is the safer default until you confirm language-prefixed URLs are in use.',
            ];
        }
    }

    public static function getPublishedLanguageSefs(): array
    {
        try {
            /** @var DatabaseInterface $db */
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $query = $db->getQuery(true)
                ->select($db->quoteName('sef'))
                ->from($db->quoteName('#__languages'))
                ->where($db->quoteName('published') . ' = 1')
                ->where($db->quoteName('sef') . ' <> ' . $db->quote(''));
            $db->setQuery($query);

            return LanguageRoutingProfile::extractLanguageSefs(array_map(
                static fn ($sef): array => ['sef' => $sef],
                (array) $db->loadColumn()
            ));
        } catch (\Throwable $exception) {
            return [];
        }
    }

    private static function formatLanguageSefs(array $sefs): string
    {
        if ($sefs === []) {
            return '';
        }

        return ' (' . implode(', ', $sefs) . ')';
    }

}

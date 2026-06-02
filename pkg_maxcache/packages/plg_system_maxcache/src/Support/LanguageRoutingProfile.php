<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

\defined('_JEXEC') or die;

final class LanguageRoutingProfile
{
    /**
     * @param array<string, mixed> $languageFilterParams
     * @param array<int, array<string, mixed>> $languages
     */
    public static function detect(bool $languageFilterEnabled, array $languageFilterParams, array $languages): array
    {
        $removeDefaultPrefix = (int) ($languageFilterParams['remove_default_prefix'] ?? 0) === 1;
        $languageSefs = self::extractLanguageSefs($languages);
        $publishedLanguages = count($languageSefs);

        if ($languageFilterEnabled && $publishedLanguages >= 1 && !$removeDefaultPrefix) {
            return [
                'state' => 'prefixed',
                'recommended_path_mode' => 'host-language-sef',
                'recommended_vary_language' => 1,
                'language_sefs' => $languageSefs,
            ];
        }

        if ($languageFilterEnabled && $publishedLanguages > 1 && $removeDefaultPrefix) {
            return [
                'state' => 'partially_prefixed',
                'recommended_path_mode' => 'host-language-sef',
                'recommended_vary_language' => 1,
                'language_sefs' => $languageSefs,
            ];
        }

        return [
            'state' => 'single_language',
            'recommended_path_mode' => 'host-sef',
            'recommended_vary_language' => 0,
            'language_sefs' => $languageSefs,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $languages
     * @return string[]
     */
    public static function extractLanguageSefs(array $languages): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (array $language): string => strtolower(trim((string) ($language['sef'] ?? ''))),
            $languages
        ))));
    }
}

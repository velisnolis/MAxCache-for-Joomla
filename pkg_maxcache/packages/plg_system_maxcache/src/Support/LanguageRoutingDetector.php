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

            $prefixes = [];

            foreach ((array) $db->loadColumn() as $path) {
                $first = strtolower((string) strtok((string) $path, '/'));

                if ($first !== '' && (bool) preg_match('#^[a-z]{2}(?:-[a-z]{2})?$#', $first)) {
                    $prefixes[$first] = true;
                }
            }

            $hasLanguagePrefixes = $prefixes !== [];

            if ($languageFilterEnabled && $hasLanguagePrefixes) {
                return [
                    'state' => 'prefixed',
                    'recommended_path_mode' => 'host-language-sef',
                    'recommended_vary_language' => 1,
                    'message' => 'Language Filter is enabled and language prefixes are visible in site URLs. Host + Language + SEF Path is the recommended default.',
                ];
            }

            if ($languageFilterEnabled && $publishedLanguages > 1) {
                return [
                    'state' => 'multilingual_hidden',
                    'recommended_path_mode' => 'host-sef',
                    'recommended_vary_language' => 0,
                    'message' => 'Language Filter is enabled, but language is not exposed in the URL structure. For deterministic server cache paths, Host + SEF Path is the safer default.',
                ];
            }

            return [
                'state' => 'single_language',
                'recommended_path_mode' => 'host-sef',
                'recommended_vary_language' => 0,
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
}

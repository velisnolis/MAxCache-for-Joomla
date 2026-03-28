<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
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

            if ($languageFilterEnabled && $publishedLanguages > 1 && self::frontendRedirectShowsLanguagePrefix()) {
                return [
                    'state' => 'prefixed',
                    'recommended_path_mode' => 'host-language-sef',
                    'recommended_vary_language' => 1,
                    'message' => 'Language Filter is enabled and the frontend redirects into a language-prefixed URL structure. Host + Language + SEF Path is the recommended default.',
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

    private static function frontendRedirectShowsLanguagePrefix(): bool
    {
        $frontendRoot = preg_replace('#/administrator/?$#', '/', Uri::base());

        if (!\is_string($frontendRoot) || $frontendRoot === '') {
            return false;
        }

        $headers = self::fetchHeaders($frontendRoot);

        if ($headers === []) {
            return false;
        }

        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) !== 'location') {
                continue;
            }

            foreach ((array) $value as $candidate) {
                $path = (string) parse_url((string) $candidate, PHP_URL_PATH);

                if (preg_match('#^/[a-z]{2}(?:-[a-z]{2})?(/|$)#i', $path)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function fetchHeaders(string $url): array
    {
        if (\function_exists('curl_init')) {
            $ch = curl_init($url);

            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_NOBODY => true,
                    CURLOPT_HEADER => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                ]);

                $raw = curl_exec($ch);

                if (\is_string($raw) && $raw !== '') {
                    curl_close($ch);

                    return self::parseRawHeaders($raw);
                }

                curl_close($ch);
            }
        }

        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $result = @get_headers($url, true, $context);

        return \is_array($result) ? $result : [];
    }

    private static function parseRawHeaders(string $raw): array
    {
        $headers = [];
        $lines = preg_split("/\r\n|\n|\r/", trim($raw)) ?: [];

        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if (isset($headers[$name])) {
                $headers[$name] = array_merge((array) $headers[$name], [$value]);
            } else {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}

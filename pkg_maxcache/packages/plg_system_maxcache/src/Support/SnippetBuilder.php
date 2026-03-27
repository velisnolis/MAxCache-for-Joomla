<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

\defined('_JEXEC') or die;

final class SnippetBuilder
{
    public static function build(string $mode, array $params): string
    {
        return $mode === 'apache'
            ? self::buildApacheSnippet($params)
            : self::buildModMaxcacheSnippet($params);
    }

    public static function buildApacheSnippet(array $params): string
    {
        $cacheRoot = rtrim((string) ($params['cache_root'] ?? '/var/cache/joomla-maxcache'), '/');
        $cookies = self::buildCookieRegex($params);
        $cookieLine = $cookies !== ''
            ? '    RewriteCond %{HTTP_COOKIE} !(^|;\\s*)(' . $cookies . ')(=|;|$) [NC]'
            : '';

        return trim(<<<HTACCESS
<IfModule mod_rewrite.c>
    RewriteEngine On

    # MAx Cache for Joomla preview snippet
    # Adapt paths to your vhost layout before enabling.
    RewriteCond %{REQUEST_METHOD} =GET
    RewriteCond %{REQUEST_URI} !^/administrator/
    RewriteCond %{QUERY_STRING} ^$
{$cookieLine}
    RewriteCond {$cacheRoot}/%{HTTP_HOST}%{REQUEST_URI}/index-https.html -f
    RewriteRule ^ {$cacheRoot}/%{HTTP_HOST}%{REQUEST_URI}/index-https.html [L]
</IfModule>
HTACCESS);
    }

    public static function buildModMaxcacheSnippet(array $params): string
    {
        $cacheRoot = rtrim((string) ($params['cache_root'] ?? '/var/cache/joomla-maxcache'), '/');
        $cookies = self::buildCookieRegex($params);
        $uriExclusions = self::buildUriExclusions($params);
        $queryParams = self::normalizeLineList((string) ($params['allowed_query_params'] ?? ''));

        $lines = [
            '<IfModule maxcache_module>',
            '    MaxCache On',
            '',
            '    # MAx Cache for Joomla preview snippet',
            '    MaxCachePath ' . $cacheRoot . '/{HTTP_HOST}{REQUEST_URI}{QS_SUFFIX}/index{MOBILE_SUFFIX}{SSL_SUFFIX}.html{GZIP_SUFFIX}',
        ];

        if ($uriExclusions !== '') {
            $lines[] = '    MaxCacheExcludeURI "' . $uriExclusions . '"';
        }

        if ($cookies !== '') {
            $lines[] = '    MaxCacheExcludeCookie "' . $cookies . '"';
        }

        if ($queryParams !== []) {
            $lines[] = '    MaxCacheQSAllowedParams ' . implode(' ', $queryParams);
        }

        $lines[] = '</IfModule>';

        return implode("\n", $lines);
    }

    private static function buildCookieRegex(array $params): string
    {
        $cookies = self::normalizeLineList((string) ($params['bypass_cookies'] ?? ''));

        return implode('|', array_map(static fn (string $cookie): string => preg_quote($cookie, '#'), $cookies));
    }

    private static function buildUriExclusions(array $params): string
    {
        $patterns = self::normalizeLineList((string) ($params['exclude'] ?? ''));

        if ($patterns === []) {
            return '/(?:administrator|api)(?:/.*|$)';
        }

        return implode('|', $patterns);
    }

    private static function normalizeLineList(string $value): array
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = array_map('trim', explode("\n", $value));

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }
}

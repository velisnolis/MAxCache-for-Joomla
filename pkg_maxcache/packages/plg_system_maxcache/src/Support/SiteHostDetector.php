<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

final class SiteHostDetector
{
    public static function detect(string $configured = ''): array
    {
        $configuredHosts = self::normalizeLineList($configured);

        if ($configuredHosts !== []) {
            return $configuredHosts;
        }

        $hosts = [];
        $candidates = [
            Uri::getInstance()->getHost(),
            (string) parse_url(Uri::base(), PHP_URL_HOST),
            (string) parse_url(Uri::root(), PHP_URL_HOST),
        ];

        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim((string) $candidate));

            if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_IP)) {
                continue;
            }

            $hosts[$candidate] = true;

            if (str_starts_with($candidate, 'www.')) {
                $hosts[substr($candidate, 4)] = true;
                continue;
            }

            if (substr_count($candidate, '.') >= 1) {
                $hosts['www.' . $candidate] = true;
            }
        }

        return array_keys($hosts);
    }

    public static function normalizeLineList(string $value): array
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = array_map(static fn (string $line): string => strtolower(trim($line)), explode("\n", $value));

        return array_values(array_unique(array_filter($lines, static fn (string $line): bool => $line !== '')));
    }
}

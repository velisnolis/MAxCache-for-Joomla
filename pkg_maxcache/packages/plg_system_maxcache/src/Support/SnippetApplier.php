<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Support;

\defined('_JEXEC') or die;

/**
 * Applies the generated server block through Admin Tools when it manages .htaccess,
 * or to the plugin's own managed block otherwise.
 */
final class SnippetApplier
{
    /**
     * @param callable(string): void $warn Receives warnings as they arise, before the write.
     *
     * @return string The success message, naming the backups that were created.
     *
     * @throws \Throwable when the block could not be applied
     */
    public static function apply(string $snippet, string $cacheRoot, callable $warn): string
    {
        if (AdminToolsManager::isAvailable()) {
            $result = AdminToolsManager::applySnippet($snippet, $cacheRoot);
            $message = 'MAx Cache snippet applied to the Admin Tools custom .htaccess footer and .htaccess was rebuilt.';

            if (!empty($result['footer_backup_path'])) {
                $message .= ' Footer backup created at ' . $result['footer_backup_path'] . '.';
            }

            if (!empty($result['exceptiondirs_backup_path'])) {
                $message .= ' Admin Tools server-protection exceptions backup created at ' . $result['exceptiondirs_backup_path'] . '.';
            }
        } else {
            $status = HtaccessManager::getStatus($snippet);

            if ($status['akeeba_detected']) {
                $warn('Akeeba Admin Tools markers were detected in .htaccess. Review rule ordering carefully after applying the managed MAx Cache block.');
            }

            $result = HtaccessManager::applySnippet($snippet);
            $message = 'MAx Cache snippet applied to .htaccess.';
        }

        if (!empty($result['backup_path'])) {
            $message .= ' Backup created at ' . $result['backup_path'] . '.';
        }

        return $message;
    }
}

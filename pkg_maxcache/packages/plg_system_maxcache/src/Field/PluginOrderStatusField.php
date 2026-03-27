<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

final class PluginOrderStatusField extends FormField
{
    protected $type = 'PluginOrderStatus';

    protected function getInput(): string
    {
        $status = $this->getOrderingStatus();

        if ($status === null) {
            return '<div class="alert alert-secondary">Plugin ordering status could not be determined.</div>';
        }

        if ($status['is_last']) {
            return '<div class="alert alert-success">'
                . '<strong>Plugin ordering:</strong> MAx Cache is currently the last enabled system plugin.'
                . '</div>';
        }

        $html = [];
        $html[] = '<div class="alert alert-warning">';
        $html[] = '<p><strong>Plugin ordering:</strong> MAx Cache is not configured as the last enabled system plugin.</p>';
        $html[] = '<p>Recommended: set <code>Ordering</code> to <code>- Last -</code> so MAx Cache sees the final response state before writing static output.</p>';
        $html[] = '</div>';

        return implode('', $html);
    }

    private function getOrderingStatus(): ?array
    {
        try {
            /** @var DatabaseInterface $db */
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $extensionId = (int) Factory::getApplication()->getInput()->getInt('extension_id');

            if ($extensionId <= 0) {
                return null;
            }

            $query = $db->getQuery(true)
                ->select([$db->quoteName('extension_id'), $db->quoteName('name'), $db->quoteName('ordering')])
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('extension_id') . ' = ' . $extensionId);

            $db->setQuery($query);
            $current = $db->loadAssoc();

            if (!$current) {
                return null;
            }

            $query = $db->getQuery(true)
                ->select([$db->quoteName('extension_id'), $db->quoteName('name'), $db->quoteName('ordering')])
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('enabled') . ' = 1')
                ->order($db->quoteName('ordering') . ' DESC, ' . $db->quoteName('extension_id') . ' DESC');

            $db->setQuery($query, 0, 1);
            $last = $db->loadAssoc();

            if (!$last) {
                return null;
            }

            return [
                'is_last' => (int) $current['extension_id'] === (int) $last['extension_id'],
            ];
        } catch (\Throwable $exception) {
            return null;
        }
    }
}

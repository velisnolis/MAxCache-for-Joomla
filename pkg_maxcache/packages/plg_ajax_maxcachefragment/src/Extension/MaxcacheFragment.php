<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Ajax.maxcachefragment
 */

namespace Vendor\Plugin\Ajax\MaxcacheFragment\Extension;

use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

final class MaxcacheFragment extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onAjaxMaxcachefragment' => 'onAjaxMaxcachefragment',
        ];
    }

    public function onAjaxMaxcachefragment(AjaxEvent $event): void
    {
        $app = $this->getApplication();

        $app->setHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate', true);
        $app->setHeader('Pragma', 'no-cache', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);

        if (strtoupper((string) $app->getInput()->server->getString('REQUEST_METHOD', 'GET')) !== 'GET') {
            throw new \RuntimeException('MAxCache fragments only accept GET requests.', 405);
        }

        if ($app->getInput()->getCmd('maxcache_task') === 'token') {
            $this->respondWithFormToken($event);
            return;
        }

        $fragmentId = $app->getInput()->getInt('fragment_id');
        $signature = strtolower($app->getInput()->getString('signature', ''));

        if ($fragmentId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            throw new \InvalidArgumentException('Invalid MAxCache fragment request.', 400);
        }

        $fragment = $this->loadPublishedModule($fragmentId);

        if ($fragment === null || (string) $fragment->module !== 'mod_maxcache_fragment') {
            throw new \RuntimeException('MAxCache fragment is not accessible.', 404);
        }

        $this->assertAccessible($fragment);

        $params = new Registry((string) $fragment->params);
        $sourceId = (int) $params->get('source_module_id', 0);
        $expectedSignature = self::sign($fragmentId, $sourceId, (string) Factory::getConfig()->get('secret', ''));

        if ($sourceId <= 0 || !hash_equals($expectedSignature, $signature)) {
            throw new \RuntimeException('MAxCache fragment signature is invalid.', 403);
        }

        $source = $this->loadPublishedModule($sourceId);

        if ($source === null || (string) $source->module === 'mod_maxcache_fragment') {
            throw new \RuntimeException('MAxCache fragment source is not accessible.', 404);
        }

        $this->assertAccessible($source);

        $html = ModuleHelper::renderModule($source, ['style' => 'none']);

        $app->setHeader('X-MAxCache-Fragment', (string) $fragmentId, true);
        $event->addResult($html);
    }

    private function respondWithFormToken(AjaxEvent $event): void
    {
        $app = $this->getApplication();

        // Static pages ship without a token. Same-origin scripts ask for the
        // visitor's own session token just before they send a POST request.
        if ($app->getInput()->server->getString('HTTP_X_REQUESTED_WITH', '') !== 'XMLHttpRequest') {
            throw new \InvalidArgumentException('Invalid MAxCache token request.', 400);
        }

        $app->getDocument()->setMimeEncoding('application/json');
        $event->addResult((string) json_encode(['token' => Session::getFormToken()]));
    }

    private static function sign(int $fragmentId, int $sourceId, string $secret): string
    {
        return hash_hmac('sha256', 'fragment:' . $fragmentId . ':source:' . $sourceId, $secret);
    }

    private function loadPublishedModule(int $moduleId): ?object
    {
        /** @var DatabaseInterface $db */
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select('m.*')
            ->select($db->quoteName('e.custom_data'))
            ->from($db->quoteName('#__modules', 'm'))
            ->join(
                'INNER',
                $db->quoteName('#__extensions', 'e'),
                $db->quoteName('e.element') . ' = ' . $db->quoteName('m.module')
                . ' AND ' . $db->quoteName('e.type') . ' = ' . $db->quote('module')
                . ' AND ' . $db->quoteName('e.client_id') . ' = ' . $db->quoteName('m.client_id')
            )
            ->where($db->quoteName('m.id') . ' = :moduleId')
            ->where($db->quoteName('m.client_id') . ' = 0')
            ->where($db->quoteName('m.published') . ' = 1')
            ->where($db->quoteName('e.enabled') . ' = 1')
            ->bind(':moduleId', $moduleId, ParameterType::INTEGER);

        $db->setQuery($query);
        $module = $db->loadObject();

        return \is_object($module) ? $module : null;
    }

    private function assertAccessible(object $module): void
    {
        $app = $this->getApplication();
        $levels = array_map('intval', $app->getIdentity()->getAuthorisedViewLevels());

        if (!\in_array((int) ($module->access ?? 0), $levels, true)) {
            throw new \RuntimeException('MAxCache fragment module access denied.', 403);
        }

        $moduleLanguage = (string) ($module->language ?? '*');
        $currentLanguage = (string) $app->getLanguage()->getTag();

        if ($moduleLanguage !== '*' && strcasecmp($moduleLanguage, $currentLanguage) !== 0) {
            throw new \RuntimeException('MAxCache fragment module language mismatch.', 404);
        }

        $now = Factory::getDate()->toUnix();

        foreach (['publish_up' => 'after', 'publish_down' => 'before'] as $property => $direction) {
            $value = (string) ($module->{$property} ?? '');

            if ($value === '' || $value === '0000-00-00 00:00:00') {
                continue;
            }

            $timestamp = Factory::getDate($value, 'UTC')->toUnix();

            if (($direction === 'after' && $timestamp > $now) || ($direction === 'before' && $timestamp < $now)) {
                throw new \RuntimeException('MAxCache fragment module is outside its publication window.', 404);
            }
        }
    }
}

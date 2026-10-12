<?php

namespace MaxcacheTest\Plugin\System\Lab\Extension;

use Joomla\CMS\Event\Application\AfterRenderEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\Event\SubscriberInterface;

defined('_JEXEC') or die;

/** Test-only response source. Never package this with MAxCache. */
final class Lab extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onAfterRender' => 'onAfterRender'];
    }

    public function onAfterRender(AfterRenderEvent $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site') || $app->get('sitename') !== 'MAxCache Joomla 6 isolated lab') {
            return;
        }

        $path = (string) parse_url($app->getInput()->server->getString('REQUEST_URI', ''), PHP_URL_PATH);

        if (!preg_match('#/lab-(public|query-only|private|no-store|csrf|message|redirect|json|status-500)$#', $path, $match)) {
            return;
        }

        $case = $match[1];
        $app->setHeader('X-MAxCache-Lab-Origin', bin2hex(random_bytes(8)), true);
        $body = '<!doctype html><html><body><p>lab-' . $case . '</p><p>' . bin2hex(random_bytes(8)) . '</p>';

        if ($case === 'private') {
            $app->setHeader('Cache-Control', 'public, max-age=3600', true);
            $app->setHeader('Cache-Control', 'PrIvAtE="Set-Cookie"', false);
        } elseif ($case === 'no-store') {
            $app->setHeader('Cache-Control', 'public, No-StOrE', true);
        } elseif ($case === 'csrf') {
            $body .= '<form><input type="hidden" name="' . Session::getFormToken() . '" value="1"></form>';
        } elseif ($case === 'message') {
            $app->enqueueMessage('Test-only late message');
        } elseif ($case === 'redirect') {
            $app->setHeader('Location', '/index.php/lab-public', true);
        } elseif ($case === 'json') {
            $app->setHeader('Content-Type', 'application/json', true);
        } elseif ($case === 'status-500') {
            $app->setResponse($app->getResponse()->withStatus(500));
        }

        $app->setBody($body . '</body></html>');
    }
}

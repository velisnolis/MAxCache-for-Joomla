<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.maxcache
 */

namespace Vendor\Plugin\System\Maxcache\Extension;

use Joomla\CMS\Event\Application\AfterRenderEvent;
use Joomla\CMS\Event\Application\AfterRespondEvent;
use Joomla\CMS\Event\Application\AfterRouteEvent;
use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Event\PageCache\GetKeyEvent;
use Joomla\CMS\Event\PageCache\IsExcludedEvent;
use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Router\SiteRouter;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Content\Site\Helper\RouteHelper as ContentRouteHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherAwareInterface;
use Joomla\Event\DispatcherAwareTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;
use Vendor\Plugin\System\Maxcache\Support\AdminToolsManager;
use Vendor\Plugin\System\Maxcache\Support\BuiltInExclusions;
use Vendor\Plugin\System\Maxcache\Support\CachePathBuilder;
use Vendor\Plugin\System\Maxcache\Support\HtaccessManager;
use Vendor\Plugin\System\Maxcache\Support\LanguageRoutingDetector;
use Vendor\Plugin\System\Maxcache\Support\RegularLabsCacheCleanerDetector;
use Vendor\Plugin\System\Maxcache\Support\SiteHostDetector;
use Vendor\Plugin\System\Maxcache\Support\SnippetBuilder;
use Vendor\Plugin\System\Maxcache\Support\SystemCacheSettings;

\defined('_JEXEC') or die;

final class Maxcache extends CMSPlugin implements SubscriberInterface, DispatcherAwareInterface
{
    use DispatcherAwareTrait;

    private ?SiteRouter $router;
    private bool $eligible = false;
    private ?string $targetPath = null;
    private ?string $gzipPath = null;
    private ?string $rejectionReason = null;

    public function __construct(array $config, ?SiteRouter $router = null)
    {
        parent::__construct($config);

        $this->router = $router;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRoute' => 'onAfterRoute',
            'onAfterRender' => 'onAfterRender',
            'onAfterRespond' => 'onAfterRespond',
            'onContentAfterSave' => 'onContentAfterSave',
        ];
    }

    public function onAfterRoute(AfterRouteEvent $event): void
    {
        $this->warnIfPluginOrderingIsNotLast();

        if ($this->handleAdminHtaccessAction()) {
            return;
        }

        $this->handleScheduledFullRegeneration();

        $this->resetRequestState();
        $this->importPagecachePlugins();

        if (!$this->appStateSupportsCaching()) {
            $this->markRejected('app-state');
            return;
        }

        if (!$this->isHtmlRequest()) {
            $this->markRejected('non-html');
            return;
        }

        if ($this->isExcludedMenuItem()) {
            $this->markRejected('excluded-menu-item');
            return;
        }

        if ($this->isExcludedUrl()) {
            $this->markRejected('excluded-url');
            return;
        }

        if ($this->isExcludedByPagecachePlugins()) {
            $this->markRejected('pagecache-plugin');
            return;
        }

        if ($this->hasBypassCookie()) {
            $this->markRejected('bypass-cookie');
            return;
        }

        if ($this->hasReservedBypassQuery()) {
            $this->markRejected('reserved-query');
            return;
        }

        if (!$this->hasAllowedQueryParameters()) {
            $this->markRejected('query-params');
            return;
        }

        $this->targetPath = $this->buildTargetPath();

        if ($this->targetPath === null) {
            $this->markRejected('path-build');
            return;
        }

        $this->gzipPath = $this->params->get('write_gzip', 0) ? $this->targetPath . '_gzip' : null;
        $this->eligible = true;
        $this->emitDebugHeaders('eligible');
    }

    public function onAfterRender(AfterRenderEvent $event): void
    {
        if ($this->shouldRenderAdminPurgeButton()) {
            $this->injectAdminPurgeButton();
        }

        if (!$this->eligible) {
            return;
        }

        // Store a single canonical representation and let the server decide whether
        // to return the plain or precompressed artifact.
        $this->getApplication()->set('gzip', false);
    }

    public function onAfterRespond(AfterRespondEvent $event): void
    {
        if (!$this->eligible || !$this->responseSupportsStaticWrite()) {
            return;
        }

        $body = (string) $this->getApplication()->getBody();

        if ($body === '') {
            return;
        }

        $directory = \dirname($this->targetPath);

        if (!Folder::exists($directory) && !Folder::create($directory)) {
            $this->emitDebugHeaders('mkdir-failed');
            return;
        }

        @file_put_contents($this->targetPath, $body);

        if ($this->gzipPath !== null) {
            $gzip = gzencode($body, 6);

            if ($gzip !== false) {
                @file_put_contents($this->gzipPath, $gzip);
            }
        }

        $this->emitDebugHeaders('stored');
    }

    private function warnIfPluginOrderingIsNotLast(): void
    {
        $app = $this->getApplication();
        $input = $app->getInput();

        if (
            !$app->isClient('administrator')
            || $input->getCmd('option') !== 'com_plugins'
            || !\in_array($input->getCmd('view'), ['plugin', 'plugins'], true)
            || $input->getInt('extension_id') <= 0
        ) {
            return;
        }

        if ($input->getInt('extension_id') !== (int) ($this->_subject->id ?? 0)) {
            return;
        }

        try {
            /** @var DatabaseInterface $db */
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('maxcache'));

            $db->setQuery($query);
            $maxcacheExtensionId = (int) $db->loadResult();

            if ($maxcacheExtensionId <= 0 || $input->getInt('extension_id') !== $maxcacheExtensionId) {
                return;
            }

            $query = $db->getQuery(true)
                ->select([$db->quoteName('extension_id'), $db->quoteName('name'), $db->quoteName('ordering')])
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('enabled') . ' = 1')
                ->order($db->quoteName('ordering') . ' DESC, ' . $db->quoteName('extension_id') . ' DESC');

            $db->setQuery($query, 0, 1);
            $last = $db->loadObject();

            if (!$last || (int) $last->extension_id === $maxcacheExtensionId) {
                return;
            }

            $app->enqueueMessage(
                'MAx Cache should usually be the last enabled system plugin. Set Ordering to - Last - so it sees the final response before writing static output.',
                'warning'
            );
        } catch (\Throwable $exception) {
            // Ignore admin hint failures.
        }
    }

    public function onContentAfterSave(AfterSaveEvent $event): void
    {
        $context = $event->getContext();
        $item = $event->getItem();

        if (!\in_array($context, ['com_content.article', 'com_menus.item'], true) || !\is_object($item)) {
            return;
        }

        if ($this->params->get('purge_strategy', 'targeted') === 'full') {
            if ($this->usesRegularLabsAutomation()) {
                return;
            }

            if (!(int) $this->params->get('full_regenerate_on_save', 1)) {
                return;
            }

            $this->purgeAll();
            return;
        }

        $paths = $this->resolvePurgePaths($context, $item);

        if ($paths === []) {
            $alias = isset($item->alias) && \is_string($item->alias) ? trim($item->alias) : '';

            if ($alias !== '') {
                $this->purgeAlias($alias);
                return;
            }

            $this->purgeAll();
            return;
        }

        $this->purgePaths($paths);
    }

    private function resetRequestState(): void
    {
        $this->eligible = false;
        $this->targetPath = null;
        $this->gzipPath = null;
        $this->rejectionReason = null;
    }

    private function handleAdminHtaccessAction(): bool
    {
        $app = $this->getApplication();
        $input = $app->getInput();

        if (
            !$app->isClient('administrator')
            || !\in_array($input->getCmd('maxcache_action'), ['apply_snippet', 'purge_cache', 'enable_regularlabs', 'disable_regularlabs'], true)
        ) {
            return false;
        }

        $action = $input->getCmd('maxcache_action');
        $hasValidToken = \in_array($action, ['enable_regularlabs', 'disable_regularlabs'], true)
            ? (Session::checkToken('post') || Session::checkToken('get'))
            : Session::checkToken('post');

        if (!$hasValidToken) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'));
        }

        if ($action === 'purge_cache') {
            $this->purgeAll();

            $message = 'MAx Cache static cache was fully purged.';
            $app->setUserState('plg_system_maxcache.last_purge_result', [
                'message' => $message,
                'type' => 'success',
                'time' => time(),
            ]);
            $app->enqueueMessage($message, 'message');
            $app->redirect($this->getAdminReturnUrl());
            $app->close();

            return true;
        }

        if (\in_array($action, ['enable_regularlabs', 'disable_regularlabs'], true)) {
            $status = RegularLabsCacheCleanerDetector::detect(
                (string) $this->params->get('cache_root', '/var/cache/joomla-maxcache')
            );

            if ($action === 'enable_regularlabs') {
                if (($status['state'] ?? 'unknown') !== 'active' || !($status['cache_root_is_public'] ?? false)) {
                    $app->enqueueMessage(
                        'Regular Labs integration can only be enabled when Cache Cleaner is active and the static cache root is inside the public web root.',
                        'warning'
                    );
                } else {
                    $this->configureRegularLabsCacheCleaner($status);
                    $this->updatePluginParams([
                        'purge_automation_driver' => 'regularlabs',
                        'full_regenerate_on_save' => 0,
                        'full_regenerate_on_interval' => 0,
                    ]);
                    $app->enqueueMessage(
                        'MAx Cache now delegates full regeneration to Regular Labs Cache Cleaner. The MAx Cache folder was added to Cache Cleaner custom folders and the current MAx Cache save/interval settings were copied over.',
                        'message'
                    );
                }
            } else {
                $this->updatePluginParams([
                    'purge_automation_driver' => 'maxcache',
                ]);
                $app->enqueueMessage(
                    'MAx Cache full regeneration is managed by MAx Cache again.',
                    'message'
                );
            }

            $app->redirect(Route::_('index.php?option=com_plugins&task=plugin.edit&extension_id=' . $input->getInt('extension_id'), false));
            $app->close();

            return true;
        }

        if ($input->getCmd('option') !== 'com_plugins') {
            return false;
        }

        try {
            $snippet = SnippetBuilder::build(
                (string) $this->params->get('server_snippet_mode', 'mod_maxcache'),
                [
                    'cache_root' => $this->params->get('cache_root', '/var/cache/joomla-maxcache'),
                    'site_hosts' => $this->params->get('site_hosts', ''),
                    'exclude' => implode("\n", $this->getEffectiveCustomExcludePatterns()),
                    'bypass_cookies' => $this->params->get('bypass_cookies', ''),
                    'allowed_query_params' => $this->params->get('allowed_query_params', ''),
                ]
            );

            if (AdminToolsManager::isAvailable()) {
                $result = AdminToolsManager::applySnippet(
                    $snippet,
                    (string) $this->params->get('cache_root', '/var/cache/joomla-maxcache')
                );
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
                    $app->enqueueMessage('Akeeba Admin Tools markers were detected in .htaccess. Review rule ordering carefully after applying the managed MAx Cache block.', 'warning');
                }

                $result = HtaccessManager::applySnippet($snippet);
                $message = 'MAx Cache snippet applied to .htaccess.';
            }

            if (!empty($result['backup_path'])) {
                $message .= ' Backup created at ' . $result['backup_path'] . '.';
            }

            $app->setUserState('plg_system_maxcache.last_apply_result', [
                'message' => $message,
                'type' => 'success',
                'time' => time(),
            ]);
            $app->enqueueMessage($message, 'message');

            $guestSessionWarning = $this->getGuestSessionTrackingWarning();

            if ($guestSessionWarning !== null) {
                $app->enqueueMessage($guestSessionWarning, 'warning');
            }
        } catch (\Throwable $exception) {
            $message = 'Could not apply the MAx Cache snippet: ' . $exception->getMessage();
            $app->setUserState('plg_system_maxcache.last_apply_result', [
                'message' => $message,
                'type' => 'error',
                'time' => time(),
            ]);
            $app->enqueueMessage($message, 'error');
        }

        $app->redirect(Route::_('index.php?option=com_plugins&task=plugin.edit&extension_id=' . $input->getInt('extension_id'), false));
        $app->close();

        return true;
    }

    private function shouldRenderAdminPurgeButton(): bool
    {
        $app = $this->getApplication();
        $input = $app->getInput();

        if (!$app->isClient('administrator')) {
            return false;
        }

        $identity = $app->getIdentity();

        if (!$identity || $identity->guest) {
            return false;
        }

        if ($input->getCmd('option') === 'com_login') {
            return false;
        }

        $body = (string) $app->getBody();

        if ($body === '' || stripos($body, '</body>') === false) {
            return false;
        }

        $detector = RegularLabsCacheCleanerDetector::detect(
            (string) $this->params->get('cache_root', '/var/cache/joomla-maxcache')
        );

        if (($detector['state'] ?? 'unknown') === 'active') {
            return false;
        }

        return true;
    }

    private function injectAdminPurgeButton(): void
    {
        $app = $this->getApplication();
        $body = (string) $app->getBody();

        if (stripos($body, 'data-maxcache-purge-action') !== false) {
            return;
        }

        $token = Session::getFormToken();
        $action = Route::_('index.php', false);
        $confirm = htmlspecialchars(
            'Purge all MAx Cache static files now? This will remove the entire static cache directory and the next public requests will rebuild it.',
            ENT_QUOTES,
            'UTF-8'
        );
        $snippetWarning = $this->getSnippetApplyWarning();
        $warningTitle = $snippetWarning !== null
            ? htmlspecialchars($snippetWarning, ENT_QUOTES, 'UTF-8')
            : '';
        $headerWarning = $snippetWarning !== null
            ? '<span class="badge bg-warning text-dark ms-1" title="' . $warningTitle . '">!</span>'
            : '';
        $buttonClass = $snippetWarning !== null ? 'btn btn-warning' : 'btn btn-danger';
        $floatingWarning = $snippetWarning !== null
            ? '<div class="small text-dark bg-warning p-2 mt-2 rounded" style="max-width:260px;">' . $warningTitle . '</div>'
            : '';

        $styleMarkup = <<<HTML
<style data-maxcache-purge-action-style>
@media (min-width: 1200px) {
  [data-maxcache-purge-action="floating"] { display: none !important; }
}
@media (max-width: 1199.98px) {
  [data-maxcache-purge-action="floating"] { display: block !important; }
}
</style>
HTML;

        $headerMarkup = <<<HTML
<div class="header-item" data-maxcache-purge-action="header">
  <a href="javascript:" onclick="return document.getElementById('maxcache-purge-form')?.requestSubmit();" class="header-item-content" title="Purge MAx Cache">
    <div class="header-item-icon">
      <span class="icon-trash" aria-hidden="true"></span>
    </div>
    <div class="header-item-text">Purge MAx Cache{$headerWarning}</div>
  </a>
  <form id="maxcache-purge-form" method="post" action="{$action}" onsubmit="return confirm('{$confirm}');" style="display:none;">
    <input type="hidden" name="maxcache_action" value="purge_cache">
    <input type="hidden" name="{$token}" value="1">
  </form>
</div>
HTML;

        $floatingMarkup = <<<HTML
<div data-maxcache-purge-action="floating" style="display:none;position:fixed;right:18px;bottom:18px;z-index:1080;">
  <form method="post" action="{$action}" onsubmit="return confirm('{$confirm}');" style="margin:0;">
    <input type="hidden" name="maxcache_action" value="purge_cache">
    <input type="hidden" name="{$token}" value="1">
    <button type="submit" class="{$buttonClass}">
      Purge MAx Cache
    </button>
    {$floatingWarning}
  </form>
</div>
HTML;

        $updated = preg_replace(
            '#<div class="header-items d-flex ms-auto">#i',
            '$0' . "\n" . $headerMarkup,
            $body,
            1,
            $headerMatches
        );

        $body = ($headerMatches ?? 0) > 0 ? (string) $updated : $body;

        $app->setBody((string) preg_replace('#</body>#i', $styleMarkup . "\n" . $floatingMarkup . "\n</body>", $body, 1));
    }

    private function getSnippetApplyWarning(): ?string
    {
        try {
            $snippet = $this->buildCurrentSnippet();
            $status = AdminToolsManager::isAvailable()
                ? AdminToolsManager::getStatus($snippet)
                : HtaccessManager::getStatus($snippet);
            $state = (string) ($status['state'] ?? 'unknown');

            return match ($state) {
                'applied' => null,
                'outdated' => 'MAx Cache server snippet is applied but outdated. Purge clears files, but update the snippet so Apache serves the current rules.',
                'not_applied' => 'MAx Cache server snippet is not applied. Purge clears files, but Apache will not serve MAx Cache until you apply the snippet.',
                default => 'MAx Cache server snippet status could not be verified. Purge clears files, but verify server integration before relying on static delivery.',
            };
        } catch (\Throwable $exception) {
            return 'MAx Cache server snippet status could not be verified. Purge clears files, but verify server integration before relying on static delivery.';
        }
    }

    private function buildCurrentSnippet(): string
    {
        return SnippetBuilder::build(
            (string) $this->params->get('server_snippet_mode', 'mod_maxcache'),
            [
                'cache_root' => $this->params->get('cache_root', '/var/cache/joomla-maxcache'),
                'site_hosts' => $this->params->get('site_hosts', ''),
                'exclude' => implode("\n", $this->getEffectiveCustomExcludePatterns()),
                'bypass_cookies' => $this->params->get('bypass_cookies', ''),
                'allowed_query_params' => $this->params->get('allowed_query_params', ''),
            ]
        );
    }

    private function getAdminReturnUrl(): string
    {
        $referer = (string) $this->getApplication()->getInput()->server->getString('HTTP_REFERER', '');

        if ($referer !== '' && str_starts_with($referer, Uri::root())) {
            return $referer;
        }

        return Route::_('index.php', false);
    }

    private function appStateSupportsCaching(): bool
    {
        static $isSite = null;
        static $isGet = null;

        $app = $this->getApplication();

        if ($isSite === null) {
            $isSite = $app->isClient('site');
            $isGet = $app->getInput()->getMethod() === 'GET';
        }

        return $isSite
            && $isGet
            && $app->getIdentity()->guest
            && empty($app->getMessageQueue());
    }

    private function isHtmlRequest(): bool
    {
        $input = $this->getApplication()->getInput();

        if ($input->getCmd('format', 'html') !== 'html') {
            return false;
        }

        return !str_starts_with(Uri::getInstance()->getPath(), '/api/');
    }

    private function isExcludedMenuItem(): bool
    {
        $excludedMenuItems = SystemCacheSettings::mergeMenuItems((array) $this->params->get('exclude_menu_items', []));

        if ($excludedMenuItems === []) {
            return false;
        }

        $active = $this->getApplication()->getMenu()->getActive();

        return $active && $active->id && \in_array((int) $active->id, $excludedMenuItems, true);
    }

    private function isExcludedUrl(): bool
    {
        $exclusions = array_values(array_unique(array_merge(
            BuiltInExclusions::getRuntimePatterns(),
            $this->getEffectiveCustomExcludePatterns()
        )));

        $externalUrl = Uri::getInstance()->toString();
        $internalUrl = '/index.php';

        if ($this->router !== null) {
            $internalUrl .= '?' . Uri::buildQuery($this->router->getVars());
        }

        foreach ($exclusions as $pattern) {
            if (@preg_match('#' . $pattern . '#i', $externalUrl . ' ' . $internalUrl)) {
                return true;
            }
        }

        return false;
    }

    private function hasBypassCookie(): bool
    {
        $cookies = $this->getApplication()->getInput()->cookie;
        $configured = $this->normalizeLineList((string) $this->params->get('bypass_cookies', ''));
        $sessionName = $this->getApplication()->getSession()->getName();

        if ($sessionName !== '') {
            $configured[] = $sessionName;
        }

        foreach (array_unique($configured) as $cookieName) {
            if ($cookies->get($cookieName, null) !== null) {
                return true;
            }
        }

        return false;
    }

    private function hasAllowedQueryParameters(): bool
    {
        $query = Uri::getInstance()->getQuery(true);

        if ($query === []) {
            return true;
        }

        $allowed = $this->normalizeLineList((string) $this->params->get('allowed_query_params', ''));

        if ($allowed === []) {
            return false;
        }

        foreach (array_keys($query) as $key) {
            if (!\in_array((string) $key, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    private function hasReservedBypassQuery(): bool
    {
        $query = Uri::getInstance()->getQuery(true);

        if ($query === []) {
            return false;
        }

        return strtolower((string) ($query['p'] ?? '')) === 'customizer';
    }

    private function buildTargetPath(): ?string
    {
        $uri = Uri::getInstance();
        $server = $this->getApplication()->getInput()->server;
        $host = strtolower((string) ($server->getString('HTTP_HOST', '') ?: $uri->getHost() ?: 'site'));

        $knownHosts = $this->getKnownHosts();

        if ($knownHosts !== ['site'] && !\in_array($host, $knownHosts, true)) {
            return null;
        }

        $requestUri = (string) $server->getString('REQUEST_URI', '');
        $requestPath = (string) (parse_url($requestUri, PHP_URL_PATH) ?: $uri->getPath());

        return CachePathBuilder::build([
            'cache_root' => $this->params->get('cache_root', '/var/cache/joomla-maxcache'),
            'host' => $host,
            'request_path' => $requestPath,
            'scheme' => $uri->getScheme(),
            'vary_language' => (int) $this->params->get('vary_language', 1),
            'vary_mobile' => (int) $this->params->get('vary_mobile', 0),
            'is_mobile' => $this->isMobileRequest(),
            'variant_suffix' => $this->getPagecacheVariantSuffix(),
            'language_sefs' => LanguageRoutingDetector::getPublishedLanguageSefs(),
        ]);
    }

    private function getEffectiveCustomExcludePatterns(): array
    {
        return SystemCacheSettings::mergeUrlPatterns(BuiltInExclusions::filterCustomPatterns(
            $this->normalizeLineList((string) $this->params->get('exclude', ''))
        ));
    }

    private function isMobileRequest(): bool
    {
        $userAgent = (string) $this->getApplication()->getInput()->server->getString('HTTP_USER_AGENT', '');

        return (bool) preg_match('#Mobile|Android|iPhone|iPad|Opera Mini|IEMobile#i', $userAgent);
    }

    private function responseSupportsStaticWrite(): bool
    {
        $headers = $this->getApplication()->getHeaders();
        $hasLocation = false;
        $status = '200';
        $contentType = 'text/html';

        foreach ($headers as $header) {
            $name = strtolower((string) ($header['name'] ?? ''));
            $value = (string) ($header['value'] ?? '');

            if ($name === 'location' && $value !== '') {
                $hasLocation = true;
            }

            if ($name === 'status' && $value !== '') {
                $status = $value;
            }

            if ($name === 'content-type' && $value !== '') {
                $contentType = $value;
            }
        }

        return !$hasLocation
            && str_starts_with($status, '200')
            && str_contains(strtolower($contentType), 'text/html');
    }

    private function importPagecachePlugins(): void
    {
        PluginHelper::importPlugin('pagecache', null, true, $this->getDispatcher());
    }

    private function isExcludedByPagecachePlugins(): bool
    {
        $results = $this->getDispatcher()
            ->dispatch('onPageCacheIsExcluded', new IsExcludedEvent('onPageCacheIsExcluded'))
            ->getArgument('result', []);

        return \in_array(true, $results, true);
    }

    private function getPagecacheVariantSuffix(): string
    {
        $parts = $this->getDispatcher()
            ->dispatch('onPageCacheGetKey', new GetKeyEvent('onPageCacheGetKey'))
            ->getArgument('result', []);

        if ($parts === []) {
            return '';
        }

        return substr(md5(serialize($parts)), 0, 10);
    }

    private function purgeAll(): void
    {
        $root = rtrim((string) $this->params->get('cache_root', '/var/cache/joomla-maxcache'), '/');

        if ($root !== '' && self::isSafeCacheRoot($root) && Folder::exists($root)) {
            Folder::delete($root);
        }

        $this->touchFullPurgeMarker();
    }

    private function handleScheduledFullRegeneration(): void
    {
        if ((string) $this->params->get('purge_strategy', 'targeted') !== 'full') {
            return;
        }

        if ($this->usesRegularLabsAutomation()) {
            return;
        }

        if (!(int) $this->params->get('full_regenerate_on_interval', 0)) {
            return;
        }

        $interval = max(60, (int) $this->params->get('full_regenerate_interval_seconds', 3600));
        $markerPath = $this->getFullPurgeMarkerPath();
        $lastRun = is_file($markerPath) ? (int) @file_get_contents($markerPath) : 0;

        if ($lastRun <= 0) {
            $this->touchFullPurgeMarker(time());
            return;
        }

        if ((time() - $lastRun) < $interval) {
            return;
        }

        $this->purgeAll();
    }

    private function usesRegularLabsAutomation(): bool
    {
        if ((string) $this->params->get('purge_automation_driver', 'maxcache') !== 'regularlabs') {
            return false;
        }

        $status = RegularLabsCacheCleanerDetector::detect(
            (string) $this->params->get('cache_root', '/var/cache/joomla-maxcache')
        );

        return ($status['state'] ?? 'unknown') === 'active' && (bool) ($status['cache_root_is_public'] ?? false);
    }

    private function getFullPurgeMarkerPath(): string
    {
        return rtrim((string) JPATH_CACHE, '/') . '/plg_system_maxcache_full_purge.timestamp';
    }

    private function touchFullPurgeMarker(?int $timestamp = null): void
    {
        $timestamp ??= time();
        @file_put_contents($this->getFullPurgeMarkerPath(), (string) $timestamp, LOCK_EX);
    }

    private function updatePluginParams(array $overrides): void
    {
        /** @var DatabaseInterface $db */
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $extensionId = $this->getOwnExtensionId($db);

        if ($extensionId <= 0) {
            throw new \RuntimeException('Could not determine the MAx Cache extension id.');
        }

        $registry = new Registry($this->params->toArray());

        foreach ($overrides as $key => $value) {
            $registry->set((string) $key, $value);
        }

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = ' . $db->quote((string) $registry))
            ->where($db->quoteName('extension_id') . ' = ' . (int) $extensionId);

        $db->setQuery($query);
        $db->execute();
        $this->params = $registry;
    }

    private function configureRegularLabsCacheCleaner(array $status): void
    {
        /** @var DatabaseInterface $db */
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select([$db->quoteName('extension_id'), $db->quoteName('params')])
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('cachecleaner'));

        $db->setQuery($query);
        $row = $db->loadAssoc();

        if (!$row || empty($row['extension_id'])) {
            throw new \RuntimeException('Could not locate the Regular Labs Cache Cleaner plugin.');
        }

        $cacheCleanerParams = new Registry((string) ($row['params'] ?? '{}'));
        $relativeFolder = ltrim((string) ($status['recommended_path'] ?? '/maxcache'), '/');
        $folders = $this->normalizeLineList((string) $cacheCleanerParams->get('clean_folders_selection', ''));

        if ($relativeFolder !== '' && !\in_array($relativeFolder, $folders, true)) {
            $folders[] = $relativeFolder;
        }

        $cacheCleanerParams->set('clean_folders', 1);
        $cacheCleanerParams->set('clean_folders_selection', implode("\n", $folders));
        $cacheCleanerParams->set('clean_folders_min_age', 0);
        $cacheCleanerParams->set('auto_save_admin', (int) $this->params->get('full_regenerate_on_save', 1));
        $cacheCleanerParams->set('auto_save_admin_msg', 0);
        $cacheCleanerParams->set('auto_save_front', 0);
        $cacheCleanerParams->set('auto_save_front_msg', 0);
        $cacheCleanerParams->set('auto_interval_admin', (int) $this->params->get('full_regenerate_on_interval', 0));
        $cacheCleanerParams->set(
            'auto_interval_admin_secs',
            max(60, (int) $this->params->get('full_regenerate_interval_seconds', 3600))
        );
        $cacheCleanerParams->set('auto_interval_admin_msg', 0);

        $update = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = ' . $db->quote((string) $cacheCleanerParams))
            ->where($db->quoteName('extension_id') . ' = ' . (int) $row['extension_id']);

        $db->setQuery($update);
        $db->execute();
    }

    private function getOwnExtensionId(DatabaseInterface $db): int
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('maxcache'));

        $db->setQuery($query);

        return (int) $db->loadResult();
    }

    private static function isSafeCacheRoot(string $path): bool
    {
        $real = realpath($path);

        if ($real === false) {
            return true;
        }

        $dangerous = ['/', '/tmp', '/var', '/etc', '/home', '/root', '/usr', '/bin', '/sbin', '/opt', '/boot', '/dev', '/proc', '/sys', '/run', '/var/log'];

        if (\in_array($real, $dangerous, true)) {
            return false;
        }

        if (\defined('JPATH_ROOT') && $real === realpath(JPATH_ROOT)) {
            return false;
        }

        return true;
    }

    private function purgeAlias(string $alias): void
    {
        $root = rtrim((string) $this->params->get('cache_root', '/var/cache/joomla-maxcache'), '/');

        if ($root === '' || !Folder::exists($root)) {
            return;
        }

        $safeAlias = $this->sanitizePathSegment($alias);
        $directories = Folder::folders($root, '.', true, true);

        foreach ($directories as $directory) {
            if (basename($directory) === $safeAlias) {
                Folder::delete($directory);
            }
        }
    }

    private function purgePaths(array $paths): void
    {
        $root = rtrim((string) $this->params->get('cache_root', '/var/cache/joomla-maxcache'), '/');

        if ($root === '' || !Folder::exists($root)) {
            return;
        }

        $hosts = $this->getKnownHosts();

        foreach ($paths as $path) {
            $segments = array_values(array_filter(explode('/', trim((string) $path, '/')), 'strlen'));

            if ($segments === []) {
                foreach ($hosts as $host) {
                    $directory = $root . '/' . $this->sanitizePathSegment($host);

                    if (Folder::exists($directory)) {
                        Folder::delete($directory);
                    }
                }

                continue;
            }

            foreach ($hosts as $host) {
                $directory = $root . '/' . $this->sanitizePathSegment($host);

                foreach ($segments as $segment) {
                    $directory .= '/' . $this->sanitizePathSegment($segment);
                }

                if (Folder::exists($directory)) {
                    Folder::delete($directory);
                }
            }
        }
    }

    private function getKnownHosts(): array
    {
        $detected = SiteHostDetector::detect((string) $this->params->get('site_hosts', ''));

        return $detected !== [] ? $detected : ['site'];
    }

    private function resolvePurgePaths(string $context, object $item): array
    {
        $paths = [];

        if ($context === 'com_menus.item') {
            if (isset($item->path) && \is_string($item->path) && trim($item->path) !== '') {
                $paths[] = '/' . trim($item->path, '/');
            }

            if (isset($item->home) && (int) $item->home === 1) {
                $paths[] = '/';
            }

            return array_values(array_unique($paths));
        }

        if ($context === 'com_content.article') {
            if (
                class_exists(ContentRouteHelper::class)
                && isset($item->id, $item->catid)
            ) {
                $route = ContentRouteHelper::getArticleRoute((int) $item->id, (int) $item->catid, $item->language ?? '*');
                $paths[] = parse_url(Route::_($route), PHP_URL_PATH) ?: '';
            }

            $paths = array_merge($paths, $this->resolveMenuPathsForArticle((int) ($item->id ?? 0)));

            if (isset($item->alias) && \is_string($item->alias) && trim($item->alias) !== '') {
                $paths[] = '/' . trim($item->alias, '/');
            }
        }

        return array_values(array_unique(array_filter($paths, static fn ($path): bool => \is_string($path))));
    }

    private function resolveMenuPathsForArticle(int $articleId): array
    {
        if ($articleId <= 0) {
            return [];
        }

        try {
            /** @var DatabaseInterface $db */
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select($db->quoteName('path'))
                ->from($db->quoteName('#__menu'))
                ->where($db->quoteName('published') . ' = 1')
                ->where($db->quoteName('path') . ' <> ' . $db->quote(''))
                ->where('('
                    . $db->quoteName('link') . ' LIKE ' . $db->quote('%view=article%&id=' . $articleId)
                    . ' OR '
                    . $db->quoteName('link') . ' LIKE ' . $db->quote('%view=article%&id=' . $articleId . '&%')
                    . ')');

            $db->setQuery($query);

            return array_map(static fn (string $path): string => '/' . trim($path, '/'), (array) $db->loadColumn());
        } catch (\Throwable $exception) {
            return [];
        }
    }

    private function sanitizePathSegment(string $segment): string
    {
        return CachePathBuilder::sanitizePathSegment($segment);
    }

    private function normalizeLineList(string $value): array
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = array_map('trim', explode("\n", $value));

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    private function getGuestSessionTrackingWarning(): ?string
    {
        try {
            if (!(int) Factory::getConfig()->get('session_metadata_for_guest', 0)) {
                return null;
            }

            $sessionName = (string) $this->getApplication()->getSession()->getName();
            $cookieLabel = $sessionName !== '' ? ' (' . $sessionName . ')' : '';

            return 'Guest Session Tracking is enabled in Joomla Global Configuration. Anonymous visitors may receive the Joomla session cookie'
                . $cookieLabel
                . ', and MAx Cache will bypass those requests until guest session tracking is disabled.';
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function markRejected(string $reason): void
    {
        $this->rejectionReason = $reason;
        $this->emitDebugHeaders('bypass');
    }

    private function emitDebugHeaders(string $state): void
    {
        if (!(int) $this->params->get('debug_headers', 0)) {
            return;
        }

        $app = $this->getApplication();

        $app->setHeader('X-MAxCache-State', $state, true);

        if ($this->rejectionReason !== null) {
            $app->setHeader('X-MAxCache-Reason', $this->rejectionReason, true);
        }

        if ($this->targetPath !== null) {
            $app->setHeader('X-MAxCache-Path', $this->targetPath, true);
        }
    }
}

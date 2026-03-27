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
use Vendor\Plugin\System\Maxcache\Support\HtaccessManager;
use Vendor\Plugin\System\Maxcache\Support\SnippetBuilder;

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
        if ($this->handleAdminHtaccessAction()) {
            return;
        }

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

        if (!$this->hasAllowedQueryParameters()) {
            $this->markRejected('query-params');
            return;
        }

        $this->targetPath = $this->buildTargetPath();

        if ($this->targetPath === null) {
            $this->markRejected('path-build');
            return;
        }

        $this->gzipPath = $this->params->get('write_gzip', 0) ? $this->targetPath . '.gz' : null;
        $this->eligible = true;
        $this->emitDebugHeaders('eligible');
    }

    public function onAfterRender(AfterRenderEvent $event): void
    {
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

    public function onContentAfterSave(AfterSaveEvent $event): void
    {
        $context = $event->getContext();
        $item = $event->getItem();

        if (!\in_array($context, ['com_content.article', 'com_menus.item'], true) || !\is_object($item)) {
            return;
        }

        if ($this->params->get('purge_strategy', 'targeted') === 'full') {
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
            || $input->getCmd('option') !== 'com_plugins'
            || $input->getCmd('maxcache_action') !== 'apply_snippet'
        ) {
            return false;
        }

        if (!Session::checkToken()) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'));
        }

        $snippet = SnippetBuilder::build(
            (string) $this->params->get('server_snippet_mode', 'mod_maxcache'),
            [
                'cache_root' => $this->params->get('cache_root', '/var/cache/joomla-maxcache'),
                'site_hosts' => $this->params->get('site_hosts', ''),
                'exclude' => $this->params->get('exclude', ''),
                'bypass_cookies' => $this->params->get('bypass_cookies', ''),
                'allowed_query_params' => $this->params->get('allowed_query_params', ''),
            ]
        );

        $status = HtaccessManager::getStatus($snippet);

        if ($status['akeeba_detected']) {
            $app->enqueueMessage('Akeeba Admin Tools markers were detected in .htaccess. Review rule ordering carefully after applying the managed MAx Cache block.', 'warning');
        }

        $result = HtaccessManager::applySnippet($snippet);

        $message = 'MAx Cache snippet applied to .htaccess.';

        if ($result['backup_path']) {
            $message .= ' Backup created at ' . $result['backup_path'] . '.';
        }

        $app->enqueueMessage($message, 'message');
        $app->redirect(Route::_('index.php?option=com_plugins&task=plugin.edit&extension_id=' . $input->getInt('extension_id'), false));
        $app->close();

        return true;
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
        $excludedMenuItems = (array) $this->params->get('exclude_menu_items', []);

        if ($excludedMenuItems === []) {
            return false;
        }

        $active = $this->getApplication()->getMenu()->getActive();

        return $active && $active->id && \in_array((int) $active->id, $excludedMenuItems, true);
    }

    private function isExcludedUrl(): bool
    {
        $exclusions = $this->normalizeLineList((string) $this->params->get('exclude', ''));

        if ($exclusions === []) {
            return false;
        }

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

    private function buildTargetPath(): ?string
    {
        $root = rtrim((string) $this->params->get('cache_root', '/var/cache/joomla-maxcache'), '/');
        $uri = Uri::getInstance();
        $host = strtolower((string) ($uri->getHost() ?: $this->getApplication()->getInput()->server->getString('HTTP_HOST', 'site')));

        if ($root === '' || $host === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim((string) $uri->getPath(), '/')), 'strlen'));
        $pathMode = (string) $this->params->get('path_mode', 'host-language-sef');
        $parts = [$root, $this->sanitizePathSegment($host)];

        if ((int) $this->params->get('vary_language', 1) === 1) {
            $languageSegment = $this->detectLanguageSegment($segments);

            if ($languageSegment !== '') {
                $parts[] = $languageSegment;

                if ($pathMode === 'host-language-sef' && $segments !== []) {
                    array_shift($segments);
                }
            }
        }

        foreach ($segments as $segment) {
            $parts[] = $this->sanitizePathSegment($segment);
        }

        $filename = 'index';

        if ((int) $this->params->get('vary_mobile', 0) === 1 && $this->isMobileRequest()) {
            $filename .= '-mobile';
        }

        if (strtolower((string) $uri->getScheme()) === 'https') {
            $filename .= '-https';
        }

        $variantSuffix = $this->getPagecacheVariantSuffix();

        if ($variantSuffix !== '') {
            $filename .= '-v-' . $variantSuffix;
        }

        $filename .= '.html';

        return implode('/', $parts) . '/' . $filename;
    }

    private function detectLanguageSegment(array $segments): string
    {
        if ($segments !== []) {
            $first = strtolower((string) $segments[0]);

            if ((bool) preg_match('#^[a-z]{2}(?:-[a-z]{2})?$#', $first)) {
                return $first;
            }
        }

        $tag = strtolower((string) $this->getApplication()->getLanguage()->getTag());

        if ($tag === '') {
            return '';
        }

        return explode('-', $tag)[0];
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

        if ($root !== '' && Folder::exists($root)) {
            Folder::delete($root);
        }
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
        $configured = $this->normalizeLineList((string) $this->params->get('site_hosts', ''));

        if ($configured !== []) {
            return $configured;
        }

        $host = Uri::getInstance()->getHost();

        if ($host !== '') {
            return [$host];
        }

        return ['site'];
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
                ->where($db->quoteName('link') . ' LIKE ' . $db->quote('%view=article%id=' . $articleId . '%'));

            $db->setQuery($query);

            return array_map(static fn (string $path): string => '/' . trim($path, '/'), (array) $db->loadColumn());
        } catch (\Throwable $exception) {
            return [];
        }
    }

    private function sanitizePathSegment(string $segment): string
    {
        $segment = rawurlencode(rawurldecode(trim($segment)));

        return $segment === '' ? 'index' : $segment;
    }

    private function normalizeLineList(string $value): array
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = array_map('trim', explode("\n", $value));

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
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

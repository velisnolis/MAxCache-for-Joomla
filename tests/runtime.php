<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/joomla-runtime.php';

use Joomla\CMS\Event\Application\AfterRenderEvent;
use Joomla\CMS\Event\Application\AfterRouteEvent;
use Joomla\CMS\Event\Application\BeforeRespondEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use MaxcacheTests\Application;
use MaxcacheTests\ApplicationClosed;
use MaxcacheTests\Identity;
use MaxcacheTests\Input;
use Vendor\Plugin\System\Maxcache\Extension\Maxcache;
use Vendor\Plugin\System\Maxcache\Support\AtomicFileWriter;
use Vendor\Plugin\System\Maxcache\Support\CacheOperations;
use Vendor\Plugin\System\Maxcache\Support\DirectoryOperations;
use Vendor\Plugin\System\Maxcache\Support\SnippetBuilder;

define('_JEXEC', 1);
define('JPATH_ROOT', sys_get_temp_dir() . '/maxcache-runtime-' . bin2hex(random_bytes(8)));
define('JPATH_ADMINISTRATOR', JPATH_ROOT . '/administrator');
define('JPATH_CACHE', JPATH_ROOT . '/administrator/cache');
mkdir(JPATH_CACHE, 0700, true);
register_shutdown_function(static fn () => DirectoryOperations::delete(JPATH_ROOT));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Vendor\\Plugin\\System\\Maxcache\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/'
            . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

$failures = [];
$assertions = 0;

function check(string $label, mixed $expected, mixed $actual): void
{
    global $failures, $assertions;
    ++$assertions;
    if ($expected !== $actual) {
        $failures[] = $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
}

function pluginFor(Application $app, array $params = []): Maxcache
{
    Factory::$application = $app;
    $plugin = new Maxcache(['params' => $params + [
        'cache_root' => JPATH_ROOT . '/maxcache',
        'site_hosts' => 'example.test',
        'debug_headers' => 1,
    ]]);
    $plugin->setApplication($app);
    $plugin->setDispatcher(new class {
        public function dispatch(string $name, object $event): object { return $event; }
    });
    return $plugin;
}

function dispatchLifecycle(Maxcache $plugin, Application $app): void
{
    $events = Maxcache::getSubscribedEvents();
    foreach (['onAfterRoute', 'onAfterRender', 'onBeforeRespond', 'respond', 'onAfterRespond'] as $name) {
        if ($name === 'respond') {
            $app->respond();
        } elseif (isset($events[$name])) {
            $class = 'Joomla\\CMS\\Event\\Application\\' . ucfirst(substr($name, 2)) . 'Event';
            $method = \is_array($events[$name]) ? $events[$name][0] : $events[$name];
            $plugin->{$method}(new $class($name, ['subject' => $app]));
        }
    }
}

// Another listener can still change headers or body in onBeforeRespond. Joomla
// runs higher priorities first, so the write decision must subscribe last.
$subscribed = Maxcache::getSubscribedEvents()['onBeforeRespond'];
check('write decision subscribes to onBeforeRespond at minimum priority',
    ['onBeforeRespond', \Joomla\Event\Priority::MIN], $subscribed);

// A valid CSRF token is not authority: guests and users without the relevant ACL
// must not reach any of the four administrative mutation paths.
$artifact = JPATH_ROOT . '/maxcache/example.test/news/index-https.html';
DirectoryOperations::ensure(\dirname($artifact));
AtomicFileWriter::write($artifact, 'existing public cache');
AtomicFileWriter::write(JPATH_ROOT . '/.htaccess', '# original site rules');

foreach (['purge_cache', 'apply_snippet', 'enable_regularlabs', 'disable_regularlabs'] as $action) {
    foreach ([
        new Identity(),
        new Identity(false),
        new Identity(false, ['core.login.admin', 'core.manage:com_plugins']),
        new Identity(false, ['core.manage:com_cache']),
    ] as $identity) {
        $app = new Application('administrator', new Input(['maxcache_action' => $action, 'option' => 'com_plugins']), $identity);
        $plugin = pluginFor($app);
        $error = null;
        try {
            $plugin->onAfterRoute(new AfterRouteEvent('onAfterRoute', ['subject' => $app]));
        } catch (\Throwable $exception) {
            $error = $exception->getCode();
        }
        check($action . ' rejects insufficient permissions', 403, $error);
        check($action . ' preserves cache', true, is_file($artifact));
        check($action . ' preserves htaccess', '# original site rules', file_get_contents(JPATH_ROOT . '/.htaccess'));
        check($action . ' does not change plugin state', [], $app->userState);
        check($action . ' does not clean Joomla caches', 0, Factory::$cacheCleanCalls);
        check($action . ' does not write purge marker', false, is_file(JPATH_CACHE . '/plg_system_maxcache_full_purge.timestamp'));
    }
}

foreach ([new Identity(), new Identity(false)] as $identity) {
    $app = new Application('administrator', new Input(['option' => 'com_cpanel']), $identity);
    pluginFor($app)->onAfterRender(new AfterRenderEvent('onAfterRender', ['subject' => $app]));
    check('purge button is hidden without permission', false, str_contains($app->body, 'data-maxcache-purge-action'));
}

// Cache managers can purge, but still cannot change server rules or integrations.
$cacheManager = new Identity(false, ['core.login.admin', 'core.manage:com_cache']);
foreach (['apply_snippet', 'enable_regularlabs', 'disable_regularlabs'] as $action) {
    $app = new Application('administrator', new Input(['maxcache_action' => $action]), $cacheManager);
    $error = null;
    try {
        pluginFor($app)->onAfterRoute(new AfterRouteEvent('onAfterRoute', ['subject' => $app]));
    } catch (\Throwable $exception) {
        $error = $exception->getCode();
    }
    check('cache manager cannot run ' . $action, 403, $error);
}

foreach (['purge_cache' => $cacheManager, 'apply_snippet' => new Identity(false, ['core.admin'])] as $action => $identity) {
    Session::$validToken = false;
    $app = new Application('administrator', new Input(['maxcache_action' => $action, 'option' => 'com_plugins']), $identity);
    $error = null;
    try {
        pluginFor($app)->onAfterRoute(new AfterRouteEvent('onAfterRoute', ['subject' => $app]));
    } catch (\Throwable $exception) {
        $error = $exception->getMessage();
    }
    check('authorized ' . $action . ' still requires CSRF', 'JINVALID_TOKEN', $error);
    check('invalid CSRF preserves cache', true, is_file($artifact));
    check('invalid CSRF preserves htaccess', '# original site rules', file_get_contents(JPATH_ROOT . '/.htaccess'));
    check('invalid CSRF preserves plugin state', [], $app->userState);
}
Session::$validToken = true;

$app = new Application('administrator', new Input(['maxcache_action' => 'purge_cache']), $cacheManager);
try {
    pluginFor($app)->onAfterRoute(new AfterRouteEvent('onAfterRoute', ['subject' => $app]));
} catch (ApplicationClosed) {
    // The public handler redirects/closes after completing the authorized action.
}
check('authorized purge removes cache', false, is_file($artifact));
check('authorized purge records success', 'success', $app->userState['plg_system_maxcache.last_purge_result']['type'] ?? null);
check('authorized purge reaches Joomla cache cleaner', 5, Factory::$cacheCleanCalls);

$app = new Application('administrator', new Input(['maxcache_action' => 'apply_snippet', 'option' => 'com_plugins']), new Identity(false, ['core.admin']));
try {
    pluginFor($app)->onAfterRoute(new AfterRouteEvent('onAfterRoute', ['subject' => $app]));
} catch (ApplicationClosed) {
}
check('global administrator can apply snippet', 'success', $app->userState['plg_system_maxcache.last_apply_result']['type'] ?? null);
check('authorized apply writes managed block', true, str_contains(file_get_contents(JPATH_ROOT . '/.htaccess'), '# BEGIN MAx Cache for Joomla'));

foreach ([1, 0] as $writeGzip) {
    $app = new Application('administrator', new Input(['maxcache_action' => 'apply_snippet', 'option' => 'com_plugins']), new Identity(false, ['core.admin']));
    try {
        pluginFor($app, ['write_gzip' => $writeGzip])->onAfterRoute(new AfterRouteEvent('onAfterRoute', ['subject' => $app]));
    } catch (ApplicationClosed) {
    }
    $applied = file_get_contents(JPATH_ROOT . '/.htaccess');
    check('admin applies the configured gzip suffix: ' . $writeGzip, (bool) $writeGzip, str_contains($applied, '{GZIP_SUFFIX}'));
    check('admin applies the configured gzip encoding: ' . $writeGzip, (bool) $writeGzip, str_contains($applied, 'AddEncoding gzip .html_gzip'));
    $preview = new Application('administrator', new Input(['option' => 'com_cpanel']), new Identity(false, ['core.admin']));
    pluginFor($preview, ['write_gzip' => $writeGzip])->onAfterRender(new AfterRenderEvent('onAfterRender', ['subject' => $preview]));
    check('admin status agrees with its applied gzip setting: ' . $writeGzip, false, str_contains($preview->body, 'server snippet is applied but outdated'));
    $changed = new Application('administrator', new Input(['option' => 'com_cpanel']), new Identity(false, ['core.admin']));
    pluginFor($changed, ['write_gzip' => 1 - $writeGzip])->onAfterRender(new AfterRenderEvent('onAfterRender', ['subject' => $changed]));
    check('admin detects changed gzip settings before reapplying: ' . $writeGzip, true, str_contains($changed->body, 'server snippet is applied but outdated'));
}

function siteRequest(string $request, string $body, array $headers = [], array $params = [], int $status = 200, bool $browserCache = false): Application
{
    Uri::$request = $request;
    $app = new Application('site', new Input([], $request), new Identity());
    $app->body = $body;
    $app->headers = $headers;
    $app->statusCode = $status;
    $app->allowCache($browserCache);
    dispatchLifecycle(pluginFor($app, $params), $app);
    return $app;
}

function sentHeader(Application $app, string $name): string
{
    return implode(', ', array_column(array_filter($app->sentHeaders,
        static fn (array $header): bool => strcasecmp($header['name'], $name) === 0), 'value'));
}

// Exclusions must match each URL/path independently: concatenating public and
// routed URLs moves the end anchor away from the public route.
foreach (['/news(?:/.*|$)', '^/news(?:/.*|$)', '^https://example.test/news$', '^/index\\.php$'] as $pattern) {
    DirectoryOperations::delete(JPATH_ROOT . '/maxcache');
    $app = siteRequest('/news', '<html>public route with no token</html>', [], ['exclude' => $pattern]);
    check('anchored exclusion reports an URL bypass: ' . $pattern, 'excluded-url', sentHeader($app, 'X-MAxCache-Reason'));
    check('anchored exclusion creates no HTML: ' . $pattern, false, is_file($artifact));
}

// Explicit privacy directives must prevent writing even without a form token.
foreach (['private', 'public, NO-STORE', 'private="Set-Cookie"', 'max-age=60, private'] as $control) {
    DirectoryOperations::ensure(\dirname($artifact));
    AtomicFileWriter::write($artifact, 'old cache');
    AtomicFileWriter::write($artifact . '_gzip', 'old compressed cache');
    $app = siteRequest('/news', '<html>private content without a token</html>', [
        ['name' => 'cAcHe-CoNtRoL', 'value' => 'public'],
        ['name' => 'Cache-Control', 'value' => $control],
    ]);
    check($control . ' leaves no plain artifact', false, is_file($artifact));
    check($control . ' removes old gzip even with write_gzip off', false, is_file($artifact . '_gzip'));
    check($control . ' sends bypass before responding', 'bypass', sentHeader($app, 'X-MAxCache-State'));
    check($control . ' sends no-store', true, str_contains(sentHeader($app, 'Cache-Control'), 'no-store'));
    check($control . ' has no late header changes', 0, $app->lateHeaderChanges);
}

$app = siteRequest('/news', '<html><input name="bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb" value="1"></html>', [], ['write_gzip' => 1], 200, true);
check('CSRF response has no plain artifact', false, is_file($artifact));
check('CSRF response has no gzip artifact', false, is_file($artifact . '_gzip'));
check('CSRF no-store reaches the client even with browser caching enabled', true, str_contains(sentHeader($app, 'Cache-Control'), 'no-store'));
check('CSRF rejection reason reaches the client', 'csrf-token-in-response', sentHeader($app, 'X-MAxCache-Reason'));
check('CSRF protection changes no headers after sending', 0, $app->lateHeaderChanges);

foreach ([404, 500] as $status) {
    $app = siteRequest('/news', '<html>error page</html>', [], [], $status);
    check('PSR response status prevents caching ' . $status . ' without a Status header', false, is_file($artifact));
}

foreach ([
    [['name' => 'Location', 'value' => '/other-page']],
    [['name' => 'Content-Type', 'value' => 'application/json']],
] as $headers) {
    siteRequest('/news', '<html>not a cacheable HTML response</html>', $headers);
    check('redirects and non-HTML responses leave no artifact', false, is_file($artifact));
}

foreach (['login', 'message'] as $change) {
    DirectoryOperations::ensure(\dirname($artifact));
    AtomicFileWriter::write($artifact, 'old public page');
    Uri::$request = '/news';
    $app = new Application('site', new Input(), new Identity());
    $plugin = pluginFor($app);
    $plugin->onAfterRoute(new AfterRouteEvent('onAfterRoute', ['subject' => $app]));
    $plugin->onAfterRender(new AfterRenderEvent('onAfterRender', ['subject' => $app]));
    if ($change === 'login') {
        $app->identity = new Identity(false);
    } else {
        $app->enqueueMessage('private validation message', 'message');
    }
    $plugin->onBeforeRespond(new BeforeRespondEvent('onBeforeRespond', ['subject' => $app]));
    $app->respond();
    check($change . ' during rendering prevents a static artifact', false, is_file($artifact));
    check($change . ' during rendering reports rejection', 'response-state', sentHeader($app, 'X-MAxCache-Reason'));
}

// Joomla puts the session token in core.js script options on every page. The
// visitor who generated the page keeps it; the shared artifact must not.
$tokenOptions = '<script type="application/json" class="joomla-script-options new">'
    . '{"csrf.token":"0123456789abcdef0123456789abcdef"}</script>';
$tokenPage = '<html><head>' . $tokenOptions . '</head><body>public</body></html>';
$loaderFile = JPATH_ROOT . '/media/plg_system_maxcache/js/csrf-refresh.js';
DirectoryOperations::ensure(dirname($loaderFile));
file_put_contents($loaderFile, '// loader');
\Joomla\CMS\Plugin\PluginHelper::$enabled = ['ajax/maxcachefragment'];
$app = siteRequest('/news', $tokenPage, [], ['write_gzip' => 1]);
$stored = (string) file_get_contents($artifact);
check('live response keeps the visitor token', true, str_contains($app->body, '"csrf.token":"0123456789abcdef0123456789abcdef"'));
check('artifact blanks the shared csrf.token', true, str_contains($stored, '"csrf.token":""'));
check('artifact contains no session token', false, str_contains($stored, '0123456789abcdef0123456789abcdef'));
check('artifact loads the token refresh script before </head>', true,
    (bool) preg_match('#<script src="/media/plg_system_maxcache/js/csrf-refresh\.js\?[0-9a-f]{8}" defer data-maxcache-token-endpoint="/index\.php\?option=com_ajax&amp;plugin=maxcachefragment&amp;format=raw&amp;maxcache_task=token"></script>\n</head>#', $stored));
check('gzip artifact matches the blanked plain artifact', $stored, gzdecode((string) file_get_contents($artifact . '_gzip')));
check('page with a script option token is still stored', 'stored', sentHeader($app, 'X-MAxCache-State'));

foreach (['disabled plugin', 'missing script', 'missing head'] as $unavailable) {
    \Joomla\CMS\Plugin\PluginHelper::$enabled = $unavailable === 'disabled plugin' ? [] : ['ajax/maxcachefragment'];
    if ($unavailable === 'missing script') {
        unlink($loaderFile);
    } else {
        file_put_contents($loaderFile, '// loader');
    }
    AtomicFileWriter::write($artifact, 'old shared HTML');
    AtomicFileWriter::write($artifact . '_gzip', 'old shared gzip');
    $body = $unavailable === 'missing head' ? str_replace('</head>', '', $tokenPage) : $tokenPage;
    $app = siteRequest('/news', $body);
    check($unavailable . ' prevents a tokenless artifact without refresh', false, is_file($artifact));
    check($unavailable . ' removes the old gzip companion', false, is_file($artifact . '_gzip'));
    check($unavailable . ' keeps the live visitor token', $body, $app->body);
    check($unavailable . ' reports the refresh bypass', 'csrf-refresh-unavailable', sentHeader($app, 'X-MAxCache-Reason'));
    check($unavailable . ' sends no-store', true, str_contains(sentHeader($app, 'Cache-Control'), 'no-store'));
}
unlink($loaderFile);
\Joomla\CMS\Plugin\PluginHelper::$enabled = [];

// The static reader can run as a different Unix user from PHP (cPanel/LSAPI).
// New public cache directories must remain traversable even with a private umask.
DirectoryOperations::delete(JPATH_ROOT . '/maxcache');
$originalUmask = umask(0027);
try {
    siteRequest('/permissions/new', '<html>public</html>');
    foreach (['', '/example.test', '/example.test/permissions', '/example.test/permissions/new'] as $suffix) {
        check('public cache directory is readable by Apache: ' . $suffix, 0755,
            fileperms(JPATH_ROOT . '/maxcache' . $suffix) & 0777);
    }
} finally {
    umask($originalUmask);
}
check('public directory creation restores the caller umask', $originalUmask, umask());
DirectoryOperations::delete(JPATH_ROOT . '/maxcache');

$publicBody = '<html>public page without tokens</html>';
$app = siteRequest('/news', $publicBody, [], ['write_gzip' => 1]);
check('public page remains cacheable with Joomla browser cache disabled', $publicBody, is_file($artifact) ? file_get_contents($artifact) : null);
check('public gzip companion contains the same body', $publicBody, is_file($artifact . '_gzip') ? gzdecode(file_get_contents($artifact . '_gzip')) : null);
check('successful stored diagnostic reaches the client', 'stored', sentHeader($app, 'X-MAxCache-State'));
check('public page changes no headers after sending', 0, $app->lateHeaderChanges);

// Query caching is fail-closed until the writer implements server-compatible
// variants. In particular, pagination must never overwrite the canonical page.
foreach (['/news?start=10', '/news?start[]=10', '/news?start=10&limit=20', '/news?unused=1', '/news?=10'] as $request) {
    AtomicFileWriter::write($artifact, $publicBody);
    AtomicFileWriter::write($artifact . '_gzip', gzencode($publicBody));
    $app = siteRequest($request, '<html>different query result</html>', [], ['allowed_query_params' => "start\nlimit"]);
    check($request . ' preserves canonical HTML', $publicBody, file_get_contents($artifact));
    check($request . ' preserves canonical gzip', $publicBody, gzdecode(file_get_contents($artifact . '_gzip')));
    check($request . ' writes no query variant', 2, CacheOperations::stats(JPATH_ROOT . '/maxcache')['files']);
    check($request . ' reports query bypass', 'query-params', sentHeader($app, 'X-MAxCache-Reason'));
}

// The server may serve the canonical page for tracking-only queries, but PHP
// must still never write from a request URL that carries a query.
AtomicFileWriter::write($artifact, $publicBody);
$app = siteRequest('/news?utm_source=newsletter', '<html>tracking render</html>', [],
    ['tracking_query_params' => 'utm_source']);
check('tracking-only query preserves canonical HTML', $publicBody, file_get_contents($artifact));
check('tracking-only query reports query bypass', 'query-params', sentHeader($app, 'X-MAxCache-Reason'));

$app = siteRequest('/fresh?start=10', '<html>first query visit</html>', [], ['allowed_query_params' => 'start']);
check('first query visit creates no canonical artifact', false, is_file(JPATH_ROOT . '/maxcache/example.test/fresh/index-https.html'));

// Joomla's current Uri can be modified by routing. The original request query
// must also prevent a write when the routed Uri has already lost the query.
Uri::$request = '/news';
AtomicFileWriter::write($artifact, $publicBody);
$app = new Application('site', new Input([], '/news?start=10'), new Identity());
$app->body = '<html>query result after routing</html>';
dispatchLifecycle(pluginFor($app, ['allowed_query_params' => 'start']), $app);
check('original request query cannot be hidden by routing', $publicBody, file_get_contents($artifact));
check('original request query reports bypass', 'query-params', sentHeader($app, 'X-MAxCache-Reason'));

foreach (['apache', 'mod_maxcache'] as $mode) {
    $snippet = SnippetBuilder::build($mode, ['cache_root' => JPATH_ROOT . '/maxcache', 'allowed_query_params' => "start\nlimit"]);
    check($mode . ' snippet does not enable query variants', false, str_contains($snippet, 'MaxCacheQSAllowedParams'));
}

echo 'Runtime tests: ' . $assertions . " assertions\n";
if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Runtime tests passed.\n";

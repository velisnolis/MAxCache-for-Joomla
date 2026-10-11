<?php

/** Opt-in runner: real Joomla, real ACL/session login, real Apache HTTP. */
declare(strict_types=1);

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\Table\Content;
use Joomla\CMS\Table\Menu;
use Joomla\CMS\Table\Usergroup;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Session\SessionInterface;

if (getenv('MAXCACHE_ISOLATED_JOOMLA6') !== '1') {
    throw new RuntimeException('Only run in the dedicated disposable Joomla 6 lab.');
}

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
$_SERVER['HTTP_HOST'] = 'maxcache.test';
$_SERVER['SERVER_NAME'] = 'maxcache.test';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';

$container = Factory::getContainer();
$container->alias('session', 'session.web.site')->alias(SessionInterface::class, 'session.web.site');
$app = $container->get(Joomla\CMS\Application\SiteApplication::class);
Factory::$application = $app;
$app->createExtensionNamespaceMap();
$app->loadLanguage($container->get(Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('en-GB', false));
$app->set('mailonline', false);

if ($app->get('sitename') !== 'MAxCache Joomla 6 isolated lab' || (new Joomla\CMS\Version())->getShortVersion() !== '6.1.4') {
    throw new RuntimeException('Unexpected site or Joomla version; refusing to modify it.');
}

// The fail-closed read test still lets the writer store a public response.
// Start each run cold, using only the cache owned by this guarded lab.
if (is_dir(JPATH_BASE . '/maxcache')) {
    Vendor\Plugin\System\Maxcache\Support\CacheOperations::purge(JPATH_BASE . '/maxcache', JPATH_BASE);
}

$db = $container->get(DatabaseInterface::class);
$checks = [];
$failures = [];

function check(bool $passed, string $label, array $details = []): void
{
    global $checks, $failures;
    $checks[] = ['test' => $label, 'passed' => $passed] + $details;
    if (!$passed) {
        $failures[] = $label;
    }
    echo ($passed ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
}

function scalar(string $sql): mixed
{
    global $db;
    return $db->setQuery($sql)->loadResult();
}

function execute(string $sql): void
{
    global $db;
    $db->setQuery($sql)->execute();
}

function grant(string $asset, string $permission, int $group): void
{
    global $db;
    $row = $db->setQuery('SELECT id, rules FROM #__assets WHERE name=' . $db->quote($asset))->loadObject();
    if (!$row) {
        throw new RuntimeException('Missing ACL asset: ' . $asset);
    }
    $rules = json_decode($row->rules, true, 512, JSON_THROW_ON_ERROR);
    $rules[$permission][(string) $group] = true;
    execute('UPDATE #__assets SET rules=' . $db->quote(json_encode($rules, JSON_THROW_ON_ERROR)) . ' WHERE id=' . (int) $row->id);
}

function user(string $role, bool $backend, bool $cache, bool $plugins): array
{
    global $db;
    $title = 'MAxCache lab ' . $role;
    $groupId = (int) scalar('SELECT id FROM #__usergroups WHERE title=' . $db->quote($title));
    if (!$groupId) {
        $group = new Usergroup($db);
        $group->bind(['title' => $title, 'parent_id' => 1]);
        if (!$group->check() || !$group->store()) {
            throw new RuntimeException($group->getError());
        }
        $groupId = (int) $group->id;
    }
    if ($backend) {
        grant('root.1', 'core.login.admin', $groupId);
        grant('com_cpanel', 'core.manage', $groupId);
        // mod_user has Special access. Without this the login works but Atum
        // hides the logout URL/token from a new custom group.
        $special = $db->setQuery("SELECT id,rules FROM #__viewlevels WHERE title='Special'")->loadObject();
        $viewGroups = json_decode($special->rules, true, 512, JSON_THROW_ON_ERROR);
        $viewGroups[] = $groupId;
        execute('UPDATE #__viewlevels SET rules=' . $db->quote(json_encode(array_values(array_unique($viewGroups)))) . ' WHERE id=' . (int) $special->id);
    }
    if ($cache) {
        grant('com_cache', 'core.manage', $groupId);
    }
    if ($plugins) {
        grant('com_plugins', 'core.manage', $groupId);
    }
    $username = 'maxcache-' . $role;
    $id = (int) scalar('SELECT id FROM #__users WHERE username=' . $db->quote($username));
    $record = new User($id);
    $password = bin2hex(random_bytes(24));
    $data = ['name' => $title, 'username' => $username, 'email' => $username . '@example.invalid',
        'password' => $password, 'password2' => $password, 'groups' => [$groupId], 'block' => 0];
    $record->bind($data);
    if (!$record->save()) {
        throw new RuntimeException($record->getError());
    }
    return ['id' => (int) $record->id, 'username' => $username, 'password' => $password];
}

function request(string $path, array $post = [], ?CurlHandle $client = null, array $extraHeaders = []): array
{
    $own = $client === null;
    $client ??= curl_init();
    $headers = [];
    curl_setopt_array($client, [CURLOPT_URL => 'http://maxcache.test' . $path,
        CURLOPT_RESOLVE => ['maxcache.test:80:127.0.0.1'], CURLOPT_PROXY => '',
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $extraHeaders, CURLOPT_POST => $post !== [],
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))][] = trim($value);
            }
            return strlen($line);
        }]);
    if ($post !== []) {
        curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($client);
    if ($body === false) {
        throw new RuntimeException(curl_error($client));
    }
    $status = curl_getinfo($client, CURLINFO_RESPONSE_CODE);
    if ($own) {
        curl_close($client);
    }
    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}

function headerValue(array $response, string $name): string
{
    return implode(', ', $response['headers'][strtolower($name)] ?? []);
}

function consoleStatus(): array
{
    $process = proc_open([PHP_BINARY, JPATH_BASE . '/cli/joomla.php', 'maxcache:status', '--format=json'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, JPATH_BASE);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start the real Joomla console status command.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('The real Joomla console status command failed.');
    }
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

function token(string $body): string
{
    preg_match('/<input\b[^>]*name=["\']([a-f0-9]{32})["\'][^>]*value=["\']1["\']/i', $body, $match);
    if (!isset($match[1])) {
        // Atum's authenticated toolbar uses a logout URL, not a hidden input.
        preg_match('/option=com_login(?:&amp;|&)task=logout(?:&amp;|&)([a-f0-9]{32})=1/i', $body, $match);
    }
    if (!isset($match[1])) {
        throw new RuntimeException('No Joomla form token in HTTP response.');
    }
    return $match[1];
}

function login(array $credentials): array
{
    $client = curl_init();
    curl_setopt($client, CURLOPT_COOKIEFILE, ''); // In-memory cookie jar, never written to disk.
    $page = request('/administrator/index.php', [], $client);
    $reply = request('/administrator/index.php', ['option' => 'com_login', 'task' => 'login',
        'username' => $credentials['username'], 'passwd' => $credentials['password'],
        token($page['body']) => '1'], $client);
    $page = request('/administrator/index.php', [], $client);
    preg_match('#<title>(.*?)</title>#s', $page['body'], $title);
    check($page['status'] === 200 && str_contains($page['body'], 'task=logout'), 'Real administrator login: ' . $credentials['username'],
        ['login_status' => $reply['status'], 'page_status' => $page['status'], 'title' => html_entity_decode($title[1] ?? '')]);
    if ($page['status'] !== 200 || !str_contains($page['body'], 'task=logout')) {
        throw new RuntimeException('Administrator login did not reach the authenticated toolbar (HTTP ' . $page['status'] . ').');
    }
    return [$client, token($page['body'])];
}

function artifact(string $case): string
{
    return '/var/www/html/maxcache/maxcache.test/index.php/lab-' . $case . '/index.html';
}

function artifacts(): array
{
    $found = [];
    $root = '/var/www/html/maxcache';
    if (is_dir($root)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $found[$file->getPathname()] = hash_file('sha256', $file->getPathname());
            }
        }
    }
    ksort($found);
    return $found;
}

// Only dedicated fixture identities and content, no production data or credentials.
$pluginManager = user('plugin-manager', true, false, true);
$cacheManager = user('cache-manager', true, true, false);
$cacheWithoutBackend = user('cache-no-backend', false, true, false);
$adminId = (int) scalar("SELECT id FROM #__users WHERE username='labadmin'");
$admin = new User($adminId);
$adminPassword = bin2hex(random_bytes(24));
$adminData = ['password' => $adminPassword, 'password2' => $adminPassword];
$admin->bind($adminData);
if (!$admin->save()) {
    throw new RuntimeException($admin->getError());
}
Access::clearStatics();
check((new User($pluginManager['id']))->authorise('core.login.admin')
    && !(new User($pluginManager['id']))->authorise('core.manage', 'com_cache'), 'Plugin-manager real ACL is restricted');
check((new User($cacheManager['id']))->authorise('core.manage', 'com_cache')
    && !(new User($cacheManager['id']))->authorise('core.admin'), 'Cache-manager real ACL is restricted');
check((new User($cacheWithoutBackend['id']))->authorise('core.manage', 'com_cache')
    && !(new User($cacheWithoutBackend['id']))->authorise('core.login.admin'), 'Cache privilege does not grant backend login');

$params = ['cache_root' => '/var/www/html/maxcache', 'site_hosts' => 'maxcache.test',
    'path_mode' => 'host-sef', 'vary_language' => 0, 'vary_mobile' => 0, 'write_gzip' => 1,
    'debug_headers' => 1, 'browsercache' => 1, 'allowed_query_params' => "limitstart\nstart\nutm_source",
    'exclude' => implode("\n", ['/lab-excluded(?:/.*|$)',
        '/lab-quoted(?:" and space)?(?:/.*|$)', '/index\\.php/lab-regex(?:/.*|$)']),
    'purge_automation_driver' => 'maxcache', 'full_regenerate_on_save' => 0,
    'full_regenerate_on_interval' => 0, 'server_snippet_mode' => 'apache'];
execute('UPDATE #__extensions SET enabled=1, ordering=9999, params=' . $db->quote(json_encode($params))
    . " WHERE type='plugin' AND folder='system' AND element='maxcache'");
execute("UPDATE #__extensions SET enabled=1, ordering=9998 WHERE type='plugin' AND folder='system' AND element='maxcachelab'");
execute("UPDATE #__extensions SET enabled=0 WHERE type='plugin' AND folder='system' AND element='cache'");

$component = (int) scalar("SELECT extension_id FROM #__extensions WHERE type='component' AND element='com_content'");
foreach (['public', 'query-only', 'excluded', 'quoted', 'regex', 'private', 'no-store', 'csrf', 'message', 'redirect', 'json', 'status-500'] as $case) {
    $alias = 'lab-' . $case;
    if (scalar('SELECT id FROM #__menu WHERE alias=' . $db->quote($alias))) {
        continue;
    }
    $content = new Content($db);
    $content->bind(['title' => $alias, 'alias' => $alias, 'introtext' => '<p>' . $alias . '</p>',
        'fulltext' => '', 'state' => 1, 'catid' => 2, 'created' => gmdate('Y-m-d H:i:s'),
        'created_by' => $adminId, 'access' => 1, 'language' => '*', 'attribs' => '{}',
        'metadata' => '{}', 'images' => '{}', 'urls' => '{}']);
    if (!$content->check() || !$content->store()) {
        throw new RuntimeException($content->getError());
    }
    $menu = new Menu($db);
    $menu->setLocation(1, 'last-child');
    $menu->bind(['menutype' => 'mainmenu', 'title' => $alias, 'alias' => $alias,
        'link' => 'index.php?option=com_content&view=article&id=' . $content->id,
        'type' => 'component', 'published' => 1, 'parent_id' => 1, 'component_id' => $component,
        'access' => 1, 'language' => '*', 'home' => 0, 'client_id' => 0, 'params' => '{}']);
    if (!$menu->check() || !$menu->store() || !$menu->rebuildPath($menu->id)) {
        throw new RuntimeException($menu->getError());
    }
}

$reply = request('/index.php/lab-excluded');
check($reply['status'] === 200 && headerValue($reply, 'X-MAxCache-Reason') === 'excluded-url'
    && !is_file(artifact('excluded')) && !is_file(artifact('excluded') . '_gzip'),
    'End-anchored configured exclusion bypasses the writer before applying Apache rules',
    ['status' => $reply['status'], 'reason' => headerValue($reply, 'X-MAxCache-Reason')]);

$public = request('/index.php/lab-public');
check($public['status'] === 200 && headerValue($public, 'X-MAxCache-State') === 'stored', 'Public HTML is stored before real HTTP send', ['status' => $public['status'], 'state' => headerValue($public, 'X-MAxCache-State'), 'reason' => headerValue($public, 'X-MAxCache-Reason')]);
check(is_file(artifact('public')) && file_get_contents(artifact('public')) === $public['body'], 'Plain artifact equals the HTTP body');
check(is_file(artifact('public') . '_gzip') && gzdecode(file_get_contents(artifact('public') . '_gzip')) === $public['body'], 'Gzip artifact equals the HTTP body');
$canonical = artifacts();
foreach (['limitstart=10', 'start=20', 'utm_source=lab', 'limitstart%5B%5D=10', 'limitstart=10&unknown=1', 'unknown=1', '=value'] as $query) {
    $reply = request('/index.php/lab-public?' . $query);
    // Article pagination can legitimately be a router 404. Never call that 200.
    check(in_array($reply['status'], [200, 404], true) && headerValue($reply, 'X-MAxCache-Reason') === 'query-params', 'Query bypass via real HTTP: ' . $query, ['status' => $reply['status'], 'reason' => headerValue($reply, 'X-MAxCache-Reason')]);
    check(artifacts() === $canonical, 'Query cannot change canonical plain/gzip: ' . $query);
}
$reply = request('/index.php/lab-query-only?utm_source=lab');
check($reply['status'] === 200 && headerValue($reply, 'X-MAxCache-Reason') === 'query-params', 'A query-only visit bypasses the writer');
check(!is_file(artifact('query-only')) && !is_file(artifact('query-only') . '_gzip'), 'A query-only visit creates no canonical artifact');
$visitor = curl_init();
curl_setopt($visitor, CURLOPT_COOKIEFILE, '');
$reply = request('/index.php/lab-public', [], $visitor);
$before = artifacts();
$reply = request('/index.php/lab-public', [], $visitor);
check($reply['status'] === 200 && headerValue($reply, 'X-MAxCache-Reason') === 'bypass-cookie', 'A real guest session cookie bypasses the writer');
check($before === artifacts(), 'A session visit does not change cached HTML');
curl_close($visitor);
foreach (['private', 'no-store', 'csrf', 'message', 'redirect', 'json', 'status-500'] as $case) {
    // Synthetic stale artifacts belong only to this disposable fixture.
    $target = artifact($case);
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0755, true);
    }
    file_put_contents($target, 'stale fixture');
    file_put_contents($target . '_gzip', gzencode('stale fixture'));
    $reply = request('/index.php/lab-' . $case);
    $reason = in_array($case, ['private', 'no-store'], true) ? 'private-response'
        : ($case === 'csrf' ? 'csrf-token-in-response' : 'response-state');
    check(headerValue($reply, 'X-MAxCache-State') === 'bypass' && headerValue($reply, 'X-MAxCache-Reason') === $reason, 'Unsafe response bypass via real HTTP: ' . $case, ['status' => $reply['status'], 'reason' => headerValue($reply, 'X-MAxCache-Reason')]);
    if ($case === 'status-500') {
        check($reply['status'] === 500, 'A real PSR response 500 is not treated as HTTP success');
    }
    // Joomla respond() appends its generic policy; sendHeaders() replaces prior
    // values. no-store must survive, even if the optional private directive does not.
    check(str_contains(headerValue($reply, 'Cache-Control'), 'no-store') && !str_contains(headerValue($reply, 'Cache-Control'), 'public'), 'Unsafe policy reaches HTTP client: ' . $case, ['cache_control' => headerValue($reply, 'Cache-Control')]);
    check(!is_file($target) && !is_file($target . '_gzip'), 'Unsafe response removes stale plain/gzip: ' . $case);
}
$first = request('/index.php/lab-csrf');
$second = request('/index.php/lab-csrf');
check(token($first['body']) !== token($second['body']), 'CSRF tokens differ between two fresh guest visits');
check(!is_file(artifact('csrf')) && !is_file(artifact('csrf') . '_gzip'), 'Repeated CSRF responses produce no artifacts');
$params['write_gzip'] = 0;
execute('UPDATE #__extensions SET params=' . $db->quote(json_encode($params)) . " WHERE folder='system' AND element='maxcache'");
file_put_contents(artifact('csrf'), 'stale fixture');
file_put_contents(artifact('csrf') . '_gzip', gzencode('stale fixture'));
$reply = request('/index.php/lab-csrf');
check(headerValue($reply, 'X-MAxCache-Reason') === 'csrf-token-in-response'
    && !is_file(artifact('csrf')) && !is_file(artifact('csrf') . '_gzip'), 'CSRF rejection removes old gzip even when gzip writes are disabled');
$params['write_gzip'] = 1;
execute('UPDATE #__extensions SET params=' . $db->quote(json_encode($params)) . " WHERE folder='system' AND element='maxcache'");

// Real authentication and role ACL, not injected identities or API doubles.
$extensionId = (int) scalar("SELECT extension_id FROM #__extensions WHERE folder='system' AND element='maxcache'");
$actions = ['purge_cache', 'apply_snippet', 'enable_regularlabs', 'disable_regularlabs'];
$state = static fn (): array => ['files' => artifacts(), 'htaccess' => is_file(JPATH_BASE . '/.htaccess') ? hash_file('sha256', JPATH_BASE . '/.htaccess') : null,
    'params' => scalar("SELECT params FROM #__extensions WHERE folder='system' AND element='maxcache'")];
foreach ($actions as $action) {
    $before = $state();
    $reply = request('/administrator/index.php', ['option' => 'com_plugins', 'maxcache_action' => $action, 'extension_id' => $extensionId]);
    check($reply['status'] === 403, 'Guest denied admin action: ' . $action, ['status' => $reply['status']]);
    check($before === $state(), 'Guest denial has no effects: ' . $action);
}
foreach (['plugin-manager' => $pluginManager, 'cache-manager' => $cacheManager] as $role => $credentials) {
    [$client, $formToken] = login($credentials);
    foreach ($role === 'cache-manager' ? array_slice($actions, 1) : $actions as $action) {
        $before = $state();
        $reply = request('/administrator/index.php', ['option' => 'com_plugins', 'maxcache_action' => $action,
            'extension_id' => $extensionId, $formToken => '1'], $client);
        check($reply['status'] === 403, $role . ' denied admin action with valid CSRF: ' . $action, ['status' => $reply['status']]);
        check($before === $state(), $role . ' denial has no effects: ' . $action);
    }
    if ($role === 'cache-manager') {
        $before = $state();
        $reply = request('/administrator/index.php', ['option' => 'com_plugins', 'maxcache_action' => 'purge_cache', 'extension_id' => $extensionId], $client);
        check($reply['status'] !== 303 && $before === $state(), 'Authorized role still needs a valid CSRF token');
        $reply = request('/administrator/index.php', ['option' => 'com_plugins', 'maxcache_action' => 'purge_cache',
            'extension_id' => $extensionId, $formToken => '1'], $client);
        check($reply['status'] === 303 && artifacts() === [], 'Cache-manager can purge only dedicated test cache', ['status' => $reply['status']]);
    }
    curl_close($client);
}
[$client, $formToken] = login(['username' => 'labadmin', 'password' => $adminPassword]);
$reply = request('/administrator/index.php', ['option' => 'com_plugins', 'maxcache_action' => 'apply_snippet',
    'extension_id' => $extensionId, $formToken => '1'], $client);
check($reply['status'] === 303 && is_file(JPATH_BASE . '/.htaccess')
    && str_contains(file_get_contents(JPATH_BASE . '/.htaccess'), '# BEGIN MAx Cache for Joomla'), 'Super User applies snippet in isolated site', ['status' => $reply['status']]);
check(is_file(JPATH_BASE . '/.htaccess') && !str_contains(file_get_contents(JPATH_BASE . '/.htaccess'), 'MaxCacheQSAllowedParams'), 'Applied snippet does not enable query allowlists');
curl_close($client);

// Apache preview currently targets index-https.html. Seed that exact fixture
// variant to prove the installed rewrite rules, without pretending HTTP wrote it.
$serverFixture = dirname(artifact('query-only')) . '/index-https.html';
mkdir(dirname($serverFixture), 0755, true);
$sentinel = 'maxcache-lab-static-sentinel';
file_put_contents($serverFixture, $sentinel);
$reply = request('/index.php/lab-query-only');
check($reply['status'] === 200 && $reply['body'] === $sentinel, 'Installed Apache snippet really serves the seeded static variant');
$before = hash_file('sha256', $serverFixture);
$reply = request('/index.php/lab-query-only?utm_source=lab');
check($reply['status'] === 200 && $reply['body'] !== $sentinel
    && headerValue($reply, 'X-MAxCache-Reason') === 'query-params', 'Apache query bypass reaches live Joomla rather than stale static HTML');
check(hash_file('sha256', $serverFixture) === $before && !is_file(artifact('query-only'))
    && !is_file(artifact('query-only') . '_gzip'), 'Apache query bypass neither replaces nor creates canonical artifacts');
$visitor = curl_init();
curl_setopt($visitor, CURLOPT_COOKIEFILE, '');
request('/index.php/lab-csrf', [], $visitor);
$reply = request('/index.php/lab-query-only', [], $visitor);
check($reply['status'] === 200 && $reply['body'] !== $sentinel
    && headerValue($reply, 'X-MAxCache-Reason') === 'bypass-cookie', 'Apache bypasses static HTML for a real guest session cookie',
    ['status' => $reply['status'], 'served_static_sentinel' => $reply['body'] === $sentinel,
        'state' => headerValue($reply, 'X-MAxCache-State'), 'reason' => headerValue($reply, 'X-MAxCache-Reason')]);
check(hash_file('sha256', $serverFixture) === $before && !is_file(artifact('query-only')),
    'Apache session bypass preserves the static artifact and does not write HTML');
curl_close($visitor);

// Old artifacts on an excluded URL must never bypass Joomla's exclusion/token
// protection. Test both configured exclusions and Joomla's built-in users route.
foreach (['/index.php/lab-excluded', '/index.php/lab-quoted', '/index.php/lab-regex',
    '/index.php/component/users/reset', '/index.php/component/users/remind'] as $excludedPath) {
    $target = JPATH_BASE . '/maxcache/maxcache.test' . $excludedPath . '/index-https.html';
    mkdir(dirname($target), 0755, true);
    file_put_contents($target, $sentinel);
    $before = hash_file('sha256', $target);
    $first = request($excludedPath);
    $second = request($excludedPath);
    check($first['status'] === 200 && $first['body'] !== $sentinel
        && headerValue($first, 'X-MAxCache-Reason') === 'excluded-url',
        'Apache excluded route reaches live Joomla: ' . $excludedPath,
        ['status' => $first['status'], 'served_static_sentinel' => $first['body'] === $sentinel,
            'reason' => headerValue($first, 'X-MAxCache-Reason')]);
    check(hash_file('sha256', $target) === $before
        && !is_file(dirname($target) . '/index.html') && !is_file(dirname($target) . '/index.html_gzip'),
        'Excluded visits preserve the old fixture and write no new HTML: ' . $excludedPath);
    if (str_contains($excludedPath, 'component/users')) {
        // Do not abort the red run when the stale sentinel contains no token.
        $hasTokens = preg_match('/name=["\x27][a-f0-9]{32}["\x27]/i', $first['body'])
            && preg_match('/name=["\x27][a-f0-9]{32}["\x27]/i', $second['body']);
        check($hasTokens && token($first['body']) !== token($second['body']),
            'Real Joomla form tokens change despite a stale static artifact: ' . $excludedPath);
    }
}

// mod_rewrite cannot escape its own quote delimiter. An exclusion containing
// both literal quote types must fail closed, without breaking the entire site.
$quotedParams = $params;
$quotedParams['exclude'] = "/lab-quoted(?:[\"'] and space)?(?:/.*|$)";
execute('UPDATE #__extensions SET params=' . $db->quote(json_encode($quotedParams)) . " WHERE folder='system' AND element='maxcache'");
[$client, $formToken] = login(['username' => 'labadmin', 'password' => $adminPassword]);
$reply = request('/administrator/index.php', ['option' => 'com_plugins', 'maxcache_action' => 'apply_snippet',
    'extension_id' => $extensionId, $formToken => '1'], $client);
check($reply['status'] === 303 && str_contains(file_get_contents(JPATH_BASE . '/.htaccess'), 'Static serving disabled'),
    'Ambiguous quote delimiters produce an explicit fail-closed Apache snippet');
$reply = request('/index.php/lab-query-only');
check($reply['status'] === 200 && $reply['body'] !== $sentinel && headerValue($reply, 'X-MAxCache-Lab-Origin') !== '',
    'Fail-closed snippet keeps real Joomla working instead of serving stale HTML or returning 500');
$reply = request('/index.php/lab-quoted');
check($reply['status'] === 200 && $reply['body'] !== $sentinel && headerValue($reply, 'X-MAxCache-Reason') === 'excluded-url',
    'Both-quote exclusion still bypasses static reads and the writer');
execute('UPDATE #__extensions SET params=' . $db->quote(json_encode($params)) . " WHERE folder='system' AND element='maxcache'");
$reply = request('/administrator/index.php', ['option' => 'com_plugins', 'maxcache_action' => 'apply_snippet',
    'extension_id' => $extensionId, $formToken => '1'], $client);
check($reply['status'] === 303 && !str_contains(file_get_contents(JPATH_BASE . '/.htaccess'), 'Static serving disabled'),
    'Safe exclusions restore static serving without a site restart');
curl_close($client);

// Admin apply/status must agree with the console and preserve write_gzip.
// The lab has no mod_maxcache: applying its guarded snippet proves generation,
// not a cache hit. The real module is checked separately by the production canary.
[$client, $formToken] = login(['username' => 'labadmin', 'password' => $adminPassword]);
foreach ([1, 0] as $writeGzip) {
    $gzipParams = $params;
    $gzipParams['server_snippet_mode'] = 'mod_maxcache';
    $gzipParams['write_gzip'] = $writeGzip;
    execute('UPDATE #__extensions SET params=' . $db->quote(json_encode($gzipParams)) . " WHERE folder='system' AND element='maxcache'");
    $reply = request('/administrator/index.php', ['option' => 'com_plugins', 'maxcache_action' => 'apply_snippet',
        'extension_id' => $extensionId, $formToken => '1'], $client);
    $applied = file_get_contents(JPATH_BASE . '/.htaccess');
    check($reply['status'] === 303 && str_contains($applied, '{GZIP_SUFFIX}') === (bool) $writeGzip,
        'Real admin applies configured mod_maxcache gzip suffix: ' . $writeGzip);
    check(str_contains($applied, 'AddType text/html .html_gzip') === (bool) $writeGzip
        && str_contains($applied, 'AddEncoding gzip .html_gzip') === (bool) $writeGzip,
        'Real admin applies configured gzip MIME directives: ' . $writeGzip);
    $page = request('/administrator/index.php', [], $client);
    check($page['status'] === 200 && !str_contains($page['body'], 'server snippet is applied but outdated'),
        'Real admin status agrees with applied gzip snippet: ' . $writeGzip);
    $state = consoleStatus();
    check($state['server']['snippet_state'] === 'applied', 'Real console and admin agree on gzip snippet hash: ' . $writeGzip);
    $gzipParams['write_gzip'] = 1 - $writeGzip;
    execute('UPDATE #__extensions SET params=' . $db->quote(json_encode($gzipParams)) . " WHERE folder='system' AND element='maxcache'");
    $page = request('/administrator/index.php', [], $client);
    check($page['status'] === 200 && str_contains($page['body'], 'server snippet is applied but outdated'),
        'Real admin detects changed gzip settings before reapply: ' . $writeGzip);
    check(consoleStatus()['server']['snippet_state'] === 'outdated',
        'Real console detects changed gzip settings before reapply: ' . $writeGzip);
}

// Parse the generated MIME block with real Apache and serve a gzip generated
// by the actual writer. It is scoped to the disposable lab cache directory.
$gzipParams['write_gzip'] = 1;
execute('UPDATE #__extensions SET params=' . $db->quote(json_encode($gzipParams)) . " WHERE folder='system' AND element='maxcache'");
$reply = request('/administrator/index.php', ['option' => 'com_plugins', 'maxcache_action' => 'apply_snippet',
    'extension_id' => $extensionId, $formToken => '1'], $client);
$applied = file_get_contents(JPATH_BASE . '/.htaccess');
preg_match('#<IfModule mod_mime\\.c>.*?</IfModule>#s', $applied, $mimeBlock);
check(isset($mimeBlock[0]), 'Applied snippet exposes a reusable guarded MIME block');
if (isset($mimeBlock[0])) {
    $public = request('/index.php/lab-public');
    $target = artifact('public');
    check($public['status'] === 200 && is_file($target . '_gzip')
        && gzdecode(file_get_contents($target . '_gzip')) === $public['body'],
        'Real writer generates gzip before Apache MIME validation');
    file_put_contents(JPATH_BASE . '/maxcache/.htaccess', $mimeBlock[0]);
    $staticPath = '/maxcache/maxcache.test/index.php/lab-public/index.html';
    $gzipReply = request($staticPath . '_gzip', [], null, ['Accept-Encoding: gzip']);
    check($gzipReply['status'] === 200 && headerValue($gzipReply, 'Content-Encoding') === 'gzip',
        'Generated MIME directives send gzip encoding through real Apache');
    check(str_starts_with(strtolower(headerValue($gzipReply, 'Content-Type')), 'text/html'),
        'Generated MIME directives send HTML content type through real Apache');
    check(gzdecode($gzipReply['body']) === $public['body'], 'Real Apache serves the actual writer gzip bytes');
    $plainReply = request($staticPath, [], null, ['Accept-Encoding: identity']);
    check($plainReply['status'] === 200 && headerValue($plainReply, 'Content-Encoding') === ''
        && $plainReply['body'] === $public['body'], 'Gzip MIME declarations leave plain HTML unencoded');
    unlink(JPATH_BASE . '/maxcache/.htaccess');
}
execute('UPDATE #__extensions SET params=' . $db->quote(json_encode($params)) . " WHERE folder='system' AND element='maxcache'");
request('/administrator/index.php', ['option' => 'com_plugins', 'maxcache_action' => 'apply_snippet',
    'extension_id' => $extensionId, $formToken => '1'], $client);
curl_close($client);
check(consoleStatus()['server']['snippet_state'] === 'applied', 'Real console agrees after restoring the Apache snippet');

$sourceHashes = [];
foreach (['Extension/Maxcache.php', 'Support/BypassCookieNames.php', 'Support/SnippetBuilder.php', 'Support/ResponseSafety.php'] as $source) {
    $sourceHashes[$source] = hash_file('sha256', JPATH_BASE . '/plugins/system/maxcache/src/' . $source);
}
$evidence = ['environment' => ['joomla' => (new Joomla\CMS\Version())->getShortVersion(), 'php' => PHP_VERSION,
    'site' => $app->get('sitename'), 'plugin_version' => '0.1.39-unreleased', 'fixture_only' => true],
    'verified_at_utc' => gmdate('c'), 'installed_source_sha256' => $sourceHashes,
    'checks' => $checks, 'failed' => $failures, 'assertions' => count($checks)];
// Evidence deliberately excludes credentials, cookies, tokens and complete response bodies.
file_put_contents('/tmp/maxcache-joomla6-evidence.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
echo 'Assertions: ' . count($checks) . '; failures: ' . count($failures) . PHP_EOL;
exit($failures === [] ? 0 : 1);

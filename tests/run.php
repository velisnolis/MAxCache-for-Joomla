<?php

declare(strict_types=1);

const _JEXEC = 1;

require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/CachePathBuilder.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/ResponseSafety.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/CacheOperations.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/DirectoryOperations.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/LanguageRoutingProfile.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/AtomicFileWriter.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/RegexPatternMatcher.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/CachePathHelper.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/BuiltInExclusions.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/BypassCookieNames.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/SnippetBuilder.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/SystemCacheSettings.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/SnippetParams.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/HtaccessManager.php';

use Vendor\Plugin\System\Maxcache\Support\AtomicFileWriter;
use Vendor\Plugin\System\Maxcache\Support\BypassCookieNames;
use Vendor\Plugin\System\Maxcache\Support\BuiltInExclusions;
use Vendor\Plugin\System\Maxcache\Support\CachePathBuilder;
use Vendor\Plugin\System\Maxcache\Support\CacheOperations;
use Vendor\Plugin\System\Maxcache\Support\DirectoryOperations;
use Vendor\Plugin\System\Maxcache\Support\LanguageRoutingProfile;
use Vendor\Plugin\System\Maxcache\Support\HtaccessManager;
use Vendor\Plugin\System\Maxcache\Support\RegexPatternMatcher;
use Vendor\Plugin\System\Maxcache\Support\ResponseSafety;
use Vendor\Plugin\System\Maxcache\Support\SnippetBuilder;
use Vendor\Plugin\System\Maxcache\Support\SnippetParams;

$failures = [];
$assertions = 0;

function assertSameValue(string $label, mixed $expected, mixed $actual): void
{
    global $failures, $assertions;
    ++$assertions;

    if ($expected === $actual) {
        echo '.';
        return;
    }

    $failures[] = [
        'label' => $label,
        'expected' => $expected,
        'actual' => $actual,
    ];
    echo 'F';
}

function cachePath(array $overrides): ?string
{
    return CachePathBuilder::build($overrides + [
        'cache_root' => '/cache/maxcache',
        'host' => 'www.planadevic.cat',
        'request_path' => '/',
        'scheme' => 'https',
        'vary_language' => 1,
        'vary_mobile' => 0,
        'is_mobile' => false,
        'variant_suffix' => '',
        'language_sefs' => ['cat', 'esp'],
    ]);
}

assertSameValue(
    'prefixed Catalan URL does not duplicate language segment when path_mode is host-sef',
    '/cache/maxcache/www.planadevic.cat/cat/llet/index-https.html',
    cachePath([
        'request_path' => '/cat/llet',
        'path_mode' => 'host-sef',
    ])
);

assertSameValue(
    'prefixed Spanish URL does not duplicate language segment when path_mode is host-language-sef',
    '/cache/maxcache/www.planadevic.cat/esp/pienso/index-https.html',
    cachePath([
        'request_path' => '/esp/pienso',
        'path_mode' => 'host-language-sef',
    ])
);

assertSameValue(
    'single published language with URL prefix keeps one language directory',
    '/cache/maxcache/www.example.org/ca/agenda/index-https.html',
    cachePath([
        'host' => 'www.example.org',
        'request_path' => '/ca/agenda',
        'path_mode' => 'host-sef',
        'language_sefs' => ['ca'],
    ])
);

assertSameValue(
    'vary_language disabled keeps the language-looking segment as regular SEF path',
    '/cache/maxcache/www.planadevic.cat/cat/llet/index-https.html',
    cachePath([
        'request_path' => '/cat/llet',
        'vary_language' => 0,
    ])
);

assertSameValue(
    'mobile and pagecache variant suffixes are preserved',
    '/cache/maxcache/www.planadevic.cat/cat/llet/index-mobile-https-v-abcd1234.html',
    cachePath([
        'request_path' => '/cat/llet',
        'vary_mobile' => 1,
        'is_mobile' => true,
        'variant_suffix' => 'abcd1234',
    ])
);

$profile = LanguageRoutingProfile::detect(true, ['remove_default_prefix' => 0], [
    ['lang_code' => 'ca-ES', 'sef' => 'cat'],
    ['lang_code' => 'es-ES', 'sef' => 'esp'],
]);

assertSameValue('two prefixed languages recommend language path mode', 'host-language-sef', $profile['recommended_path_mode']);
assertSameValue('two prefixed languages enable vary_language', 1, $profile['recommended_vary_language']);
assertSameValue('three-letter language SEFs are preserved', ['cat', 'esp'], $profile['language_sefs']);

$profile = LanguageRoutingProfile::detect(true, ['remove_default_prefix' => 0], [
    ['lang_code' => 'ca-ES', 'sef' => 'ca'],
]);

assertSameValue('single prefixed language still recommends language path mode', 'host-language-sef', $profile['recommended_path_mode']);
assertSameValue('single prefixed language still enables vary_language', 1, $profile['recommended_vary_language']);

$profile = LanguageRoutingProfile::detect(true, ['remove_default_prefix' => 1], [
    ['lang_code' => 'ca-ES', 'sef' => 'ca'],
    ['lang_code' => 'es-ES', 'sef' => 'es'],
]);

assertSameValue('removed default prefix is detected as partially prefixed', 'partially_prefixed', $profile['state']);

assertSameValue('valid regex pattern matches the URL subject', true, RegexPatternMatcher::matchesAny(['/secret(?:/.*|$)'], 'https://example.test/secret/page /index.php'));
assertSameValue('invalid regex pattern is skipped at runtime', false, RegexPatternMatcher::matchesAny(['[broken'], 'https://example.test/secret/page /index.php'));
assertSameValue('invalid regex pattern is reported for admin feedback', ['[broken'], RegexPatternMatcher::findInvalid(['/ok(?:/.*|$)', '[broken']));
assertSameValue(
    'built-in exclusions protect Joomla users component query routes',
    true,
    RegexPatternMatcher::matchesAny(
        BuiltInExclusions::getRuntimePatterns(),
        'https://www.planadevic.cat/index.php?option=com_users&view=reset /index.php?option=com_users&view=reset'
    )
);
assertSameValue(
    'built-in exclusions protect Joomla users component SEF routes',
    true,
    RegexPatternMatcher::matchesAny(
        BuiltInExclusions::getRuntimePatterns(),
        'https://www.planadevic.cat/component/users/reset /index.php?option=com_users&view=reset'
    )
);

$sessionCookieFromConfiguredName = md5(md5('secret-value' . 'custom-session-name'));
$sessionCookieFromSite = md5(md5('secret-value' . 'site'));
$sessionCookieFromAdministrator = md5(md5('secret-value' . 'administrator'));
$sessionCookieFromSiteApplication = md5(md5('secret-value' . 'Joomla\\CMS\\Application\\SiteApplication'));
$sessionCookieFromAdministratorApplication = md5(md5('secret-value' . 'Joomla\\CMS\\Application\\AdministratorApplication'));
$sessionCookieFromService = '44c05088706fb5e957c981e7d16d5a4d';

assertSameValue(
    'effective bypass cookies include configured cookies, resolved service session cookies, and Joomla frontend session cookies',
    ['joomla_user_state', $sessionCookieFromService, $sessionCookieFromConfiguredName,
        $sessionCookieFromSite, $sessionCookieFromAdministrator,
        $sessionCookieFromSiteApplication, $sessionCookieFromAdministratorApplication],
    BypassCookieNames::mergeWithJoomlaSessionCookies(
        ['joomla_user_state'],
        'secret-value',
        'custom-session-name',
        [$sessionCookieFromService]
    )
);

foreach (['', 'site', 'administrator', 'shared', 'custom-session-name'] as $sessionName) {
    $names = BypassCookieNames::joomlaSessionCookieNames('secret-value', $sessionName);
    assertSameValue('frontend cookie survives snippet generation from ' . ($sessionName ?: 'no application'), true,
        in_array($sessionCookieFromSite, $names, true));
    assertSameValue('backend cookie survives snippet generation from ' . ($sessionName ?: 'no application'), true,
        in_array($sessionCookieFromAdministrator, $names, true));
    assertSameValue('session cookie list is deduplicated: ' . $sessionName, count(array_unique($names)), count($names));
    if ($sessionName !== '') {
        assertSameValue('configured/shared session uses Joomla double hash: ' . $sessionName, true,
            in_array(md5(md5('secret-value' . $sessionName)), $names, true));
    }
}
assertSameValue('no cookie hashes are guessed without a Joomla secret', [],
    BypassCookieNames::joomlaSessionCookieNames('', 'site'));

// This standalone helper suite does not bootstrap Joomla. Foreign session
// construction can change PHP's global session name, so make that observable.
class MaxcacheCookieFactoryFixture
{
    public static int $containerCalls = 0;
    public static ?object $application = null;

    public static function getContainer(): never
    {
        ++self::$containerCalls;
        throw new LogicException('Foreign session services must not be requested.');
    }

    public static function getApplication(): object
    {
        return self::$application ?? throw new LogicException('Application is not bootstrapped.');
    }
}
class_alias(MaxcacheCookieFactoryFixture::class, 'Joomla\\CMS\\Factory');
MaxcacheCookieFactoryFixture::$application = new class {
    public function getSession(): object
    {
        return new class {
            public function getName(): string { return 'current-runtime-cookie'; }
        };
    }
};
assertSameValue('resolved cookie names use the current application session', ['current-runtime-cookie'],
    BypassCookieNames::factorySessionCookieNames());
assertSameValue('cookie discovery never constructs another client session', 0, MaxcacheCookieFactoryFixture::$containerCalls);
MaxcacheCookieFactoryFixture::$application = new class {
    public int $sessionReads = 0;
    public function isClient(string $client): bool { return $client === 'cli'; }
    public function getSession(): object
    {
        ++$this->sessionReads;
        return new class {
            public function getName(): string { return 'cli-only-runtime-cookie'; }
        };
    }
};
assertSameValue('console runtime sessions are not browser bypass cookies', [],
    BypassCookieNames::factorySessionCookieNames());
assertSameValue('browser cookie discovery does not open the console runtime session', 0,
    MaxcacheCookieFactoryFixture::$application->sessionReads);
MaxcacheCookieFactoryFixture::$application = null;
assertSameValue('partially bootstrapped previews tolerate a missing application', [],
    BypassCookieNames::factorySessionCookieNames());

// Equivalent cookie sets must not make the applied snippet look outdated
// when it is checked from a different Joomla client or the console.
$cookieSnippetParams = ['joomla_secret' => 'secret-value', 'bypass_cookies' => "z_cookie\na_cookie"];
foreach (['apache', 'mod_maxcache'] as $mode) {
    $canonicalSnippet = SnippetBuilder::build($mode, $cookieSnippetParams);
    foreach (['site', 'administrator', 'Joomla\\CMS\\Application\\SiteApplication',
        'Joomla\\CMS\\Application\\AdministratorApplication'] as $clientName) {
        assertSameValue('snippet is stable across Joomla cookie contexts: ' . $mode . ' ' . $clientName,
            $canonicalSnippet, SnippetBuilder::build($mode, $cookieSnippetParams + [
                'joomla_session_name' => $clientName,
                'joomla_session_cookie_names' => [md5(md5('secret-value' . $clientName))],
            ]));
    }
}

$snippet = SnippetBuilder::buildModMaxcacheSnippet([
    'cache_root' => '/var/cache/joomla-maxcache',
    'exclude' => "/ok(?:/.*|$)\n[broken",
    'bypass_cookies' => 'joomla_user_state',
    'joomla_secret' => 'secret-value',
    'joomla_session_name' => 'custom-session-name',
    'joomla_session_cookie_names' => [$sessionCookieFromService],
]);

assertSameValue('snippet keeps valid custom exclusion regex', true, str_contains($snippet, '/ok(?:/.*|$)'));
assertSameValue('snippet omits invalid custom exclusion regex', false, str_contains($snippet, '[broken'));
assertSameValue('snippet excludes Joomla users component routes', true, str_contains($snippet, 'option=com_users'));
assertSameValue('snippet excludes Joomla users component SEF path', true, str_contains($snippet, 'component/users'));
assertSameValue('snippet excludes configured bypass cookie', true, str_contains($snippet, 'joomla_user_state'));
assertSameValue('snippet excludes resolved service session cookie', true, str_contains($snippet, $sessionCookieFromService));
assertSameValue('snippet excludes configured Joomla session-name cookie', true, str_contains($snippet, $sessionCookieFromConfiguredName));
assertSameValue('snippet excludes Joomla frontend application session cookie', true, str_contains($snippet, $sessionCookieFromSiteApplication));
assertSameValue('mod_maxcache snippet excludes the default frontend cookie', true, str_contains($snippet, $sessionCookieFromSite));
assertSameValue('mod_maxcache snippet excludes the default backend cookie', true, str_contains($snippet, $sessionCookieFromAdministrator));

$gzipSnippet = SnippetBuilder::build('mod_maxcache', ['write_gzip' => 1]);
assertSameValue('gzip snippet declares HTML type for the actual writer extension', true,
    str_contains($gzipSnippet, 'AddType text/html .html_gzip'));
assertSameValue('gzip snippet declares compression for the actual writer extension', true,
    str_contains($gzipSnippet, 'AddEncoding gzip .html_gzip'));
assertSameValue('gzip MIME directives are guarded by the Apache MIME module', true,
    str_contains($gzipSnippet, '<IfModule mod_mime.c>'));
assertSameValue('gzip enabled selects the CloudLinux gzip suffix', true,
    str_contains($gzipSnippet, '.html{GZIP_SUFFIX}'));
foreach (['mod_maxcache', 'apache'] as $mode) {
    $plainSnippet = SnippetBuilder::build($mode, ['write_gzip' => 0]);
    assertSameValue('gzip disabled does not change encoding rules: ' . $mode, false,
        str_contains($plainSnippet, 'AddEncoding'));
    assertSameValue('gzip disabled does not enable compressed reads: ' . $mode, false,
        str_contains($plainSnippet, '{GZIP_SUFFIX}'));
}

$apacheSnippet = SnippetBuilder::buildApacheSnippet([
    'cache_root' => '/var/cache/joomla-maxcache',
    'exclude' => implode("\n", ['/lab-excluded(?:/.*|$)', '/with space',
        '/lab-quoted(?:" and space)?(?:/.*|$)', '/index\\.php/lab-regex(?:/.*|$)', '[broken']),
    'joomla_secret' => 'secret-value',
    'joomla_session_name' => 'administrator',
]);
assertSameValue('Apache snippet excludes frontend cookies when built from administrator', true, str_contains($apacheSnippet, $sessionCookieFromSite));
assertSameValue('Apache snippet keeps built-in sensitive URI exclusions', true, str_contains($apacheSnippet, 'component/users'));
assertSameValue('Apache snippet keeps valid configured URI exclusions', true, str_contains($apacheSnippet, '/lab-excluded(?:/.*|$)'));
assertSameValue('Apache snippet quotes exclusion patterns containing spaces', true, str_contains($apacheSnippet, "RewriteCond %{REQUEST_URI} '!"));
assertSameValue('Apache snippet preserves double quotes inside single-quoted patterns', true, str_contains($apacheSnippet, '(?:" and space)?'));
assertSameValue('Apache snippet preserves regex backslashes through config parsing', true, str_contains($apacheSnippet, '/index\\.php/lab-regex'));
assertSameValue('Apache snippet omits invalid configured URI exclusions', false, str_contains($apacheSnippet, '[broken'));
$unsafeQuotedSnippet = SnippetBuilder::buildApacheSnippet(['exclude' => "/lab[\"']"]);
assertSameValue('Apache disables static serving when neither quote delimiter is safe', true,
    str_contains($unsafeQuotedSnippet, 'RewriteCond %{REQUEST_URI} "!(?:^)" [NC]'));
assertSameValue('Apache explains how to recover safe static serving', true,
    str_contains($unsafeQuotedSnippet, 'encode regex quotes as \\x22/\\x27'));

// Tracking-only queries may receive the canonical page. Without a configured
// list the snippets stay byte-identical, so existing applied hashes remain valid.
foreach (['apache', 'mod_maxcache'] as $mode) {
    assertSameValue('empty tracking list keeps the snippet unchanged: ' . $mode,
        SnippetBuilder::build($mode, []), SnippetBuilder::build($mode, ['tracking_query_params' => '']));
}
assertSameValue('empty tracking list keeps Apache query bypass strict', true,
    str_contains(SnippetBuilder::buildApacheSnippet([]), 'RewriteCond %{QUERY_STRING} ^$'));
assertSameValue('empty tracking list emits no mod_maxcache query directives', false,
    str_contains(SnippetBuilder::buildModMaxcacheSnippet([]), 'MaxCacheQS'));

$trackingParams = "utm_source\nfbclid\np\nmaxcache_probe\nbad name\nutm_source\nutm.id";
$trackingApache = SnippetBuilder::buildApacheSnippet(['tracking_query_params' => $trackingParams]);
preg_match('/RewriteCond %\{QUERY_STRING\} (\S+)/', $trackingApache, $queryMatch);
$queryRegex = '~' . ($queryMatch[1] ?? '^$') . '~';
foreach ([
    '' => true,
    'utm_source=newsletter' => true,
    'fbclid=abc&utm_source=x' => true,
    'utm.id=7&' => true,
    'fbclid' => true,
    'utm_source=x&start=10' => false,
    'start=10&utm_source=x' => false,
    'start=10' => false,
    'utm_source_extra=1' => false,
    'p=customizer' => false,
    'maxcache_probe=1' => false,
    'bad%20name=1' => false,
    '&' => false,
] as $query => $served) {
    assertSameValue('Apache tracking query condition for "' . $query . '"', $served,
        preg_match($queryRegex, (string) $query) === 1);
}

$trackingMod = SnippetBuilder::buildModMaxcacheSnippet(['tracking_query_params' => $trackingParams]);
assertSameValue('mod_maxcache allows only valid tracking keys, sorted', true,
    str_contains($trackingMod, "MaxCacheQSAllowedParams fbclid utm.id utm_source\n"));
assertSameValue('mod_maxcache strips the same tracking keys from the cache key', true,
    str_contains($trackingMod, "MaxCacheQSIgnoredParams fbclid utm.id utm_source\n"));
assertSameValue('mod_maxcache keeps the query suffix for other keys', true,
    str_contains($trackingMod, '{REQUEST_URI}{QS_SUFFIX}/index'));
assertSameValue('tracking list order does not change the snippet', $trackingMod,
    SnippetBuilder::buildModMaxcacheSnippet(['tracking_query_params' => "utm.id\nutm_source\nfbclid"]));

$managedBlock = HtaccessManager::buildManagedBlock($snippet);
$appliedStatus = HtaccessManager::getStatusFromContents($snippet, $managedBlock);
$outdatedStatus = HtaccessManager::getStatusFromContents($snippet . "\n# changed", $managedBlock);
$missingStatus = HtaccessManager::getStatusFromContents($snippet, '');

assertSameValue('managed snippet status reports an exact hash match', 'applied', $appliedStatus['state']);
assertSameValue('managed snippet status reports a stale applied hash', 'outdated', $outdatedStatus['state']);
assertSameValue('managed snippet status reports a missing block', 'not_applied', $missingStatus['state']);

$token = '4ff978756936e28cf822853a6aea0c1a';
$tokenInput = '<input type="hidden" name="' . $token . '" value="1">';
$reorderedTokenInput = "<input value='1' class='form-control' name='" . strtoupper($token) . "' type='hidden'>";

assertSameValue('response safety detects a Joomla form token', $token, ResponseSafety::findJoomlaFormToken($tokenInput));
assertSameValue('response safety detects reordered and uppercase Joomla token attributes', $token, ResponseSafety::findJoomlaFormToken($reorderedTokenInput));
assertSameValue('response safety ignores ordinary hidden inputs', false, ResponseSafety::containsJoomlaFormToken('<input type="hidden" name="option" value="1">'));

$scriptOptions = '<script type="application/json" class="joomla-script-options new">'
    . '{"csrf.token":"3d641ea424ca1d6ed66b2dba0123abcd","system.paths":{"root":""}}</script>';
assertSameValue('response safety detects the core.js csrf.token script option', true,
    ResponseSafety::containsScriptOptionToken($scriptOptions));
$blankedOptions = ResponseSafety::removeScriptOptionToken($scriptOptions, $removedTokens);
assertSameValue('response safety blanks only the csrf.token value',
    '<script type="application/json" class="joomla-script-options new">{"csrf.token":"","system.paths":{"root":""}}</script>',
    $blankedOptions);
assertSameValue('response safety counts blanked script option tokens', 1, $removedTokens);
assertSameValue('a blanked script option token is no longer reported', false,
    ResponseSafety::containsScriptOptionToken($blankedOptions));
assertSameValue('pages without the script option are left unchanged', '<html>plain</html>',
    ResponseSafety::removeScriptOptionToken('<html>plain</html>', $removedTokens));
assertSameValue('pages without the script option report no blanked token', 0, $removedTokens);

foreach ([
    ['public, max-age=600', false],
    ['no-cache', false],
    ['max-age=60, NO-STORE', true],
    ['PRIVATE="Set-Cookie"', true],
    ['s-maxage=60, public', false],
    ['x-private=1', false],
] as [$control, $private]) {
    assertSameValue('response safety parses privacy directives: ' . $control, $private,
        ResponseSafety::hasPrivateCacheControl([['name' => 'Cache-Control', 'value' => $control]]));
}
assertSameValue('privacy words in unrelated headers do not disable caching', false,
    ResponseSafety::hasPrivateCacheControl([['name' => 'X-Debug', 'value' => 'private, no-store']]));

$installerScript = (string) file_get_contents(__DIR__ . '/../pkg_maxcache/script.php');
assertSameValue(
    'package postflight invalidates Joomla plugin metadata after enabling console commands',
    true,
    str_contains($installerScript, "Factory::getCache('com_plugins', 'callback')->clean()")
);
assertSameValue(
    'package postflight enables the signed fragment AJAX plugin',
    true,
    str_contains($installerScript, "['folder' => 'ajax', 'element' => 'maxcachefragment']")
);

$packageManifest = (string) file_get_contents(__DIR__ . '/../pkg_maxcache/pkg_maxcache.xml');
assertSameValue(
    'package manifest includes the dynamic fragment module',
    true,
    str_contains($packageManifest, 'id="maxcache_fragment">mod_maxcache_fragment.zip')
);
assertSameValue(
    'package manifest includes the fragment AJAX plugin',
    true,
    str_contains($packageManifest, 'id="maxcachefragment">plg_ajax_maxcachefragment.zip')
);

$fragmentLoader = (string) file_get_contents(
    __DIR__ . '/../pkg_maxcache/packages/mod_maxcache_fragment/media/js/fragment.js'
);
assertSameValue(
    'fragment loader rewrites endpoint-relative forms to the current page',
    true,
    str_contains($fragmentLoader, 'normalizeFragmentForms(fragment)')
);

$atomicDir = sys_get_temp_dir() . '/maxcache-tests-' . getmypid() . '-' . str_replace('.', '', uniqid('', true));
mkdir($atomicDir, 0700, true);
$atomicPath = $atomicDir . '/cache.html';

assertSameValue('atomic writer creates a new file', true, AtomicFileWriter::write($atomicPath, 'first'));
assertSameValue('atomic writer wrote expected first contents', 'first', (string) file_get_contents($atomicPath));
assertSameValue('atomic writer replaces existing file contents', true, AtomicFileWriter::write($atomicPath, 'second'));
assertSameValue('atomic writer wrote expected replacement contents', 'second', (string) file_get_contents($atomicPath));

unlink($atomicPath);
rmdir($atomicDir);

$operationsDir = sys_get_temp_dir() . '/maxcache-operations-tests-' . getmypid() . '-' . str_replace('.', '', uniqid('', true));
mkdir($operationsDir . '/nested', 0700, true);
file_put_contents($operationsDir . '/index-https.html', 'one');
file_put_contents($operationsDir . '/nested/index-mobile-https.html', 'second');
file_put_contents($operationsDir . '/nested/token.html', $tokenInput);

assertSameValue('cache operations reject the filesystem root', false, CacheOperations::isSafeRoot('/'));
assertSameValue('cache operations reject the site root itself', false, CacheOperations::isSafeRoot($operationsDir, $operationsDir));
assertSameValue('cache operations accept a dedicated cache directory', true, CacheOperations::isSafeRoot($operationsDir));
assertSameValue(
    'cache operations report file and byte totals',
    ['exists' => true, 'files' => 3, 'bytes' => 9 + strlen($tokenInput)],
    CacheOperations::stats($operationsDir)
);
assertSameValue(
    'cache operations report static artifacts containing Joomla form tokens',
    ['files' => 1, 'samples' => ['nested/token.html'], 'unreadable' => 0],
    CacheOperations::joomlaFormTokenArtifacts($operationsDir)
);
assertSameValue(
    'cache operations purge reports the removed tree',
    ['root' => $operationsDir, 'removed' => true, 'files' => 3, 'bytes' => 9 + strlen($tokenInput)],
    CacheOperations::purge($operationsDir)
);
assertSameValue('cache operations remove the cache root', false, is_dir($operationsDir));

// Artifacts written before the script option token was blanked must be purged too.
mkdir($operationsDir . '/legacy', 0700, true);
file_put_contents($operationsDir . '/legacy/index-https.html', $scriptOptions);
file_put_contents($operationsDir . '/legacy/blank.html', $blankedOptions);
assertSameValue('cache operations report artifacts with a cached csrf.token script option',
    ['files' => 1, 'samples' => ['legacy/index-https.html'], 'unreadable' => 0],
    CacheOperations::joomlaFormTokenArtifacts($operationsDir));
CacheOperations::purge($operationsDir);

$directoryOperationsRoot = sys_get_temp_dir() . '/maxcache-directory-tests-' . getmypid() . '-' . str_replace('.', '', uniqid('', true));
$nestedDirectory = $directoryOperationsRoot . '/host/ca/page';

assertSameValue('directory operations create nested directories without Joomla legacy filesystem classes', true, DirectoryOperations::ensure($nestedDirectory));
file_put_contents($nestedDirectory . '/index-https.html', 'cached');
assertSameValue(
    'directory operations list nested cache directories',
    true,
    \in_array($nestedDirectory, DirectoryOperations::listDirectories($directoryOperationsRoot), true)
);
assertSameValue('directory operations delete a nested cache tree', true, DirectoryOperations::delete($directoryOperationsRoot . '/host'));
assertSameValue('directory operations remove the requested cache tree', false, is_dir($directoryOperationsRoot . '/host'));
rmdir($directoryOperationsRoot);

$systemPluginSource = (string) file_get_contents(
    __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Extension/Maxcache.php'
);
$htaccessManagerSource = (string) file_get_contents(
    __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/HtaccessManager.php'
);
assertSameValue(
    'Joomla 6 runtime paths do not depend on the removed CMS Folder class',
    false,
    str_contains($systemPluginSource . $htaccessManagerSource, 'Joomla\\CMS\\Filesystem\\Folder')
);

// Every client must build the snippet from the same settings mapping, or a
// freshly applied block looks outdated from the admin status or the console.
$snippetSettings = [
    'server_snippet_mode' => 'apache',
    'cache_root' => '/home/example/public_html/maxcache',
    'site_hosts' => "www.example.org\nexample.org",
    'exclude' => " /lab-excluded(?:/.*|$) \n\n[broken\n",
    'bypass_cookies' => "z_cookie\na_cookie",
    'tracking_query_params' => "utm_source\ngclid",
    'write_gzip' => '1',
];
$expectedSnippetParams = [
    'cache_root' => '/home/example/public_html/maxcache',
    'site_hosts' => "www.example.org\nexample.org",
    'exclude' => implode("\n", BuiltInExclusions::filterCustomPatterns(['/lab-excluded(?:/.*|$)', '[broken'])),
    'bypass_cookies' => "z_cookie\na_cookie",
    'joomla_secret' => '',
    'joomla_session_name' => '',
    'joomla_session_cookie_names' => [],
    'tracking_query_params' => "utm_source\ngclid",
    'write_gzip' => 1,
];
assertSameValue('snippet params map saved settings', $expectedSnippetParams, SnippetParams::fromSettings($snippetSettings));
foreach (['apache', 'mod_maxcache'] as $mode) {
    assertSameValue('central snippet matches the previous apply mapping: ' . $mode,
        SnippetBuilder::build($mode, $expectedSnippetParams + ['allowed_query_params' => 'start']),
        SnippetParams::buildSnippet($snippetSettings, $mode));
}
assertSameValue('snippet mode comes from saved settings', SnippetBuilder::build('apache', $expectedSnippetParams),
    SnippetParams::buildSnippet($snippetSettings));
$emptyFormSettings = array_fill_keys(SnippetParams::SETTING_KEYS, null);
assertSameValue('unsaved form values use the plugin cache root and mod_maxcache',
    SnippetBuilder::build('mod_maxcache', ['cache_root' => SnippetParams::DEFAULT_CACHE_ROOT]),
    SnippetParams::buildSnippet($emptyFormSettings));
assertSameValue('empty saved hosts emit no Apache host condition', false,
    str_contains(SnippetParams::buildSnippet(['server_snippet_mode' => 'apache']), 'HTTP_HOST} ^'));

foreach ([
    'plg_system_maxcache/src/Extension/Maxcache.php',
    'plg_system_maxcache/src/Field/SnippetField.php',
    'plg_system_maxcache/src/Field/HtaccessStatusField.php',
    'plg_console_maxcache/src/Support/MaxcacheState.php',
] as $caller) {
    $callerSource = (string) file_get_contents(__DIR__ . '/../pkg_maxcache/packages/' . $caller);
    assertSameValue($caller . ' builds the snippet through SnippetParams', true,
        str_contains($callerSource, 'SnippetParams::buildSnippet('));
    assertSameValue($caller . ' does not build snippet params by hand', false,
        str_contains($callerSource, 'SnippetBuilder::build('));
}

echo "\nHelper tests: " . $assertions . " assertions\n";

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "\n" . $failure['label'] . "\n");
        fwrite(STDERR, 'Expected: ' . var_export($failure['expected'], true) . "\n");
        fwrite(STDERR, 'Actual:   ' . var_export($failure['actual'], true) . "\n");
    }

    exit(1);
}

// Runtime regressions use isolated Joomla API doubles; do not load them into
// this helper-test process or a real Joomla installation.
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/runtime.php'), $runtimeStatus);

if ($runtimeStatus !== 0) {
    exit($runtimeStatus);
}

// The browser token refresh script needs Node; say so instead of skipping silently.
$node = trim((string) shell_exec('command -v node 2>/dev/null'));

if ($node === '') {
    echo "csrf-refresh tests: SKIPPED (node not found)\n";
} else {
    passthru(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/csrf-refresh.test.js'), $nodeStatus);

    if ($nodeStatus !== 0) {
        exit($nodeStatus);
    }
}

echo "All tests passed.\n";

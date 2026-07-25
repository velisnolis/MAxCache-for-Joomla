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

$failures = [];

function assertSameValue(string $label, mixed $expected, mixed $actual): void
{
    global $failures;

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
    '/cache/maxcache/www.gibaix.com/ca/agenda/index-https.html',
    cachePath([
        'host' => 'www.gibaix.com',
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

$sessionCookieFromConfiguredName = md5('secret-value' . 'custom-session-name');
$sessionCookieFromSiteApplication = md5('secret-value' . 'Joomla\\CMS\\Application\\SiteApplication');
$sessionCookieFromService = '44c05088706fb5e957c981e7d16d5a4d';

assertSameValue(
    'effective bypass cookies include configured cookies, resolved service session cookies, and Joomla frontend session cookies',
    ['joomla_user_state', $sessionCookieFromService, $sessionCookieFromConfiguredName, $sessionCookieFromSiteApplication],
    BypassCookieNames::mergeWithJoomlaSessionCookies(
        ['joomla_user_state'],
        'secret-value',
        'custom-session-name',
        [$sessionCookieFromService]
    )
);

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

echo "\n";

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "\n" . $failure['label'] . "\n");
        fwrite(STDERR, 'Expected: ' . var_export($failure['expected'], true) . "\n");
        fwrite(STDERR, 'Actual:   ' . var_export($failure['actual'], true) . "\n");
    }

    exit(1);
}

echo "All tests passed.\n";

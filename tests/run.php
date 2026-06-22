<?php

declare(strict_types=1);

const _JEXEC = 1;

require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/CachePathBuilder.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/LanguageRoutingProfile.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/AtomicFileWriter.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/RegexPatternMatcher.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/CachePathHelper.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/BuiltInExclusions.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/BypassCookieNames.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/SnippetBuilder.php';

use Vendor\Plugin\System\Maxcache\Support\AtomicFileWriter;
use Vendor\Plugin\System\Maxcache\Support\BypassCookieNames;
use Vendor\Plugin\System\Maxcache\Support\CachePathBuilder;
use Vendor\Plugin\System\Maxcache\Support\LanguageRoutingProfile;
use Vendor\Plugin\System\Maxcache\Support\RegexPatternMatcher;
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

$sessionCookieFromConfiguredName = md5('secret-value' . 'custom-session-name');
$sessionCookieFromSiteApplication = md5('secret-value' . 'Joomla\\CMS\\Application\\SiteApplication');

assertSameValue(
    'effective bypass cookies include configured cookies and Joomla frontend session cookies',
    ['joomla_user_state', $sessionCookieFromConfiguredName, $sessionCookieFromSiteApplication],
    BypassCookieNames::mergeWithJoomlaSessionCookies(['joomla_user_state'], 'secret-value', 'custom-session-name')
);

$snippet = SnippetBuilder::buildModMaxcacheSnippet([
    'cache_root' => '/var/cache/joomla-maxcache',
    'exclude' => "/ok(?:/.*|$)\n[broken",
    'bypass_cookies' => 'joomla_user_state',
    'joomla_secret' => 'secret-value',
    'joomla_session_name' => 'custom-session-name',
]);

assertSameValue('snippet keeps valid custom exclusion regex', true, str_contains($snippet, '/ok(?:/.*|$)'));
assertSameValue('snippet omits invalid custom exclusion regex', false, str_contains($snippet, '[broken'));
assertSameValue('snippet excludes configured bypass cookie', true, str_contains($snippet, 'joomla_user_state'));
assertSameValue('snippet excludes configured Joomla session-name cookie', true, str_contains($snippet, $sessionCookieFromConfiguredName));
assertSameValue('snippet excludes Joomla frontend application session cookie', true, str_contains($snippet, $sessionCookieFromSiteApplication));

$atomicDir = sys_get_temp_dir() . '/maxcache-tests-' . getmypid() . '-' . str_replace('.', '', uniqid('', true));
mkdir($atomicDir, 0700, true);
$atomicPath = $atomicDir . '/cache.html';

assertSameValue('atomic writer creates a new file', true, AtomicFileWriter::write($atomicPath, 'first'));
assertSameValue('atomic writer wrote expected first contents', 'first', (string) file_get_contents($atomicPath));
assertSameValue('atomic writer replaces existing file contents', true, AtomicFileWriter::write($atomicPath, 'second'));
assertSameValue('atomic writer wrote expected replacement contents', 'second', (string) file_get_contents($atomicPath));

unlink($atomicPath);
rmdir($atomicDir);

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

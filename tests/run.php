<?php

declare(strict_types=1);

const _JEXEC = 1;

require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/CachePathBuilder.php';
require_once __DIR__ . '/../pkg_maxcache/packages/plg_system_maxcache/src/Support/LanguageRoutingProfile.php';

use Vendor\Plugin\System\Maxcache\Support\CachePathBuilder;
use Vendor\Plugin\System\Maxcache\Support\LanguageRoutingProfile;

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

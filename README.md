# MAxCache for Joomla

MAxCache for Joomla is a Joomla extension package that reuses the native `System - Cache`
administrator UX, but writes deterministic static HTML artifacts so Apache or CloudLinux
MAx Cache can serve them directly.

## Package Contents

The current package installs:

- `plg_system_maxcache`

This repository is structured as a Joomla package repo, ready for GitHub releases and
Joomla auto-updates.

## Current Scope

The plugin currently includes:

- Joomla-native cache plugin style configuration UI
- guest HTML request eligibility checks
- exclusions by menu item, URL pattern, pagecache listener, cookies, and query params
- deterministic static cache path generation
- optional gzip artifact writing
- targeted/full purge scaffolding
- `.htaccess` status detection
- snippet preview
- explicit `Apply snippet` action with backup support

## New in 0.1.31

- prefixed language cache paths:
  - fixes duplicated language prefixes in generated paths such as `/cat/cat/llet`
  - shares language-routing detection between runtime and installer recommendations
  - adds local tests for real `/cat/`, `/esp/`, and `/ca/` URL cases
- admin and operations:
  - warns when Joomla `System - Cache` is enabled and can serve pages before MAx Cache
  - caches the admin snippet-status check briefly to avoid Admin Tools CLI shell-outs on every admin render
  - requests Joomla cache cleaning when purging MAx Cache or saving MAx Cache configuration
- invalidation and hardening:
  - purges stale static files on content/menu state changes and deletes, not only saves
  - writes static artifacts and managed `.htaccess` changes atomically
  - reports invalid exclusion regex patterns and skips them at runtime/snippet generation

## New in 0.1.30

- admin purge button:
  - show the `Purge MAx Cache` action whenever the plugin is active in authenticated admin pages
  - no longer require the generated server snippet to be applied before exposing the purge action
  - warn when the server snippet is not applied, outdated, or cannot be verified

## New in 0.1.29

- language-prefixed cache path detection:
  - detects Joomla language SEF prefixes such as `/ca/`, `/cat/`, and `/esp/`
  - recommends language-aware cache paths whenever Language Filter exposes a prefix, even with a single published language
- System Cache exclusion inheritance:
  - MAx Cache now applies `System - Cache` excluded menu items and URL patterns at runtime
  - server snippet preview/status/apply use the same effective exclusions
- admin UX:
  - inherited `System - Cache` exclusions are shown in the built-in exclusions panel
  - admin purge button remains hidden on the login screen
  - admin purge button remains available in tablet/mobile layouts through a floating fallback

## New in 0.1.28

- Admin Tools compatibility fix:
  - Joomla CLI calls now enable `apc.enable_cli=1` automatically when the site uses `apcu`
  - this fixes Admin Tools snippet apply/read failures on hosts where APCu is disabled for CLI
- guest session tracking warning:
  - adds a prominent warning when Joomla `Guest Session Tracking` is enabled
  - repeats the warning after `Apply snippet` so guest-cache bypasses are harder to miss

## New in 0.1.27

- full purge automation controls:
  - regenerate after Joomla save
  - regenerate on interval
- direct integration with Regular Labs Cache Cleaner:
  - save + integrate from the MAx Cache settings page
  - automatically add `maxcache` to Cache Cleaner custom folders
  - copy MAx Cache full-regeneration settings into Cache Cleaner
- more accurate Regular Labs detection:
  - only reports Cache Cleaner when the extension is actually installed
- admin form fixes:
  - fixed broken inline JavaScript in `Site Hosts`
  - hide full-regeneration controls when purge strategy is `Targeted`

## Repository Layout

- `pkg_maxcache/`: Joomla package source
- `pkg_maxcache/packages/plg_system_maxcache/`: system plugin source
- `updates/pkg_maxcache.xml`: Joomla update feed
- `docker/build-package.sh`: local package build script
- `docs/architecture.md`: architecture notes

## Building the Installable Package

Build the package with:

```bash
./docker/build-package.sh
```

Output:

- `.docker-build/pkg_maxcache-<version>.zip`
- `.docker-build/pkg_maxcache-lab.zip`
- `updates/pkg_maxcache.xml`

## Local Tests

Run the dependency-free local checks with:

```bash
php tests/run.php
```

## Joomla Auto-Updates

The package manifest is set up to use a Joomla update server feed at:

- `https://raw.githubusercontent.com/velisnolis/MAxCache-for-Joomla/main/updates/pkg_maxcache.xml`

The build script regenerates that feed with the current version, the matching GitHub
release asset URL, and the `sha256` checksum.

## Safe `.htaccess` Model

The plugin does not auto-write `.htaccess` on normal save.

Intended admin flow:

1. Save plugin settings
2. Preview snippet
3. Explicitly apply snippet

Each apply action should:

- require confirmation
- create a timestamped backup
- mark the applied hash
- warn when Akeeba Admin Tools markers are detected

## Cookie Guidance

`bypass_cookies` should only contain cookies that change the server-rendered HTML.

Typical examples:

- Joomla session or login cookies
- remember-me cookies
- anti-bot or edge challenge cookies when they materially change delivery

Do not include client-side consent cookies such as `yootheme_consent`.
That cookie should not split or bypass full-page cache, because it only controls banner
visibility in the browser through JavaScript.

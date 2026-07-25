# MAxCache for Joomla

MAxCache for Joomla is a Joomla extension package that reuses the native `System - Cache`
administrator UX, but writes deterministic static HTML artifacts so Apache or CloudLinux
MAx Cache can serve them directly.

## Package Contents

The current package installs:

- `plg_system_maxcache`
- `plg_console_maxcache`

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

## New in 0.1.37

- Refreshes Joomla's `com_plugins` metadata cache after package install/update,
  so newly enabled `maxcache:*` console commands are available immediately.
- `maxcache:status` and `maxcache:diagnose` now compare the applied `.htaccess`
  snippet hash with the current effective configuration and report
  `applied`, `outdated`, or `not_applied`.
- Keeps server configuration changes explicit: diagnosis reports drift but never
  rewrites `.htaccess`.

## New in 0.1.36

- Joomla console operations:
  - `maxcache:status` reports the installed version, effective safeguards, cache root, artifact counts, and server integration
  - `maxcache:diagnose` reports configuration errors and warnings, with JSON and strict modes for automation
  - `maxcache:purge` is a dry run unless the operator passes `--confirm`
- shared purge safety:
  - administrator and console purges use the same cache-root validation and filesystem operation
- automation-safe route diagnostics:
  - report redirect targets in a `location` column
  - `--fail-on-risk` returns exit code 1 when the final phase is not conclusively safe
  - deterministic shell fixtures cover excluded routes, fresh/stale CSRF tokens, redirects, and missing routes

## New in 0.1.35

- route diagnostics verdicts:
  - add a `verdict` column to `scripts/maxcache-route-report.sh`
  - flags stale-cache masking, post-purge missing routes, stale CSRF tokens, fresh CSRF tokens, and verified exclusions
- migration checklist:
  - document the pre/post purge workflow for Joomla major migrations
  - include route sets for multilingual roots and `com_users` reset/remind forms

## New in 0.1.34

- safer built-in exclusions:
  - exclude Joomla `com_users` and `/component/users` routes from runtime writes and generated server snippets
  - prevents reset/remind/login/profile forms from being cached with stale CSRF tokens
- route diagnostics:
  - add `scripts/maxcache-route-report.sh` for migration pre/post purge checks
  - reports HTTP status, MAxCache debug headers, matching static files, and Joomla-style token changes

## New in 0.1.33

- resolved Joomla session-cookie bypass:
  - generated Apache and mod_maxcache snippets now also include real session names from Joomla's session services when available
  - fixes sites where the runtime session cookie differs from the configured `session_name` hash

## New in 0.1.32

- server-side login/session bypass:
  - generated Apache and mod_maxcache snippets now include Joomla frontend session cookie names derived from the site secret
  - snippet preview, status checks, and apply actions use the same effective bypass-cookie list
  - runtime bypass checks use the configured cookies plus Joomla session-cookie candidates

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
tests/route-report.sh
```

## Joomla Console Commands

The package enables its companion console plugin during install/update. Run the
commands from the Joomla root:

```bash
php cli/joomla.php maxcache:status
php cli/joomla.php maxcache:status --format=json
php cli/joomla.php maxcache:diagnose --strict
php cli/joomla.php maxcache:purge
php cli/joomla.php maxcache:purge --confirm
```

`maxcache:purge` without `--confirm` only prints the exact cache root and the
file/byte count it would remove. The command refuses an empty, relative, symlinked,
site-root, or known system-level cache path.

## Route Diagnostics

For migration or purge checks, generate a pre/post route report with:

```bash
scripts/maxcache-route-report.sh --base-url https://example.com --routes routes.txt --cache-root /home/example/public_html/maxcache
```

The script is read-only unless `--purge-cmd` is provided. Use it before trusting
cached HTTP `200` responses during migrations: a route that becomes `404` after purge
was being masked by stale static HTML, not confirmed as a live Joomla route.

For CSRF-sensitive forms, include the reset/remind/login routes in `routes.txt`.
The report extracts Joomla-style hidden token names twice with cache-busting query
parameters and flags whether the token changes. The final `verdict` column gives
a per-phase hint such as `cache-present-verify-post-purge`, `live-route-missing-after-purge`,
or `csrf-token-stale`.

Use `--fail-on-risk` in migration automation. It exits non-zero unless every route
in the final phase is `live-ok`, `excluded-ok`, or `csrf-token-fresh`. Redirects
remain unresolved until their reported `location` target is added to the route file.

Use `docs/migration-purge-checklist.md` as the operational checklist for Joomla
major migrations and MAx Cache purge reviews.

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

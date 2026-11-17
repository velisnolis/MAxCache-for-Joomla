# Changelog

Release notes for MAxCache for Joomla, newest first. Each version is published as a
GitHub release with the installable ZIP.

## Unreleased

- `maxcache:warm` requests the published top-level menu items as a guest so their
  pages are stored again after a purge. See [Warm-up](README.md#warm-up).
- Catalan (`ca-ES`) and Spanish (`es-ES`) translations of the settings form and
  extension names. Status panels and administrator messages remain in English.

## 0.1.42

- Admin Tools status reads saved configuration without loading editor form plugins.
  Explicit apply/rebuild runs Admin Tools' own validated commands inside the current
  Joomla administrator request. It no longer starts a separate PHP CLI process,
  avoiding the JCE Pro 3.0.4 `ConsoleApplication::getDocument()` failure.
- Admin Tools backups are verified before any configuration change, use distinct
  names across repeated applies, and the managed block is checked after rebuilding.
- The server status field uses the saved snippet settings without PHP warnings.

No change to the generated server snippet or cache artifacts. Updating from 0.1.41
needs no purge. If a previous Admin Tools apply failed, use the explicit Apply
snippet action after updating.

## 0.1.41

- Other extensions can request a full purge (static cache plus Joomla caches) by
  dispatching `onMaxcachePurgeAll` inside the same Joomla request. The outcome is
  returned in the event's `result` argument. See
  [Purging From Another Extension](#purging-from-another-extension).

No change to the server snippet, cache layout or stored pages: updating from
0.1.40 needs no snippet reapply or purge.

## 0.1.40

- Public cache directories are created with `0755`, including under a restrictive
  PHP umask, so Apache can read artifacts when it runs as a different Unix user.
- Shared artifacts no longer retain a visitor's `csrf.token`. The browser obtains
  its own token on demand; pages are not stored when the refresh loader is unavailable.
- Admin actions enforce Joomla ACLs as well as CSRF validation. The final response
  is checked before sending; private responses, errors and hidden-token forms
  remove matching older plain/gzip artifacts.
- Session-cookie exclusions, anchored URL patterns, gzip MIME declarations and
  snippet hashes are consistent across the administrator, frontend and CLI.
- Optional tracking query parameters serve the canonical cached page. All queries
  continue to bypass static writes, preserving the canonical artifact.
- Route reports identify incomplete token probes and correctly accept routes
  rendered and stored live after a purge.

After updating, review existing cache directory permissions, review/reapply the
server snippet, and purge/regenerate old artifacts with a verified backup and
pre/post route checks. Installing the package alone does not repair previously
stored pages or existing directory permissions.

## 0.1.39

- Joomla 6 filesystem compatibility:
  - replaces runtime uses of the removed `Joomla\CMS\Filesystem\Folder` class
    with dependency-free directory operations
  - restores automatic creation of `.htaccess` and Admin Tools backup
    directories when they do not exist yet
  - keeps cache artifact creation and targeted directory purges operational
    after a Joomla 5.4 to 6 migration
- Adds regression coverage for nested directory creation, discovery, and
  recursive deletion without Joomla legacy filesystem classes.

## 0.1.38

- Dynamic Joomla module fragments:
  - `MAxCache Dynamic Fragment` outputs a cache-safe placeholder instead of
    executing the selected source module inside the static page shell
  - the signed `com_ajax` request renders only the source module configured by
    a published and accessible fragment instance
  - `visible`, `immediate`, and manual loading triggers support login modals,
    mobile dialogs, and inline dynamic blocks
- Response safety:
  - static writes are rejected with `csrf-token-in-response` when the final HTML
    contains a Joomla form token
  - an unsafe response removes the matching old plain/gzip artifacts instead of
    leaving stale token-bearing HTML available
  - console status/diagnosis reports `csrf-token-in-cache` with bounded samples
    when existing static artifacts contain Joomla form tokens

See [Dynamic module fragments](docs/dynamic-fragments.md) for the setup pattern
used with login modules, Modules Anywhere, and Conditional Content.

## 0.1.37

- Refreshes Joomla's `com_plugins` metadata cache after package install/update,
  so newly enabled `maxcache:*` console commands are available immediately.
- `maxcache:status` and `maxcache:diagnose` now compare the applied `.htaccess`
  snippet hash with the current effective configuration and report
  `applied`, `outdated`, or `not_applied`.
- Keeps server configuration changes explicit: diagnosis reports drift but never
  rewrites `.htaccess`.

## 0.1.36

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

## 0.1.35

- route diagnostics verdicts:
  - add a `verdict` column to `scripts/maxcache-route-report.sh`
  - flags stale-cache masking, post-purge missing routes, stale CSRF tokens, fresh CSRF tokens, and verified exclusions
- migration checklist:
  - document the pre/post purge workflow for Joomla major migrations
  - include route sets for multilingual roots and `com_users` reset/remind forms

## 0.1.34

- safer built-in exclusions:
  - exclude Joomla `com_users` and `/component/users` routes from runtime writes and generated server snippets
  - prevents reset/remind/login/profile forms from being cached with stale CSRF tokens
- route diagnostics:
  - add `scripts/maxcache-route-report.sh` for migration pre/post purge checks
  - reports HTTP status, MAxCache debug headers, matching static files, and Joomla-style token changes

## 0.1.33

- resolved Joomla session-cookie bypass:
  - generated Apache and mod_maxcache snippets now also include real session names from Joomla's session services when available
  - fixes sites where the runtime session cookie differs from the configured `session_name` hash

## 0.1.32

- server-side login/session bypass:
  - generated Apache and mod_maxcache snippets now include Joomla frontend session cookie names derived from the site secret
  - snippet preview, status checks, and apply actions use the same effective bypass-cookie list
  - runtime bypass checks use the configured cookies plus Joomla session-cookie candidates

## 0.1.31

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

## 0.1.30

- admin purge button:
  - show the `Purge MAx Cache` action whenever the plugin is active in authenticated admin pages
  - no longer require the generated server snippet to be applied before exposing the purge action
  - warn when the server snippet is not applied, outdated, or cannot be verified

## 0.1.29

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

## 0.1.28

- Admin Tools compatibility fix:
  - Joomla CLI calls now enable `apc.enable_cli=1` automatically when the site uses `apcu`
  - this fixes Admin Tools snippet apply/read failures on hosts where APCu is disabled for CLI
- guest session tracking warning:
  - adds a prominent warning when Joomla `Guest Session Tracking` is enabled
  - repeats the warning after `Apply snippet` so guest-cache bypasses are harder to miss

## 0.1.27

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

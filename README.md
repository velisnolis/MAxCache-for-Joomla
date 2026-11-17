# MAxCache for Joomla

MAxCache for Joomla is a Joomla extension package that reuses the native `System - Cache`
administrator UX, but writes deterministic static HTML artifacts so Apache or CloudLinux
MAx Cache can serve them directly.

## Package Contents

The current package installs:

- `plg_system_maxcache`
- `plg_console_maxcache`
- `plg_ajax_maxcachefragment`
- `mod_maxcache_fragment`

This repository is structured as a Joomla package repo, ready for GitHub releases and
Joomla auto-updates.

## Current Scope

- Joomla-native cache plugin style configuration UI, compatible with Joomla 5 and 6
- guest HTML request eligibility checks, rechecked against the final response
- exclusions by menu item, URL pattern, pagecache listener, cookies, and query params,
  including the exclusions saved in Joomla's `System - Cache`
- deterministic static cache paths, with language and optional mobile variants
- optional gzip artifacts
- targeted purge on article and menu item save, state change and delete; full purge
  from the administrator, the console, a schedule, or another extension
- generated Apache / `mod_maxcache` snippet with preview, status detection and an
  explicit `Apply snippet` action with backup, also through Akeeba Admin Tools
- dynamic module fragments and on-demand CSRF tokens for cached pages
- console commands for status, diagnosis, purge and warm-up

Known limits:

- Pages with a Joomla form token are not stored unless
  [Cache Pages with Forms](#pages-with-forms) is on.
- Requests with a query string are never stored; see
  [Runtime Safeguards](#runtime-safeguards).
- Targeted purge covers the changed article and the menu items that link to it.
  Category, blog and module listings that show the article keep their stored copy
  until a full purge.
- After a purge, `maxcache:warm` rebuilds only the top-level menu pages, and only
  when it is run. For every other page the first visitor pays for the full Joomla
  render.

Release notes are in [CHANGELOG.md](CHANGELOG.md).

## Repository Layout

- `pkg_maxcache/`: Joomla package source
- `pkg_maxcache/packages/plg_system_maxcache/`: system plugin source
- `pkg_maxcache/packages/plg_console_maxcache/`: console commands
- `pkg_maxcache/packages/plg_ajax_maxcachefragment/` and `mod_maxcache_fragment/`:
  dynamic fragments and token refresh
- `updates/pkg_maxcache.xml`: Joomla update feed
- `docker/build-package.sh`: package build script
- `scripts/maxcache-route-report.sh`: route diagnostics
- `tests/`: local tests; `tests/integration/` needs an isolated Joomla 6
- `docs/architecture.md`: architecture notes
- `CHANGELOG.md`: release notes

## Building the Installable Package

Build the package with:

```bash
./docker/build-package.sh
```

Output:

- `.docker-build/pkg_maxcache-<version>.zip`
- `.docker-build/pkg_maxcache-lab.zip`
- `updates/pkg_maxcache.xml`

The build rewrites the update feed with the checksum of the ZIP it has just produced.
Pass `--no-feed` to build without touching `updates/`, for example to test a
candidate; CI does the same.

## Local Tests

Run the dependency-free local checks with:

```bash
php tests/run.php
tests/route-report.sh
```

`tests/run.php` includes the runtime event regressions in `tests/runtime.php`.
They use Joomla API doubles and an isolated temporary filesystem, not a live
Joomla installation. They verify denied/authorized admin actions, transmitted
safety headers, plain/gzip artifacts, and query requests preserving canonical HTML.
When Node is available it also runs `tests/csrf-refresh.test.js`, which tests the
browser token refresh script against doubles for `core.js` and `fetch`.

GitHub Actions runs the same checks on every pull request and push to `main`, on
PHP 8.1 to 8.5, together with a syntax check and a package build
(`.github/workflows/ci.yml`). The Joomla 6 integration run in `tests/integration/`
is not part of CI: it needs an isolated Joomla installation.

## Runtime Safeguards

Administrative web actions require a logged-in identity with backend access.
Purging also requires `core.manage` on `com_cache`; applying server rules or
changing Cache Cleaner integration requires global `core.admin`. A valid CSRF
token remains mandatory and never substitutes for authorization. The admin purge
button is hidden from users without purge permission.

Before sending the response, MAxCache rejects explicit `Cache-Control: private`
or `no-store`, Joomla form tokens, and a response that is no longer a guest HTML
200. Rejected responses send `private, no-store` and remove matching plain/gzip
artifacts, including an old gzip companion when gzip writing is disabled.
Public HTML remains cacheable independently of Joomla's generic browser-cache
headers, which Joomla adds later inside `respond()`.

Query-string caching is temporarily disabled, including previously allowlisted
parameters: the writer does not yet implement query-specific cache keys. The
saved `allowed_query_params` field is retained for compatibility but has no effect;
generated snippets no longer emit `MaxCacheQSAllowedParams` for that field. This preserves the
canonical URL's HTML instead of replacing it with search or pagination results.

`tracking_query_params` is an optional, empty-by-default list of parameters that
never change server-rendered HTML, such as `utm_source`, `gclid` or `fbclid`. When a
request's query contains only these names, the server rules serve the canonical
cached page; PHP still never writes from such a request. In Apache mode, any other
key falls through to Joomla. In `mod_maxcache` mode the snippet lists the names in
both `MaxCacheQSAllowedParams` and `MaxCacheQSIgnoredParams`, relying on CloudLinux's
documented behaviour: a tracking-only query yields an empty `{QS_SUFFIX}`, and other
keys keep a `#query` suffix the writer never creates. This was verified on 2026-10-12
with synthetic canaries on a single-language and a multilingual site (CloudLinux
`mod_maxcache`); see `docs/validation-2026-10-12.md`. Names are case-sensitive.
Leave the list empty when a page reads these parameters in PHP, for example a form
field prefilled from `utm_source`. The names `p` and `maxcache_probe` are ignored.

Stored artifacts no longer keep the `csrf.token` script option of the request
that generated them. The browser fetches the visitor's own token only when a
non-GET `Joomla.request()` needs it; see `docs/dynamic-fragments.md`.
`maxcache:diagnose` reports older artifacts that still contain one.
Pages with a script option token are not stored unless the refresh loader can
be inserted; otherwise MAxCache reports `csrf-refresh-unavailable` and removes
older artifacts for that route.

New public cache directories use `0755`, including under a restrictive PHP
umask, so Apache can traverse them when it runs as a different Unix user from
PHP. Private backup directories retain the separate `0750` default. Existing
cache directory permissions require a scoped operational repair.

When deploying 0.1.40, review/apply the updated server snippet and perform an
approved, backed-up purge with pre/post route checks. Old artifacts served before PHP runs are not repaired
by installing a write guard alone.

## Pages with Forms

By default a page whose HTML contains a Joomla form token is never stored
(`csrf-token-in-response`). That covers RSForm! Pro, `com_contact` and any other
form that prints `form.token`: those pages are rendered by Joomla on every visit.

**Cache Pages with Forms** (`cache_form_pages`, off by default) stores them:

1. The stored copy has each token input replaced by
   `<input type="hidden" data-maxcache-form-token>`. The visitor who triggered the
   render still gets their real token.
2. When a visitor first focuses, clicks or types in such a form, the browser asks
   `com_ajax` for their own token and fills the input. This starts their Joomla
   session, so from then on their requests bypass the static cache.
3. A submit that arrives before the token is held, then sent again through
   `requestSubmit()` so the form's own validation runs. `form.submit()` waits too.
4. If the token is also used outside its input (a link, an inline script), the page
   is not stored.
5. The token is only written into `POST` forms that submit to the same site,
   including through any button's `formaction`; it is withdrawn if that changes
   before the form is sent.

Requirements and limits:

- JavaScript, the `Ajax - MAxCache Fragment` plugin and the refresh script. Without
  JavaScript the form is sent without a token and Joomla rejects it.
- Only the token is per visitor. Everything else in the form is frozen as rendered
  when the page was stored, until the next purge. Do not enable it for forms with:
  - time-based spam checks or hidden fields holding a timestamp, date, IP address
    or one-time code
  - fields prefilled from the visitor, the session or the request
  - a server-generated captcha whose challenge is embedded in the HTML
- A script that serialises the form itself and sends it over AJAX gets the token
  only if the visitor touched the form first, which is the usual case.
- A form extension with its own `pagecache` exclusion keeps excluding its pages.
  For RSForm! Pro, disable the `Page Cache - RSForm! Pro` plugin.
- Purge after changing the setting, and test every form on the site.

Tested on Joomla 6.2 with RSForm! Pro 3.4.6 (single-page and multipage example
forms, default settings). Not tested: RSForm AJAX validation, its built-in captcha,
reCAPTCHA, file uploads, forms shown through a module or content plugin, and other
form extensions.

## Joomla Console Commands

The package enables its companion console plugin during install/update. Run the
commands from the Joomla root:

```bash
php cli/joomla.php maxcache:status
php cli/joomla.php maxcache:status --format=json
php cli/joomla.php maxcache:diagnose --strict
php cli/joomla.php maxcache:purge
php cli/joomla.php maxcache:purge --confirm
php cli/joomla.php maxcache:warm --dry-run
php cli/joomla.php maxcache:warm --base-url=https://www.example.com
```

`maxcache:purge` without `--confirm` only prints the exact cache root and the
file/byte count it would remove. The command refuses an empty, relative, symlinked,
site-root, or known system-level cache path.

### Warm-up

`maxcache:warm` requests the published top-level menu items of the site menus as a
guest, so the system plugin stores them again after a purge. Submenu items, articles
and category pages are not requested.

- The URLs are built from the menu paths, the SEF settings and the Language Filter
  prefixes, not by Joomla's router. An item assigned to all languages is requested
  once per published language. Run `--dry-run` first on a new site and compare the
  list with the real menu.
- `--base-url` selects the scheme and host. Without it the command uses `live_site`,
  then the first entry of `site_hosts` over `https`. Artifacts are per host and
  scheme, so warm the host visitors actually use.
- Pages whose artifact already exists are reported as `cached` and not requested.
  Redirects are reported (`http-301`) and not followed. `not-stored` means Joomla
  answered 200 but no artifact appeared: the page is excluded, bypassed, or stored
  under a pagecache-plugin variant. Only the desktop variant is warmed.
- Requests run one at a time with a 200 ms pause (`--delay-ms`) and a 20 s timeout
  (`--timeout`). The exit code is 1 when a request fails or returns a 5xx.

To warm after a scheduled purge, run it from cron as the site's user.

## Purging From Another Extension

Other extensions in the same Joomla request can ask for the same full purge as the
admin button (static cache plus Joomla caches) by dispatching `onMaxcachePurgeAll`.
Miras Backups does it after restoring files. The event is not reachable over HTTP,
so it carries no ACL or token check of its own; the caller is already code running
inside Joomla.

```php
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\Event;

if (PluginHelper::isEnabled('system', 'maxcache')) {
    $event = new Event('onMaxcachePurgeAll', ['result' => null]);
    Factory::getApplication()->getDispatcher()->dispatch('onMaxcachePurgeAll', $event);
    $result = $event->getArgument('result');
}
```

`result` is `null` when the installed MAx Cache predates the event. Otherwise it
holds `root`, `removed`, `files`, `bytes` and `joomla_caches_cleaned`, plus
`skipped: unsafe_root` when the configured cache root fails the same safety check
as `maxcache:purge`, or only `error` with a message if the purge threw.

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
`csrf-token-stale`, or `csrf-token-missing`. A fresh-token verdict requires both
independent probes to return a token and for those tokens to differ.

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

Admin flow:

1. Save plugin settings
2. Preview snippet
3. Explicitly apply snippet

Each apply action requires confirmation, creates a timestamped backup and records
the applied hash, so the status field can report `applied`, `outdated` or
`not_applied`. When Akeeba Admin Tools manages `.htaccess`, the block goes into its
custom footer rules and Admin Tools rebuilds the file; without it, the plugin writes
its own managed block and warns if Admin Tools markers are present.

## Cookie Guidance

`bypass_cookies` should only contain cookies that change the server-rendered HTML.

Typical examples:

- Joomla session or login cookies
- remember-me cookies
- anti-bot or edge challenge cookies when they materially change delivery

Do not include client-side consent cookies such as `yootheme_consent`.
That cookie should not split or bypass full-page cache, because it only controls banner
visibility in the browser through JavaScript.

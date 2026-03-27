# Architecture

## Why not reuse Joomla core page cache internals

Joomla core page cache stores responses as internal cache entries keyed by a hash
derived from the full URL and optional pagecache plugin inputs.

That works for Joomla-managed response caching, but not for MAx Cache, because
`mod_maxcache` needs a predictable filesystem path for a request.

## Reused from core plugin UX

The new plugin should preserve the same admin ergonomics as `System - Cache`:

- enable / disable as a normal `system` plugin
- `browsercache`
- `exclude_menu_items`
- `exclude`

These three fields map directly to the current Joomla administrator experience.

The plugin should also reuse the existing `pagecache` plugin ecosystem where possible.
That matters for sites already relying on plugins like RSForm's page cache exclusion
listener, because the MAx Cache pipeline can import the same `pagecache` listeners
and honour their exclusion decisions.

## New MAx Cache fields

- `cache_root`: root directory for generated static files
- `path_mode`: how the plugin builds a filesystem path
- `vary_language`: create separate files per active site language
- `vary_mobile`: create separate files per mobile/desktop split
- `bypass_cookies`: requests with these cookies skip static cache delivery
- `allowed_query_params`: only these query parameters can participate in cache keys
- `write_gzip`: write precompressed output alongside HTML
- `purge_strategy`: full purge or targeted purge
- `debug_headers`: add MAx Cache debug headers
- `server_snippet_mode`: choose Apache / generic mod_maxcache snippet output
- `site_hosts`: known frontend hosts for targeted purge and snippet generation

## Request lifecycle

1. `onAfterRoute`
Checks whether the request is a frontend guest `GET` that is eligible for static cache.

2. Request eligibility
Reject when any of the following are true:

- logged-in user
- POST/PUT/DELETE/etc.
- active message queue
- excluded menu item
- excluded URL pattern
- bypass cookie present
- unsupported query parameters present
- response expected to be non-HTML

Cookie policy:

- bypass only on cookies that alter the server-rendered document
- do not bypass on client-side consent cookies such as `yootheme_consent`
- banner visibility should be handled by front-end JavaScript on top of the same cached HTML

3. Cache key model
Build a deterministic path instead of a Joomla hash:

`{cache_root}/{HTTP_HOST}/{language-prefix}/{normalized-sef-path}/index{mobile_suffix}{ssl_suffix}.html`

Examples:

- `/var/cache/joomla-maxcache/www.boiraesdeveniments.com/ca/index.html`
- `/var/cache/joomla-maxcache/www.boiraesdeveniments.com/ca/coneix-nos/index.html`
- `/var/cache/joomla-maxcache/www.boiraesdeveniments.com/en/about-us/index-mobile.html`

4. `onAfterRender`
Disable dynamic gzip in-app before writing cache artifacts so the plugin stores the
canonical uncompressed HTML body.

5. `onAfterRespond`
Write the rendered HTML to the deterministic target path and optionally create a
gzip companion file.

6. Purge hooks
Listen to content, category, menu, module, and plugin lifecycle events to invalidate
either the full tree or the affected URLs.

## Server role split

Joomla plugin responsibilities:

- decide eligibility
- write static artifacts
- purge them
- expose enough settings for admins

Server responsibilities:

- resolve the incoming request to the deterministic cache path
- serve the static file before PHP when present
- bypass when cookies/query-string rules say so

The plugin may eventually offer an explicit "apply snippet" action, but it should not
silently rewrite `.htaccess` by default. Many Joomla sites already delegate `.htaccess`
management to tooling such as Akeeba Admin Tools, so automatic writes must remain opt-in.

Recommended admin flow:

- `Save without applying`: persist plugin settings only
- `Preview snippet`: show the exact managed block
- `Apply snippet`: explicit action only, always with confirmation and backup

The plugin should track whether the currently applied managed block hash matches the
newly generated snippet hash, so the admin UI can display:

- snippet not applied
- snippet applied
- applied snippet is outdated

## Repository structure

- `pkg_maxcache/pkg_maxcache.xml`: Joomla package manifest
- `pkg_maxcache/packages/plg_system_maxcache/maxcache.xml`: plugin manifest
- `pkg_maxcache/packages/plg_system_maxcache/src/Extension/Maxcache.php`: plugin runtime
- `updates/pkg_maxcache.xml`: Joomla update feed

## Recommended MVP

Phase 1:

- guest-only caching
- language-aware paths
- URL/menu exclusions
- bypass on session and form cookies
- HTML output only
- manual full purge and targeted purge on article save

Phase 2:

- gzip artifact generation
- mobile variation
- richer purge graph for menus/modules/categories
- generated server config preview in plugin help text

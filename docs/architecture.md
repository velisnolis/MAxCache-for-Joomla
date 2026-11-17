# Architecture

## Why not reuse Joomla core page cache internals

Joomla core page cache stores responses as internal cache entries keyed by a hash
derived from the full URL and optional pagecache plugin inputs.

That works for Joomla-managed response caching, but not for MAx Cache, because
`mod_maxcache` needs a predictable filesystem path for a request.

## Reused from core plugin UX

The plugin keeps the admin ergonomics of `System - Cache`:

- enable / disable as a normal `system` plugin
- `browsercache`
- `exclude_menu_items`
- `exclude`

These three fields map directly to the Joomla administrator experience, and the
exclusions already saved in `System - Cache` are applied as well.

The plugin also imports the existing `pagecache` plugins and honours their exclusion
decisions and cache-key variants. That matters for sites relying on plugins like
RSForm's page cache exclusion listener.

## New MAx Cache fields

- `cache_root`: root directory for generated static files
- `path_mode`: how the plugin builds a filesystem path
- `vary_language`: create separate files per active site language
- `vary_mobile`: create separate files per mobile/desktop split
- `bypass_cookies`: requests with these cookies skip static cache delivery
- `allowed_query_params`: reserved for future query-key support; currently ignored
  because all requests with query parameters bypass static writes and generated
  snippets do not enable query variants
- `tracking_query_params`: optional; server rules serve the canonical artifact when a
  query contains only these names. PHP never writes from such requests
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
- any query parameters present, even if saved in `allowed_query_params`
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

5. `onBeforeRespond`
Recheck guest/message state and the actual HTTP response status before writing.
Reject explicit `private`/`no-store` response directives or HTML containing a
Joomla form token; send privacy/bypass headers and remove both older plain/gzip
artifacts. Otherwise write the rendered HTML to the deterministic target path and
optionally create a gzip companion file. The safety decision and debug headers
must precede sending the response. Joomla's generic browser-cache headers are
added later by `respond()` and do not determine static write eligibility.

6. Purge hooks
Article and menu item save, state change and delete invalidate the affected URLs
(`targeted`) or the whole tree (`full`). Category and module changes have no hook of
their own yet. Saving the plugin's own settings cleans Joomla's caches but does not
purge static files.

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

The plugin never rewrites `.htaccess` on save. Many Joomla sites delegate `.htaccess`
management to tooling such as Akeeba Admin Tools, so writes stay opt-in.

Admin flow:

- `Save without applying`: persist plugin settings only
- `Preview snippet`: show the exact managed block
- `Apply snippet`: explicit action only, always with confirmation and backup

The plugin compares the applied managed block hash with the newly generated snippet
hash, so the admin UI and `maxcache:status` report:

- snippet not applied
- snippet applied
- applied snippet is outdated

## Repository structure

- `pkg_maxcache/pkg_maxcache.xml`: Joomla package manifest
- `pkg_maxcache/packages/plg_system_maxcache/maxcache.xml`: plugin manifest
- `pkg_maxcache/packages/plg_system_maxcache/src/Extension/Maxcache.php`: plugin runtime
- `pkg_maxcache/packages/plg_console_maxcache/`: Joomla console companion
- `updates/pkg_maxcache.xml`: Joomla update feed

## Not implemented

- query-specific cache keys: requests with a query string are never stored
- purge graph for categories, blogs and modules that list a changed article
- cache warm-up beyond the top-level menu items requested by `maxcache:warm`

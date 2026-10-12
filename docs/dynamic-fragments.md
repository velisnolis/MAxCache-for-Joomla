# Dynamic module fragments

MAxCache 0.1.38 can keep a page shell static while rendering selected Joomla
modules after the page is delivered. The static artifact contains only a signed
fragment placeholder. The source module is rendered by Joomla through a
`com_ajax` request with `Cache-Control: no-store`.

Forms whose default action resolves back to that fragment endpoint are
normalized in the browser to submit to the current page. Explicit actions to
other components or external URLs are left unchanged.

This is intended for small user-specific or short-lived areas such as:

- login forms and their CSRF tokens
- account controls that depend on the current session
- modules embedded by Modules Anywhere inside a modal or Conditional Content

It is not a general edge-side include engine. A fragment request still boots
Joomla and should be limited to the modules that genuinely cannot be part of
the static shell.

## Setup

1. Keep the original source module published, but remove its direct menu or
   template assignment when it must never render in the cached shell.
2. Create a `MAxCache Dynamic Fragment` site module.
3. Set **Source module ID** to the numeric Joomla module ID.
4. Choose a trigger:
   - `visible`: load when the placeholder enters the viewport; suitable for a
     modal or offcanvas that starts hidden
   - `immediate`: load as soon as the document is ready
   - `manual`: load only after a `maxcache:fragment-load` event
5. Assign the fragment module where the source previously appeared, or embed
   it with Modules Anywhere.

To load one manual fragment:

```js
document.dispatchEvent(new CustomEvent('maxcache:fragment-load', {
  detail: {id: '123'}
}));
```

An empty ID loads every manual fragment on the page.

## Regular Labs compatibility

MAxCache does not interpret Regular Labs tags. Conditional Content and Modules
Anywhere remain responsible for deciding where a module appears.

The important boundary is the embedded module:

```text
Conditional Content / modal shell
└── Modules Anywhere tag
    └── MAxCache Dynamic Fragment
        └── signed AJAX render of the original login module
```

Replace a `{module ...}` tag that points directly to `mod_login` with a tag
that points to the corresponding MAxCache fragment module. Do not place the
original login module elsewhere on the same cacheable page.

Logged-in requests should still bypass the static page cache through the
configured Joomla session-cookie safeguard. The fragment is an additional
boundary for guest pages; it is not a replacement for session bypass.

## CSRF token in core.js script options

Joomla adds the session form token to the `csrf.token` script option of every
page that loads `core.js`, and `Joomla.request()` sends it in `X-CSRF-Token`
with every non-GET request. A shared static artifact would hand the token of
the guest who generated it to every visitor.

The live response keeps the visitor's own token. The stored artifact gets an
empty `csrf.token` and, when this AJAX plugin is enabled, the
`media/plg_system_maxcache/js/csrf-refresh.js` script. That script wraps
`Joomla.request()`: a non-GET request on a cached page first asks
`com_ajax&plugin=maxcachefragment&maxcache_task=token` for the visitor's
token, then sends the original request with it.

The token is never fetched on page load. Fetching it starts a Joomla session,
and the session cookie makes that visitor's later requests bypass the static
cache, so it only happens when the visitor actually sends a request.

Two limits:

- On a cached page, a non-GET `Joomla.request()` returns a Promise when called
  with `promise: true`, and `undefined` otherwise, instead of the XHR object.
- Scripts that read `Joomla.getOptions('csrf.token')` themselves receive an
  empty string. They should call `window.MAxCache.csrfToken()`, which resolves
  to the visitor's token.

Forms that render a hidden token input are still never stored; use a fragment
for them as described above.

## Safety and validation

The system plugin refuses to write a static artifact when final HTML contains
a Joomla form token and removes any matching older artifact. The console
diagnosis also reports existing token-bearing files as
`csrf-token-in-cache`, including older artifacts that still carry a
`csrf.token` script option.

Before enabling a route in production, verify:

1. A request for the page shell creates an artifact with the fragment
   placeholder but no login form or Joomla token.
2. The fragment endpoint returns `Cache-Control: no-store`.
3. Two independent fragment requests return different Joomla token names.
4. Opening the login modal loads one working form and failed login validation
   remains inside Joomla, not inside static HTML.
5. A logged-in request bypasses the static artifact.

For Modules Anywhere or Conditional Content, also verify the exact modal,
offcanvas, language, and access-level variants used by the site.

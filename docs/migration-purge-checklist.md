# Migration Purge Checklist

Use this checklist during Joomla major migrations and MAx Cache purges. The goal is to avoid treating stale static HTML as proof that Joomla still resolves a route.

## Before purge

1. Confirm the installed MAx Cache version and plugin state from Joomla administrator or the extensions table.
2. Confirm the configured `cache_root`, generated snippet mode, applied snippet status, bypass cookies, and inherited `System - Cache` exclusions.
3. Build a route file with every route that must survive the migration:

```text
/
/ca/
/es/
/en/
/component/users/reset
/component/users/remind
/index.php?option=com_users&view=reset
/index.php?option=com_users&view=remind
```

For sites with custom reset or remind menu entries, include those SEF routes as well, for example:

```text
/cat/canviar-la-contrasenya
/canviar-la-contrasenya
```

## Report

Run a read-only report first:

```bash
scripts/maxcache-route-report.sh \
  --base-url https://example.com \
  --routes routes.txt \
  --cache-root /home/example/public_html/maxcache
```

For Cloudflare-backed sites, run the same report against the origin with `--curl-extra '--resolve host:443:IP'`.

If a purge is part of the migration, make it explicit only after the normal site backup or snapshot is in place:

```bash
scripts/maxcache-route-report.sh \
  --base-url https://example.com \
  --routes routes.txt \
  --cache-root /home/example/public_html/maxcache \
  --purge-cmd '/path/to/reviewed-maxcache-purge-command'
```

## Interpret verdicts

- `live-ok`: route returns a 2xx response and no matching static artifact was found.
- `cache-present-verify-post-purge`: route may be served or masked by existing static HTML; compare with the post-purge row.
- `live-route-missing-after-purge`: purge revealed a route Joomla no longer resolves. Document this as a site route/config issue; do not restore stale cache as a fix.
- `csrf-token-stale`: a CSRF-sensitive form returned the same Joomla token across independent cache-busted fetches. Treat this as a blocking cache bug or exclusion failure.
- `csrf-token-fresh`: token changed and no matching static artifact was found.
- `excluded-ok`: MAx Cache reported a bypass reason such as `excluded-url`, `excluded-menu-item`, `pagecache-plugin`, or `bypass-cookie`, and no static artifact was found.
- `redirect-check-target`: follow the redirect and validate the target route separately.
- `review`: inspect headers, body, logs, and route configuration before drawing conclusions.

## Required closure notes

For each incident, classify the result:

- `bug`: MAx Cache cached or served something it should never cache.
- `tooling gap`: the plugin works, but the operator lacks a repeatable check.
- `capability gap`: useful behavior requires a new plugin feature or Joomla CLI command.
- `site config`: Joomla routes, menus, languages, or extensions are wrong.
- `release task`: manifests, feed, ZIP, tag, or deployment need updating.
- `docs`: the fix is procedural and should become part of the migration runbook.

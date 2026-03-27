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

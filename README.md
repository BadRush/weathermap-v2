# weathermapV2 — Modern Network Weathermap Plugin for LibreNMS

[![CI](https://github.com/BadRush/weathermap-v2/actions/workflows/ci.yml/badge.svg)](https://github.com/BadRush/weathermap-v2/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/badge/PHP-%5E8.4%20%7C%208.5-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![LibreNMS](https://img.shields.io/badge/LibreNMS-26.x%2B%20Plugin%20v2-38bdf8)](https://docs.librenms.org/Extensions/Plugin-System/)
[![License: MIT](https://img.shields.io/badge/License-MIT-22c55e.svg)](./LICENSE)

**weathermapV2** (`badrush/weathermap-v2`) is a modernized **Network Weathermap** plugin built as a native **LibreNMS Plugin v2 Composer package** for **LibreNMS 26.x+** (Laravel 12/13, **PHP 8.4 / 8.5**).

It brings the visual clarity of classic [PHP Network Weathermap](http://www.network-weathermap.com/manual/pages/main.html) (two-way directional bandwidth arrows, curved Catmull-Rom & crisp miter-joined angled multi-VIA paths, customizable utilization color scales & legends, multi-target LAG aggregation, `.conf` migration) into a secure, database-backed **drag-and-drop Vue 3 + SVG editor**, **live auto-refresh viewer & NOC kiosk**, and **server-side GD PNG renderer**.

---

## Why weathermapV2?

| Capability | Legacy v1 Weathermap (`html/plugins`) | LibreNMS Built-in Custom Maps | **weathermapV2 (`badrush/weathermap-v2`)** |
|---|---|---|---|
| PHP 8.4 / 8.5 & LibreNMS 26.x | Broken / unsupported | Supported | **Native Plugin v2 (PHP ^8.4 / 8.5)** |
| Authentication & Per-Map ACL | Unauthenticated by default | Device-based | **Full auth + Spatie RBAC + per-map ACL** |
| Drag & Drop Editor | Legacy `editor.php` | vis.js network | **Vue 3 + SVG (multi-VIA, grid/90° snap, undo/redo)** |
| Angled & Curved Multi-VIA Paths | Text `.conf` only | Single midpoint | **Click-to-route multi-VIA, miter corners, L/Z presets** |
| Server-Side PNG & Thumbnails | External `map-poller.php` cron | None | **PHP GD + FreeType via Laravel Scheduler (5 min)** |
| LAG / Multi-Port Aggregation & RRD | RRD only | Single port DB | **Port DB + RRD (`rrdcached`) + Static + Math sums** |
| Classic `.conf` Migration | Native | Not supported | **Built-in UI & CLI `.conf` importer + report** |

---

## Key Features

- **Native LibreNMS Plugin v2**: Installed via `./lnms plugin:add badrush/weathermap-v2` — registers cleanly under **Overview → Plugins → WeathermapV2** (`weathermapV2`), zero core file edits, zero `vendor:publish` required.
- **Drag & Drop SVG Editor**:
  - Click-to-route multi-VIA link drawing (defaults to crisp `angled` bends with `Shift` 90° lock; switchable to `curved` Catmull-Rom splines anytime).
  - Segment midpoint `+` ghost handles, segment-aware double-click insertion, numbered VIA waypoints, `Delete` removes only the selected waypoint, orthogonal alignment guides (`Align 90°`), and quick **L-Bend** / **Z-Bend** presets.
  - Constant-thickness miter joins across 90° and arbitrary bends with bevel fallback on acute hairpins (pixel-identical between browser SVG and server PNG).
  - Zoom/pan, snap-to-grid, marquee multi-select + group drag, undo/redo (`Ctrl+Z` / `Ctrl+Shift+Z`), optimistic-lock revision conflict protection, searchable LibreNMS Device & Port pickers, Scale & Legend editor, and GD-sanitized background/icon uploads (SVG blocked).
- **Hybrid Live SVG Viewer, NOC Kiosk & Server PNG**:
  - Live SVG viewer with auto-refresh (pauses when tab is hidden), hover tooltips with tabular metrics + LibreNMS 24h `graph.php` mini graph, and click-through links.
  - Bare fullscreen NOC Kiosk mode (`/weathermap/kiosk?maps=1,2&rotate=30`) with auto-rotation and keyboard shortcuts (`Space`, `←`, `→`, `F`).
  - Scheduled 5-minute GD PNG & thumbnail generation stored outside the webroot (`storage/app/weathermap/`) and served behind per-map authorization.
- **Flexible Datasources**:
  - **LibreNMS Port DB** (`ports.ifInOctets_rate` / `ifOutOctets_rate`, `ifSpeed` with per-link manual override, down & stale detection).
  - **RRDtool (`rrdcached`-aware)** via `Symfony\Component\Process\Process` with array args and strict `rrd_dir` path jail.
  - **Static** values and **multi-term aggregation** (sum multiple ports for LAGs, scale factor, negate, swap direction).
- **Classic `.conf` Importer**: Migrate existing Weathermap 0.97/0.98 configs through the Editor modal or `./lnms weathermap:import file.conf` with a transparent `ImportReport`.

---

## Quick Start

> For full step-by-step instructions (bare-metal, GitHub VCS, Docker, SELinux, `rrdcached`, Kiosk TV setup, upgrading, and troubleshooting), see **[docs/INSTALLATION.md](./docs/INSTALLATION.md)**.

Run as the `librenms` user in `/opt/librenms`:

```bash
# Option A: Install from Packagist
./lnms plugin:add badrush/weathermap-v2
./lnms migrate

# Option B: Install directly from GitHub before Packagist indexing
composer config repositories.weathermap-v2 vcs https://github.com/BadRush/weathermap-v2
./lnms plugin:add badrush/weathermap-v2:^1.0
./lnms migrate
```

Then open **Overview → Plugins → WeathermapV2** (or `/weathermap`) in LibreNMS.

---

## Permissions & Access Control

- **Editing Maps**: Users with the `admin` role, the `weathermap.edit` permission (seeded automatically when Spatie Permission is available), or any comma-separated role configured under **Plugin Settings (`/plugin/settings/weathermapV2`) → Additional Editor Roles**.
- **Viewing Maps**: Editors, users with `global-read`, and any additional roles or users granted per map via the **Access** dialog in the editor.
- **Device/Port Pickers**: Strictly filtered through LibreNMS `Device::hasAccess($user)` and `Port::hasAccess($user)`.

---

## CLI Commands

```bash
# Render all PNG-enabled maps (also scheduled automatically every 5 minutes)
./lnms weathermap:render

# Render a specific map by ID or slug
./lnms weathermap:render --map=1 --force

# Import a classic PHP Network Weathermap .conf file
./lnms weathermap:import /path/to/legacy.conf --name="Core Backbone"
```

---

## Local Development & Testing

This repository ships a full Docker Compose LibreNMS 26.x lab (`docker/compose.yaml`) and a lightweight PHP 8.4 + GD + FreeType + `rrdtool` test image (`docker/Dockerfile.php`):

```bash
# Build PHP 8.4 test image
docker build -f docker/Dockerfile.php -t weathermap-php:8.4 .

# PHP quality gates (Composer strict validate, PHPUnit, PHPStan Level 8, Laravel Pint)
docker run --rm -v "$PWD:/workspace" -w /workspace weathermap-php:8.4 \
  sh -c "composer validate --strict && composer install && composer test && composer analyse && composer lint"

# Frontend quality gates (vue-tsc, Vitest PHP/TS geometry parity, Vite dist build)
docker run --rm -v "$PWD:/workspace" -w /workspace node:22-alpine \
  sh -c "npm ci && npm run typecheck && npm run test && npm run build"

# Spin up full LibreNMS 26.x Docker lab + seed demo devices/maps
docker compose -f docker/compose.yaml up -d --wait
docker exec weathermapv2-dev-librenms-1 bash /opt/librenms-weathermap-plugin/docker/bootstrap-dev.sh
```

---

## Documentation

- [Installation & Operations Guide](./docs/INSTALLATION.md)
- [Changelog](./CHANGELOG.md)
- [Contributing Guidelines](./CONTRIBUTING.md)
- [Security Policy](./SECURITY.md)
- [Third-Party Notices & Attributions](./THIRD_PARTY_NOTICES.md)

---

## License

Licensed under the **[MIT License](./LICENSE)** — Copyright © 2026 BadRush.
See [THIRD_PARTY_NOTICES.md](./THIRD_PARTY_NOTICES.md) for attributions covering bundled DejaVu fonts and PHP Network Weathermap 0.98a inspirations.

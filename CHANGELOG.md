# Changelog

All notable changes to **`badrush/weathermap-v2`** (**weathermapV2**) will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-11-21

### Added
- **Centralized Reusable Vendor Icon Folder (`storage/app/weathermap/assets/vendors/`)**:
  - New `vendor` asset kind shared globally across all links and maps with SHA-256 deduplication and ETag caching.
  - Multi-file bulk upload, live filename search filter, and safe deletion (`DELETE /weathermap/api/assets/{asset}` returns `409 Conflict` when referenced in any map).
  - Drop-in server folder indexing via UI **Sync Folder** (`POST /weathermap/api/assets/sync-vendors`) and CLI `./lnms weathermap:sync-assets` (GD PNG sanitization, SVG rejected, idempotent).
- **Link Static Labels & Vendor Badges**:
  - Per-link static description (ISP/vendor name, CID, circuit info) with optional vendor logo, position slider, Offset X/Y, direct canvas drag, colors/font size, optional direction comments (`outComment`/`inComment`), and vendor header inside Viewer hover tooltips.
- **Free-Floating Static Labels (`+ Text` / `annotations[]`)**:
  - Multi-line text boxes (`card` or `plain` style), left/center/right alignment, font size & colors, optional vendor logo (`left` or `top`), optional safe click `infoUrl`, drag with grid snap, and undo/redo support.
  - Full visual parity across SVG Viewer, Kiosk, Editor preview, and server-side GD PNG output.

### Fixed
- **Map Timestamp Timezone (WIB)**: Fixed `SceneBuilder` rendering UTC (`GMT+0000`) timestamps regardless of system timezone due to PHP `new DateTimeImmutable('@' . $now)`. Timestamps now default to `Asia/Jakarta` (`WIB`) across SVG Viewer, Kiosk, and GD PNG renders, and can be customized via Plugin Settings (`/plugin/settings/weathermapV2`) or `WEATHERMAP_TIMEZONE`.
- **Viewer Blank Map on Initial Load & After Clicking Fit**: Added intrinsic SVG `width`/`height` attributes, CSS aspect-ratio fallback, `ResizeObserver`-based `fitZoom` viewport calculation, `margin: auto` safe zoom scrolling (no left-edge clipping or `max-width` squashing), live zoom percentage indicator, and explicit `fitMode="kiosk"` for Kiosk view.
- **CI Style Check**: Fixed import ordering in `LookupController.php` for Laravel Pint.

## [1.0.0] - 2026-11-20

### Added
- **LibreNMS Plugin v2 Package** (`badrush/weathermap-v2`, registered as `weathermapV2`) compatible with LibreNMS 26.x+ (Laravel 12/13, PHP `^8.4` / `8.5`).
- **Hooks & Integration**: `MenuEntryHook`, `SinglePageHook`, `SettingsHook`, automatic Laravel Scheduler registration (`weathermap:render` every 5 minutes), and built-in `dist/` + DejaVu font serving without `vendor:publish`.
- **Drag & Drop Vue 3 + SVG Editor**:
  - Click-to-route multi-VIA link drawing (`angled` default, switchable to `curved` Catmull-Rom splines).
  - Segment midpoint `+` insert handles, segment-aware double-click insertion, numbered VIA waypoints, single-waypoint `Delete`/`Backspace`, orthogonal `Align 90°` snap with cyan alignment guides, and **L-Bend** / **Z-Bend** presets.
  - Constant-thickness miter-join corner geometry with bevel fallback on acute hairpins and corner-safe arrowheads (verified pixel-identical between PHP and TypeScript).
  - Zoom/pan, snap-to-grid, marquee multi-select + group drag, undo/redo history stack, optimistic-lock revision conflict detection, Scale & Legend editor, GD-sanitized raster image uploads (SVG blocked), searchable permission-filtered Device & Port pickers, and per-map ACL manager.
- **Hybrid Live Viewer, NOC Kiosk & GD PNG Renderer**:
  - Live auto-refresh SVG viewer with hover tooltips + LibreNMS `graph.php` 24h mini graph and click-through links.
  - Fullscreen NOC Kiosk mode (`/weathermap/kiosk?maps=...&rotate=...`) with multi-map rotation.
  - PHP 8.4/8.5-clean `GdRenderer` (`\GdImage`, explicit `(int) round()` casts, FreeType support, atomic PNG/thumbnail storage outside webroot).
- **Datasources**: LibreNMS Port DB (`ifInOctets_rate`/`ifOutOctets_rate`, `ifSpeed` + manual override), `rrdcached`-aware RRD (`Symfony\Process` + `rrd_dir` path jail), Static values, and multi-term LAG/math aggregation.
- **Classic `.conf` Importer**: UI modal and `./lnms weathermap:import` CLI command with structured `ImportReport`.
- **Quality & Dev Infrastructure**: PHPUnit 13 unit/feature/golden-PNG tests, PHPStan Level 8, Laravel Pint, Vitest PHP/TS parity suite, Playwright E2E spec, Docker Compose LibreNMS 26.x lab, and GitHub Actions CI/Release workflows.

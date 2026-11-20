# Third-Party Notices & Attributions

`badrush/weathermap-v2` (**weathermapV2**) is licensed under the MIT License (see [LICENSE](./LICENSE)).
It incorporates design concepts and bundled third-party assets under the following permissive licenses:

---

## 1. PHP Network Weathermap 0.98a
- **Author**: Howard Jones
- **Homepage**: http://www.network-weathermap.com/
- **Repository**: https://github.com/howardjones/network-weathermap
- **License**: MIT License
- **Usage**: Portions of the Catmull-Rom spline formulation, directional arrow proportions, classic utilization color scale stops, and `.conf` directive syntax compatibility are inspired by and adapted from PHP Network Weathermap 0.98a.

---

## 2. DejaVu Fonts (`resources/fonts/DejaVuSans.ttf`, `DejaVuSans-Bold.ttf`)
- **Homepage**: https://dejavu-fonts.github.io/
- **License**: Bitstream Vera / Arev / DejaVu Fonts License (see [resources/fonts/LICENSE-DejaVu.txt](./resources/fonts/LICENSE-DejaVu.txt))
- **Usage**: Bundled so server-side PHP GD FreeType PNG rendering and browser SVG `@font-face` rendering share identical font metrics.

---

## 3. LibreNMS Plugin Interfaces (`librenms/plugin-interfaces`)
- **Author**: LibreNMS Project
- **Repository**: https://github.com/librenms/plugin-interfaces
- **License**: BSD Zero Clause License (`0BSD`)
- **Usage**: Runtime dependency providing the LibreNMS Plugin v2 hook interfaces (`MenuEntryHook`, `SinglePageHook`, `SettingsHook`, `PluginManagerInterface`).

---

## 4. Runtime Ecosystem Dependencies
- **Laravel Illuminate components** (`illuminate/*`) — MIT License
- **Symfony Process** (`symfony/process`) — MIT License
- **Vue 3** (`vue`) — MIT License (bundled in pre-built `dist/` assets)

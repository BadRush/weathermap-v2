# Contributing to weathermapV2

Thank you for your interest in contributing to **`badrush/weathermap-v2`**!

## Development Environment

You do not need PHP 8.4 or Node.js installed directly on your host — both toolchains are containerized:

```bash
# 1. Build the PHP 8.4 + GD + FreeType + rrdtool test image
docker build -f docker/Dockerfile.php -t weathermap-php:8.4 .

# 2. Run PHP checks (Composer validate, PHPUnit, PHPStan Level 8, Laravel Pint)
docker run --rm -v "$PWD:/workspace" -w /workspace weathermap-php:8.4 \
  sh -c "composer validate --strict && composer install && composer test && composer analyse && composer lint"

# 3. Run Frontend checks (TypeScript, Vitest geometry parity, Vite production build)
docker run --rm -v "$PWD:/workspace" -w /workspace node:22-alpine \
  sh -c "npm ci && npm run typecheck && npm run test && npm run build"
```

## Coding Standards

- **PHP 8.4+ Strict**: Every PHP file must declare `declare(strict_types=1);`, pass **PHPStan Level 8** with 0 errors, and pass **Laravel Pint** (`composer lint`). Avoid any PHP 8.4/8.5 deprecated constructs (no implicit nullables, no `strftime`, no `imagedestroy`, no `is_resource` on `\GdImage`, explicit `(int) round()` for GD coordinates).
- **PHP ↔ TypeScript Geometry Parity**: Any change to spline sampling, link path construction, miter/bevel corners, arrow proportions, label placement, or scale color resolution **must** be updated in both `src/Geometry/` and `resources/js/shared/geometry.ts` and covered by a shared fixture in `tests/fixtures/geometry/cases.json`.
- **Pre-built Frontend Bundle**: Whenever files under `resources/js/` change, run `npm run build` and commit the updated `dist/` output so `lnms plugin:add` users never need Node.js.
- **Security**: Never introduce shell interpolation (`exec`/`shell_exec`), unsafe URL schemes, unauthenticated routes, or SVG upload passthrough.

## Pull Request Process

1. Fork the repository and create a feature branch from `main` (e.g., `feat/sensor-datasource` or `fix/rrdcached-timeout`).
2. Follow [Conventional Commits](https://www.conventionalcommits.org/) for commit messages (`feat:`, `fix:`, `docs:`, `test:`, `refactor:`).
3. Ensure all PHP and Frontend CI checks pass locally before opening a Pull Request.

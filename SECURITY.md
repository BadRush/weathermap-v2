# Security Policy

## Supported Versions

| Version | Supported |
|---|---|
| `1.x` | Yes |

## Built-in Security Controls

`weathermapV2` was designed from the ground up to address the historical security weaknesses of legacy PHP Network Weathermap installations:

- **Authentication & Authorization**: Every web page, API endpoint, scene payload, PNG image, thumbnail, and uploaded asset is protected behind Laravel's `web` + `auth` middleware and enforced by `WeathermapMapPolicy`.
- **Webroot Isolation**: Rendered PNGs and uploaded images are stored outside the public webroot under `storage/app/weathermap/`.
- **Image Upload Sanitization**: Only PNG, JPEG, and WebP files (≤ 2 MB) are accepted. Uploaded images are re-encoded through PHP GD to strip metadata/embedded payloads. **SVG uploads are strictly rejected** to prevent stored XSS.
- **No Shell Execution Datasources**: Legacy `!external` command targets are permanently disabled. `RrdSource` invokes `rrdtool` via `Symfony\Component\Process\Process` using strict argument arrays (zero shell interpolation) and enforces a `realpath` jail confined to `rrd_dir`.
- **URL Validation**: Custom `infoUrl` values only allow `http://`, `https://`, or safe relative LibreNMS paths (blocking `javascript:` / `data:` URIs).

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities.
Instead, report suspected vulnerabilities privately via GitHub Security Advisories at:

- `https://github.com/BadRush/weathermap-v2/security/advisories/new`

Or email `anamb4drus@gmail.com` with reproduction steps and impact details. We will acknowledge reports within 72 hours.

# Installation & Operations Guide — weathermapV2

This guide covers installing, configuring, upgrading, and operating **`badrush/weathermap-v2`** (**weathermapV2**) on **LibreNMS 26.x+** across bare-metal Linux servers and Docker deployments.

---

## 1. Prerequisites

| Requirement | Minimum / Recommended | Verification Command |
|---|---|---|
| **LibreNMS** | 26.x+ (Laravel 12 or 13, Plugin v2) | `su - librenms -c "/opt/librenms/lnms --version"` |
| **PHP** | `^8.4` (tested on 8.4 & 8.5) | `php -v` |
| **PHP GD + FreeType** | Required for PNG & image uploads | `php -r 'var_dump(gd_info()["FreeType Support"] ?? false);'` |
| **rrdtool** | Optional (required only for RRD targets) | `rrdtool --version` |
| **LibreNMS Scheduler** | Active (`librenms-scheduler.timer` or cron) | `su - librenms -c "/opt/librenms/validate.php"` |

> **No Node.js required on your LibreNMS server:** Pre-built Vue 3 frontend bundles (`dist/`) and DejaVu Sans fonts (`resources/fonts/`) are shipped inside every Composer release tag and served directly by the plugin.

### Installing PHP GD + FreeType (if missing)

- **Ubuntu / Debian**:
  ```bash
  sudo apt-get update && sudo apt-get install -y php8.4-gd rrdtool
  sudo systemctl restart php8.4-fpm
  ```
- **RHEL / Rocky / Alma / Fedora**:
  ```bash
  sudo dnf install -y php-gd rrdtool
  sudo systemctl restart php-fpm
  ```

---

## 2. Bare-Metal Installation (`/opt/librenms`)

Always run Composer and `lnms` commands as the **`librenms`** user (never `root`).

### Method A — Install from Packagist (Recommended)

```bash
sudo su - librenms
cd /opt/librenms

./lnms plugin:add badrush/weathermap-v2
./lnms migrate
php artisan optimize:clear
```

### Method B — Install Directly from GitHub (VCS Repository)

Use this method if you want to install straight from GitHub without waiting for Packagist:

```bash
sudo su - librenms
cd /opt/librenms

composer config repositories.weathermap-v2 vcs https://github.com/BadRush/weathermap-v2
./lnms plugin:add badrush/weathermap-v2:^1.0
./lnms migrate
php artisan optimize:clear
```

### Method C — Install from a Local Clone (Development / Air-Gapped)

```bash
sudo su - librenms
cd /opt/librenms

git clone https://github.com/BadRush/weathermap-v2.git /opt/weathermap-v2
composer config repositories.weathermap-v2 path /opt/weathermap-v2
./lnms plugin:add badrush/weathermap-v2:@dev
./lnms migrate
php artisan optimize:clear
```

### Verify Installation

1. Log in to LibreNMS as an administrator.
2. Open **Overview → Plugins → Plugin Admin** (`/plugin/settings`) and verify **`weathermapV2`** is listed and enabled.
3. Click **Settings** (`/plugin/settings/weathermapV2`) and verify there are no GD/FreeType warnings.
4. Open **Overview → Plugins → WeathermapV2** (or navigate directly to `/weathermap`).

---

## 3. Docker Installation (`librenms/librenms`)

If you run the official `librenms/librenms` Docker image:

1. Disable the legacy broken v1 Weathermap in your container environment:
   ```yaml
   environment:
     LIBRENMS_WEATHERMAP: "false"
   ```
2. Install the v2 plugin inside the running LibreNMS container (or bake it into a custom derived Dockerfile so it persists across image recreations):
   ```dockerfile
   FROM librenms/librenms:latest

   USER librenms
   WORKDIR /opt/librenms
   RUN composer config repositories.weathermap-v2 vcs https://github.com/BadRush/weathermap-v2 \
       && composer require badrush/weathermap-v2:^1.0 --no-interaction \
       && php artisan package:discover --ansi
   USER root
   ```
3. Run database migrations after starting the container:
   ```bash
   docker exec -u librenms <librenms-container> php /opt/librenms/artisan migrate --force
   ```

> **Local Dev Lab**: You can also spin up a self-contained LibreNMS 26.x + MariaDB + Redis + `rrdcached` lab straight from this repository:
> ```bash
> docker compose -f docker/compose.yaml up -d --wait
> docker exec weathermapv2-dev-librenms-1 bash /opt/librenms-weathermap-plugin/docker/bootstrap-dev.sh
> ```
> Then open `http://localhost:8000/weathermap` (`admin` / `LibreNms-WmLab#2026!`).

---

## 4. Post-Installation Configuration

### 4.1 Scheduled PNG Rendering (5-Minute Cycle)

`weathermapV2` automatically registers `weathermap:render` on the LibreNMS Laravel Scheduler (`everyFiveMinutes()->withoutOverlapping()->onOneServer()`). You do **not** need to add a separate cron entry for `map-poller.php`.

Verify the LibreNMS scheduler is active:
```bash
sudo systemctl status librenms-scheduler.timer
# Or test manually as librenms user:
su - librenms -c "/opt/librenms/lnms weathermap:render"
```

Rendered PNGs and thumbnails are written atomically outside the webroot at `/opt/librenms/storage/app/weathermap/maps/`.

### 4.2 Storage Permissions & SELinux

Ensure the `librenms` user (and web server group, if different) can read/write `storage/app/weathermap`:

```bash
sudo mkdir -p /opt/librenms/storage/app/weathermap/{maps,assets/vendors,assets/icons,assets/backgrounds}
sudo chown -R librenms:librenms /opt/librenms/storage/app/weathermap
sudo chmod -R 775 /opt/librenms/storage/app/weathermap
```

### 4.2.1 Centralized Reusable Vendor Logo Folder

To share ISP/vendor logos across multiple links and maps without uploading repeatedly:
- Upload multiple PNG/JPEG/WebP files directly from the Editor (**Vendor Logos & Icons** modal), **or**
- Copy your logo collection via SCP/SFTP into `/opt/librenms/storage/app/weathermap/assets/vendors/` and index them either via the **Sync Folder** button in the UI or via CLI:
  ```bash
  sudo su - librenms
  cd /opt/librenms
  ./lnms weathermap:sync-assets
  ```
- All imported images are sanitized through PHP GD to PNG (max 512 px, ≤ 2 MB) with SHA-256 deduplication; `.svg` files are skipped for security.

On **SELinux-enforcing** systems (RHEL/Rocky/Alma/Fedora):
```bash
sudo semanage fcontext -a -t httpd_sys_rw_content_t '/opt/librenms/storage/app/weathermap(/.*)?'
sudo restorecon -Rv /opt/librenms/storage/app/weathermap
```

### 4.3 `rrdcached` Integration

By default, **Port DB targets** read live rates directly from LibreNMS's `ports` table (`ifInOctets_rate`, `ifOutOctets_rate`, `ifSpeed`) without touching RRD files — ideal for distributed pollers.

When you use **RRD targets**, `weathermapV2` automatically reads LibreNMS's `rrd_dir`, `rrdtool`, and `rrdcached` settings and passes `--daemon <rrdcached>` safely to `rrdtool fetch`. All RRD paths are jailed inside `rrd_dir` (traversal paths containing `..` or leading `/` are blocked).

### 4.4 RBAC, Per-Map ACL & NOC Kiosk Accounts

- **Admins & Editors**:
  - Users with role `admin` or permission `weathermap.edit` (auto-created in Spatie Permission on boot) can create, edit, import, and delete maps.
  - Additional editor roles can be configured at `/plugin/settings/weathermapV2` (`Additional Editor Roles`, comma-separated).
- **Viewers (Per-Map ACL)**:
  - Editors and `global-read` users can view all maps.
  - Standard `user` accounts can only view maps explicitly granted to their role or user ID via the **Access** button inside the map editor.
- **NOC / Wallboard TV Kiosk**:
  1. Create a dedicated low-privilege user (e.g. `noc-kiosk`) with role `global-read` (or grant specific maps via ACL).
  2. Sign in on the wallboard browser with **Remember Me** checked.
  3. Open `/weathermap/kiosk?maps=1,2,3&rotate=30` (press `F` for fullscreen, `Space` to pause, `←`/`→` to cycle maps).

### 4.5 Map Timestamp Timezone (WIB Default)

Map `"Updated: ..."` timestamps default to **`Asia/Jakarta` (`WIB`, UTC+7)** across live SVG views, Kiosk mode, and server-side PNG exports. To customize the timezone:
- Set **Map Timestamp Timezone** in LibreNMS at `/plugin/settings/weathermapV2` (e.g. `Asia/Jakarta`, `Asia/Makassar`, `Asia/Jayapura`, `UTC`), **or**
- Set `WEATHERMAP_TIMEZONE=Asia/Jakarta` in `/opt/librenms/.env`.

---

## 5. Migrating Classic `.conf` Weathermaps

You can migrate existing PHP Network Weathermap 0.97/0.98 `.conf` files either via the **Import .conf** button in the web UI or via CLI:

```bash
sudo su - librenms
cd /opt/librenms
./lnms weathermap:import /path/to/legacy-backbone.conf --name="Core Backbone"
```

What gets converted automatically:
- Global canvas dimensions, background color, title/timestamp/legend positions, custom `SCALE` gradient stops, and `rrd_period`.
- `NODE DEFAULT` / `LINK DEFAULT` inheritance flattening and relative `POSITION othernode dx dy` coordinates.
- `NODES a:offset b:offset`, multiple `VIA` waypoints (`curved` or `angled`), `WIDTH`, `BANDWIDTH` (K/M/G/T), `ARROWSTYLE`, `SPLITPOS`, `BWLABEL`, `BWLABELPOS`, and safe `INFOURL`.
- LibreNMS port RRD targets (`.../port-id<ID>.rrd:INOCTETS:OUTOCTETS`) are automatically converted to live **Port DB** targets (including multi-target LAG sums).
- Legacy unsafe/unsupported directives (`!external`, `snmp:`, `OVERLIBGRAPH`, `BACKGROUND` local disk paths) are safely skipped and listed in the **Import Report**.

---

## 6. Upgrading & Uninstalling

### Upgrading

```bash
sudo su - librenms
cd /opt/librenms

composer update badrush/weathermap-v2
./lnms migrate
php artisan optimize:clear
```

### Uninstalling

```bash
sudo su - librenms
cd /opt/librenms

./lnms plugin:remove badrush/weathermap-v2
php artisan optimize:clear
```

*(Optional)* To remove database tables and stored PNG/asset files permanently:
```bash
php artisan tinker --execute="
Schema::dropIfExists('weathermap_assets');
Schema::dropIfExists('weathermap_map_access');
Schema::dropIfExists('weathermap_map_references');
Schema::dropIfExists('weathermap_maps');
"
rm -rf /opt/librenms/storage/app/weathermap
```

---

## 7. Troubleshooting

| Symptom | Cause | Resolution |
|---|---|---|
| Warning *"PHP GD FreeType support is missing"* | `php-gd` compiled without FreeType | Install distro `php-gd` with FreeType & restart PHP-FPM |
| PNG returns HTTP `503` | `storage/app/weathermap` not writable or `ext-gd` missing | Fix ownership (`chown -R librenms:librenms`) and SELinux context; test `./lnms weathermap:render` |
| Links show gray `N/A` | Port `poll_time` older than `stalenessSeconds` (default 660s) or device not polled | Verify LibreNMS poller is running and `poll_time` updates on `ports` table |
| RRD target returns no data | `rrdcached` flush lag or invalid relative path | Increase `rrdPeriodSeconds` (e.g., `1100`) in map settings and ensure path is relative to `rrd_dir` |
| HTTP `409 Conflict` when saving | Another user saved a newer revision | Click **Reload Latest Map** in the conflict dialog before re-applying changes |
| Plugin routes return `404` | Stale Laravel route cache or plugin disabled | Enable `weathermapV2` in `/plugin/settings` and run `php artisan optimize:clear` |

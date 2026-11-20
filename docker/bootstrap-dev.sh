#!/usr/bin/env bash
set -euo pipefail

cd /opt/librenms

echo "[1/5] Copying local Weathermap plugin into writable path repository..."
rm -rf /tmp/librenms-weathermap-pkg
mkdir -p /tmp/librenms-weathermap-pkg
cp -r /opt/librenms-weathermap-plugin/composer.json \
      /opt/librenms-weathermap-plugin/config \
      /opt/librenms-weathermap-plugin/database \
      /opt/librenms-weathermap-plugin/dist \
      /opt/librenms-weathermap-plugin/lang \
      /opt/librenms-weathermap-plugin/resources \
      /opt/librenms-weathermap-plugin/routes \
      /opt/librenms-weathermap-plugin/src \
      /tmp/librenms-weathermap-pkg/
chown -R librenms:librenms /tmp/librenms-weathermap-pkg

echo "[2/5] Installing badrush/weathermap-v2 via Composer path repository..."
su -s /bin/bash librenms -c '
  cd /opt/librenms
  composer config repositories.weathermap-local '\''{"type":"path","url":"/tmp/librenms-weathermap-pkg","options":{"symlink":false}}'\''
  composer require badrush/weathermap-v2:@dev --no-interaction --no-scripts || composer require badrush/weathermap-v2:@dev --no-interaction
  php artisan package:discover --ansi
'

echo "[3/5] Running LibreNMS database migrations & clearing caches..."
su -s /bin/bash librenms -c '
  cd /opt/librenms
  php artisan migrate --force
  php artisan optimize:clear
'

echo "[4/5] Ensuring demo admin user exists (admin / LibreNms-WmLab#2026!) and completing install..."
su -s /bin/bash librenms -c '
  cd /opt/librenms
  ./lnms user:add admin --password="LibreNms-WmLab#2026!" --role=admin --email=admin@lab.local --full-name="NOC Admin" 2>/dev/null || true
'
sed -i '/^INSTALL=/d' /data/.env /opt/librenms/.env 2>/dev/null || true
su -s /bin/bash librenms -c 'cd /opt/librenms && php artisan config:clear'

echo "[5/5] Seeding demo devices, ports, starter weathermap, and rendering PNG..."
su -s /bin/bash librenms -c 'php /opt/librenms/artisan tinker --execute="
use App\Models\Device;
use App\Models\Port;
use BadRush\Weathermap\Models\WeathermapMap;
use BadRush\Weathermap\Document\MapDocument;

if (Device::query()->where(\"hostname\", \"core-rtr-01.lab.local\")->doesntExist()) {
    \$now = time();
    \$mkDev = function (string \$host, string \$sys, string \$os): int {
        \$d = new Device();
        \$d->hostname = \$host;
        \$d->sysName = \$sys;
        \$d->os = \$os;
        \$d->status = true;
        \$d->status_reason = \"\";
        \$d->type = \"network\";
        \$d->save();
        return (int) \$d->device_id;
    };
    \$d1 = \$mkDev(\"core-rtr-01.lab.local\", \"core-rtr-01\", \"iosxe\");
    \$d2 = \$mkDev(\"core-rtr-02.lab.local\", \"core-rtr-02\", \"iosxe\");
    \$d3 = \$mkDev(\"edge-sw-01.lab.local\", \"edge-sw-01\", \"junos\");

    \$mkPort = function (int \$devId, int \$idx, string \$name, string \$alias, int \$speed, float \$inRate, float \$outRate) use (\$now): int {
        \$p = new Port();
        \$p->device_id = \$devId;
        \$p->ifIndex = \$idx;
        \$p->ifName = \$name;
        \$p->ifDescr = \$name;
        \$p->ifAlias = \$alias;
        \$p->ifSpeed = \$speed;
        \$p->ifInOctets_rate = \$inRate;
        \$p->ifOutOctets_rate = \$outRate;
        \$p->ifOperStatus = \"up\";
        \$p->ifAdminStatus = \"up\";
        \$p->poll_time = \$now;
        \$p->save();
        return (int) \$p->port_id;
    };
    \$p1 = \$mkPort(\$d1, 1, \"TenGig0/0/1\", \"Backbone Primary to Core-02\", 10000000000, 560000000, 810000000);
    \$p2 = \$mkPort(\$d1, 2, \"TenGig0/0/2\", \"Backbone Backup to Core-02\", 10000000000, 180000000, 220000000);
    \$p3 = \$mkPort(\$d1, 3, \"Gig0/1/1\", \"Downlink to Edge-01\", 1000000000, 32000000, 112000000);
    \$p4 = \$mkPort(\$d2, 3, \"Gig0/1/2\", \"Redundant to Edge-01\", 1000000000, 18000000, 48000000);

    if (WeathermapMap::query()->doesntExist()) {
        \$doc = MapDocument::empty(\"Core Backbone Demo\")->toArray();
        \$doc[\"canvas\"][\"width\"] = 960;
        \$doc[\"canvas\"][\"height\"] = 560;
        \$doc[\"legend\"][\"x\"] = 20;
        \$doc[\"legend\"][\"y\"] = 355;
        \$doc[\"nodes\"] = [
            [\"id\" => \"core1\", \"label\" => \"core-rtr-01\", \"x\" => 220, \"y\" => 180, \"iconType\" => \"shape:box\", \"iconWidth\" => 110, \"iconHeight\" => 42, \"deviceId\" => \$d1, \"bgColor\" => \"#1e293b\", \"outlineColor\" => \"#38bdf8\", \"labelOffset\" => \"C\"],
            [\"id\" => \"core2\", \"label\" => \"core-rtr-02\", \"x\" => 740, \"y\" => 180, \"iconType\" => \"shape:box\", \"iconWidth\" => 110, \"iconHeight\" => 42, \"deviceId\" => \$d2, \"bgColor\" => \"#1e293b\", \"outlineColor\" => \"#38bdf8\", \"labelOffset\" => \"C\"],
            [\"id\" => \"edge1\", \"label\" => \"edge-sw-01\", \"x\" => 480, \"y\" => 430, \"iconType\" => \"shape:round\", \"iconWidth\" => 120, \"iconHeight\" => 46, \"deviceId\" => \$d3, \"bgColor\" => \"#1e293b\", \"outlineColor\" => \"#22c55e\", \"labelOffset\" => \"C\"],
        ];
        \$doc[\"links\"] = [
            [\"id\" => \"l-core-primary\", \"a\" => [\"nodeId\" => \"core1\", \"offset\" => \"E\"], \"b\" => [\"nodeId\" => \"core2\", \"offset\" => \"W\"], \"via\" => [[\"x\" => 480, \"y\" => 110]], \"viaStyle\" => \"curved\", \"width\" => 9, \"arrowStyle\" => \"classic\", \"target\" => [\"terms\" => [[\"source\" => \"port\", \"portId\" => \$p1]]]],
            [\"id\" => \"l-core-backup\", \"a\" => [\"nodeId\" => \"core1\", \"offset\" => \"E\"], \"b\" => [\"nodeId\" => \"core2\", \"offset\" => \"W\"], \"via\" => [[\"x\" => 480, \"y\" => 240]], \"viaStyle\" => \"curved\", \"width\" => 7, \"arrowStyle\" => \"compact\", \"target\" => [\"terms\" => [[\"source\" => \"port\", \"portId\" => \$p2]]]],
            [\"id\" => \"l-c1-e1\", \"a\" => [\"nodeId\" => \"core1\", \"offset\" => \"S\"], \"b\" => [\"nodeId\" => \"edge1\", \"offset\" => \"NW\"], \"via\" => [], \"viaStyle\" => \"curved\", \"width\" => 8, \"target\" => [\"terms\" => [[\"source\" => \"port\", \"portId\" => \$p3]]]],
            [\"id\" => \"l-c2-e1\", \"a\" => [\"nodeId\" => \"core2\", \"offset\" => \"S\"], \"b\" => [\"nodeId\" => \"edge1\", \"offset\" => \"NE\"], \"via\" => [], \"viaStyle\" => \"curved\", \"width\" => 8, \"target\" => [\"terms\" => [[\"source\" => \"port\", \"portId\" => \$p4]]]],
        ];
        \$map = WeathermapMap::query()->create([
            \"name\" => \"Core Backbone Demo\",
            \"slug\" => \"core-backbone-demo\",
            \"document\" => \$doc,
            \"schema_version\" => 1,
            \"revision\" => 1,
            \"png_enabled\" => true,
        ]);
        \$map->syncReferences(\$map->toDocument());
    }
}
"'

su -s /bin/bash librenms -c 'cd /opt/librenms && ./lnms weathermap:render'
echo "Bootstrap complete! Open http://localhost:8000/weathermap (login: admin / LibreNms-WmLab#2026!)"

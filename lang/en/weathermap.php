<?php

declare(strict_types=1);

return [
    'title' => 'WeathermapV2',
    'settings_title' => 'WeathermapV2 Settings',
    'editing_map' => 'Edit Map: :name',
    'kiosk_title' => 'WeathermapV2 NOC Kiosk',
    'new_map' => 'New Map',
    'import_conf' => 'Import .conf',
    'kiosk_mode' => 'NOC Kiosk',
    'edit' => 'Edit',
    'view' => 'View',
    'png' => 'PNG',
    'delete' => 'Delete',
    'no_maps' => 'No weathermaps have been created yet.',
    'no_maps_hint' => 'Create your first interactive topology map using the drag-and-drop editor, or import an existing classic .conf file.',
    'revision_conflict' => 'This map was modified by another user since you opened it. Reload the latest version before saving.',
    'freetype_warning' => 'PHP GD FreeType support is missing. Server-side PNG rendering will fall back to basic bitmap fonts; install php-gd with FreeType for best results.',
    'gd_missing' => 'PHP GD extension is missing. Server-side PNG rendering and image uploads are disabled.',
    'default_refresh' => 'Default Viewer Auto-Refresh (seconds)',
    'default_rrd_period' => 'Default RRD Fetch Period (seconds)',
    'editor_roles' => 'Additional Editor Roles (comma-separated)',
    'editor_roles_help' => 'Administrators and users with the "weathermap.edit" permission can always edit maps.',
    'png_rendering' => 'Enable Scheduled PNG Rendering (every 5 minutes)',
    'save_settings' => 'Save Settings',
];

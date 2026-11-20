<div style="max-width: 720px; padding: 16px;">
    <h3>{{ __('weathermap::weathermap.settings_title') }}</h3>

    @if(empty($hasGd))
        <div class="alert alert-danger" role="alert">
            {{ __('weathermap::weathermap.gd_missing') }}
        </div>
    @elseif(empty($hasFreeType))
        <div class="alert alert-warning" role="alert">
            {{ __('weathermap::weathermap.freetype_warning') }}
        </div>
    @endif

    <form method="POST" action="{{ url('plugin/settings/' . \BadRush\Weathermap\Support\LibreNmsBridge::PLUGIN_NAME) }}">
        @csrf
        <input type="hidden" name="plugin_active" value="1">

        <div class="form-group" style="margin-bottom: 14px;">
            <label for="wm-set-refresh">{{ __('weathermap::weathermap.default_refresh') }}</label>
            <input
                id="wm-set-refresh"
                type="number"
                min="10"
                max="3600"
                class="form-control"
                name="settings[default_refresh_seconds]"
                value="{{ (int) ($settings['default_refresh_seconds'] ?? 60) }}"
            >
        </div>

        <div class="form-group" style="margin-bottom: 14px;">
            <label for="wm-set-rrd">{{ __('weathermap::weathermap.default_rrd_period') }}</label>
            <input
                id="wm-set-rrd"
                type="number"
                min="300"
                max="86400"
                class="form-control"
                name="settings[default_rrd_period]"
                value="{{ (int) ($settings['default_rrd_period'] ?? 900) }}"
            >
        </div>

        <div class="form-group" style="margin-bottom: 14px;">
            <label for="wm-set-roles">{{ __('weathermap::weathermap.editor_roles') }}</label>
            <input
                id="wm-set-roles"
                type="text"
                class="form-control"
                name="settings[editor_roles]"
                value="{{ (string) ($settings['editor_roles'] ?? '') }}"
                placeholder="noc-engineer, network-ops"
            >
            <p class="help-block">{{ __('weathermap::weathermap.editor_roles_help') }}</p>
        </div>

        <div class="checkbox" style="margin-bottom: 16px;">
            <label>
                <input
                    type="checkbox"
                    name="settings[png_rendering_enabled]"
                    value="1"
                    @checked(!empty($settings['png_rendering_enabled']))
                >
                {{ __('weathermap::weathermap.png_rendering') }}
            </label>
        </div>

        <button type="submit" class="btn btn-primary">{{ __('weathermap::weathermap.save_settings') }}</button>
    </form>
</div>

<div style="padding: 18px; max-width: 1280px; margin: 0 auto; font-family: ui-sans-serif, system-ui, sans-serif;">
    <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px;">
        <h2 style="margin: 0;">{{ __('weathermap::weathermap.title') }}</h2>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            @if(!empty($maps) && count($maps) > 0)
                <a href="{{ route('weathermap.kiosk') }}" class="btn btn-default" style="padding: 8px 14px; border: 1px solid #334155; border-radius: 6px; text-decoration: none;">
                    {{ __('weathermap::weathermap.kiosk_mode') }}
                </a>
            @endif
            @if(!empty($canEdit))
                <button type="button" id="wm-create-btn" class="btn btn-primary" style="padding: 8px 14px; border-radius: 6px; border: 0; background: #38bdf8; color: #082f49; font-weight: 700; cursor: pointer;">
                    + {{ __('weathermap::weathermap.new_map') }}
                </button>
                <button type="button" id="wm-import-open-btn" class="btn btn-default" style="padding: 8px 14px; border-radius: 6px; border: 1px solid #334155; background: #1e293b; color: #f1f5f9; cursor: pointer;">
                    {{ __('weathermap::weathermap.import_conf') }}
                </button>
            @endif
        </div>
    </div>

    @if(isset($hasFreeType) && !$hasFreeType)
        <div class="alert alert-warning" role="alert" style="padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; border: 1px solid #f59e0b;">
            {{ __('weathermap::weathermap.freetype_warning') }}
        </div>
    @endif

    @if(empty($maps) || count($maps) === 0)
        <div style="padding: 40px 24px; text-align: center; border: 1px dashed #475569; border-radius: 12px;">
            <p style="font-size: 16px; font-weight: 600; margin: 0 0 6px;">{{ __('weathermap::weathermap.no_maps') }}</p>
            <p style="margin: 0; opacity: 0.75;">{{ __('weathermap::weathermap.no_maps_hint') }}</p>
        </div>
    @else
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px;">
            @foreach($maps as $wmMap)
                <div style="border: 1px solid #334155; border-radius: 10px; overflow: hidden; background: #111827; color: #f1f5f9;">
                    <a href="{{ route('weathermap.maps.show', $wmMap) }}" style="display: block; background: #020617; height: 170px; overflow: hidden;">
                        <img
                            src="{{ route('weathermap.maps.thumb', $wmMap) }}"
                            alt="{{ $wmMap->name }}"
                            loading="lazy"
                            style="width: 100%; height: 100%; object-fit: contain;"
                            onerror="this.style.display='none'"
                        >
                    </a>
                    <div style="padding: 12px 14px;">
                        <div style="font-weight: 700; font-size: 15px; margin-bottom: 4px;">{{ $wmMap->name }}</div>
                        <div style="font-size: 12px; color: #94a3b8; margin-bottom: 10px;">
                            Rev {{ $wmMap->revision }}
                            @if($wmMap->last_rendered_at)
                                &middot; PNG {{ $wmMap->last_rendered_at->diffForHumans() }}
                            @endif
                        </div>
                        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                            <a href="{{ route('weathermap.maps.show', $wmMap) }}" style="padding: 6px 10px; border-radius: 6px; background: #38bdf8; color: #082f49; font-weight: 700; text-decoration: none; font-size: 12px;">
                                {{ __('weathermap::weathermap.view') }}
                            </a>
                            <a href="{{ route('weathermap.maps.png', $wmMap) }}" target="_blank" rel="noopener" style="padding: 6px 10px; border-radius: 6px; border: 1px solid #334155; color: #f1f5f9; text-decoration: none; font-size: 12px;">
                                {{ __('weathermap::weathermap.png') }}
                            </a>
                            @if(!empty($canEdit))
                                <a href="{{ route('weathermap.maps.edit', $wmMap) }}" style="padding: 6px 10px; border-radius: 6px; border: 1px solid #334155; color: #f1f5f9; text-decoration: none; font-size: 12px;">
                                    {{ __('weathermap::weathermap.edit') }}
                                </a>
                                <button type="button" data-wm-delete="{{ $wmMap->id }}" style="margin-left: auto; padding: 6px 10px; border-radius: 6px; border: 1px solid rgba(239,68,68,0.5); background: rgba(239,68,68,0.16); color: #fca5a5; cursor: pointer; font-size: 12px;">
                                    {{ __('weathermap::weathermap.delete') }}
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

@if(!empty($canEdit))
<dialog id="wm-quick-import-dialog" style="padding: 20px; border-radius: 10px; border: 1px solid #334155; background: #111827; color: #f1f5f9; max-width: 560px; width: 92vw;">
    <h3 style="margin-top: 0;">{{ __('weathermap::weathermap.import_conf') }}</h3>
    <form id="wm-quick-import-form">
        <div style="margin-bottom: 10px;">
            <label for="wm-qi-name" style="display: block; font-size: 12px; margin-bottom: 4px;">Map Name (optional)</label>
            <input id="wm-qi-name" name="name" type="text" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid #334155; background: #0f172a; color: #f1f5f9;">
        </div>
        <div style="margin-bottom: 10px;">
            <label for="wm-qi-file" style="display: block; font-size: 12px; margin-bottom: 4px;">Classic .conf file</label>
            <input id="wm-qi-file" name="file" type="file" accept=".conf,.txt" style="width: 100%;">
        </div>
        <div style="margin-bottom: 10px;">
            <label for="wm-qi-text" style="display: block; font-size: 12px; margin-bottom: 4px;">Or paste .conf content</label>
            <textarea id="wm-qi-text" name="content" rows="6" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid #334155; background: #0f172a; color: #f1f5f9; font-family: monospace;"></textarea>
        </div>
        <div id="wm-qi-error" style="color: #fca5a5; font-size: 13px; margin-bottom: 10px;"></div>
        <div style="display: flex; justify-content: flex-end; gap: 8px;">
            <button type="button" id="wm-qi-cancel" style="padding: 8px 14px; border-radius: 6px; border: 1px solid #334155; background: #1e293b; color: #f1f5f9; cursor: pointer;">Cancel</button>
            <button type="submit" style="padding: 8px 14px; border-radius: 6px; border: 0; background: #38bdf8; color: #082f49; font-weight: 700; cursor: pointer;">Import</button>
        </div>
    </form>
</dialog>

<script>
(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const createBtn = document.getElementById('wm-create-btn');
    if (createBtn) {
        createBtn.addEventListener('click', async () => {
            const name = window.prompt('Enter new weathermap name:', 'Core Topology');
            if (!name || !name.trim()) return;
            const res = await fetch('/weathermap/api/maps', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ name: name.trim() }),
            });
            if (res.ok) {
                const data = await res.json();
                window.location.assign(`/weathermap/maps/${data.map.id}/edit`);
            } else {
                alert('Failed to create map.');
            }
        });
    }

    document.querySelectorAll('[data-wm-delete]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            if (!window.confirm('Delete this weathermap permanently?')) return;
            const id = btn.getAttribute('data-wm-delete');
            const res = await fetch(`/weathermap/api/maps/${id}`, {
                method: 'DELETE',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            });
            if (res.ok) window.location.reload();
        });
    });

    const dlg = document.getElementById('wm-quick-import-dialog');
    const openImp = document.getElementById('wm-import-open-btn');
    const cancelImp = document.getElementById('wm-qi-cancel');
    const formImp = document.getElementById('wm-quick-import-form');
    if (openImp && dlg) openImp.addEventListener('click', () => dlg.showModal());
    if (cancelImp && dlg) cancelImp.addEventListener('click', () => dlg.close());
    if (formImp) {
        formImp.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(formImp);
            const res = await fetch('/weathermap/api/import', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: fd,
            });
            const data = await res.json();
            if (res.ok) {
                window.location.assign(data.map.editUrl);
            } else {
                document.getElementById('wm-qi-error').textContent = data.message || 'Import failed.';
            }
        });
    }
})();
</script>
@endif

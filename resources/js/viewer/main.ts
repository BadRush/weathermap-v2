import '../shared/theme.css';
import { createApp } from 'vue';
import ViewerApp from './ViewerApp.vue';

const el = document.getElementById('weathermap-viewer-root');
if (el) {
    createApp(ViewerApp, {
        mapId: Number(el.dataset.mapId ?? '0'),
        mapName: el.dataset.mapName ?? 'Weathermap',
        canEdit: el.dataset.canEdit === '1',
    }).mount(el);
}

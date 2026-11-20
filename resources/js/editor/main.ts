import '../shared/theme.css';
import { createApp } from 'vue';
import EditorApp from './EditorApp.vue';

const el = document.getElementById('weathermap-editor-root');
if (el) {
    createApp(EditorApp, {
        mapId: Number(el.dataset.mapId ?? '0'),
    }).mount(el);
}

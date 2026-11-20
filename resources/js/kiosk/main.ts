import '../shared/theme.css';
import { createApp } from 'vue';
import KioskApp from './KioskApp.vue';

const el = document.getElementById('weathermap-kiosk-root');
if (el) {
    const maps = JSON.parse(el.dataset.maps ?? '[]');
    const rotateSeconds = Number(el.dataset.rotateSeconds ?? '30');
    createApp(KioskApp, { maps, rotateSeconds }).mount(el);
}

import { resolve } from 'node:path';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue()],
    base: '/weathermap/assets/',
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
        },
    },
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        manifest: true,
        rollupOptions: {
            input: {
                editor: resolve(__dirname, 'resources/js/editor/main.ts'),
                viewer: resolve(__dirname, 'resources/js/viewer/main.ts'),
                kiosk: resolve(__dirname, 'resources/js/kiosk/main.ts'),
            },
            output: {
                entryFileNames: '[name]-[hash].js',
                chunkFileNames: 'chunks/[name]-[hash].js',
                assetFileNames: 'assets/[name]-[hash][extname]',
            },
        },
    },
});

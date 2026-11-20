import { createApp, h, nextTick } from 'vue';
import { describe, expect, it } from 'vitest';
import SceneSvg from '../../resources/js/components/SceneSvg.vue';
import { computeFitZoom, layoutAnnotation, layoutStaticBadge } from '../../resources/js/shared/layout';
import type { Scene } from '../../resources/js/shared/types';

function makeSampleScene(): Scene {
    return {
        generatedAt: 1_760_000_000,
        timestampText: 'Updated: 2025-10-09 15:53:20 WIB',
        refreshSeconds: 60,
        canvas: {
            width: 1200,
            height: 800,
            bgColor: '#0f172a',
            backgroundAssetId: null,
            backgroundAssetUrl: null,
            title: 'Test Topology',
            titleX: 20,
            titleY: 28,
            titleFontSize: 18,
            titleColor: '#f8fafc',
            showTimestamp: true,
            timestampX: 20,
            timestampY: 50,
            timestampFontSize: 10,
            timestampColor: '#94a3b8',
        },
        nodes: [],
        links: [
            {
                id: 'link-1',
                nodeA: 'r1',
                nodeB: 'r2',
                zOrder: 300,
                width: 8,
                outlineColor: '#0f172a',
                centerline: [
                    { x: 100, y: 200 },
                    { x: 500, y: 200 },
                ],
                outArrow: {
                    polygon: [
                        { x: 100, y: 196 },
                        { x: 300, y: 196 },
                        { x: 300, y: 204 },
                    ],
                    color: '#22c55e',
                    bps: 100_000_000,
                    bpsFormatted: '100Mbps',
                    percent: 10,
                    capacityBps: 1_000_000_000,
                    capacityFormatted: '1Gbps',
                    label: '10%',
                    labelPos: { x: 200, y: 200 },
                    labelTextColor: '#0f172a',
                },
                inArrow: {
                    polygon: [
                        { x: 500, y: 196 },
                        { x: 300, y: 196 },
                        { x: 300, y: 204 },
                    ],
                    color: '#38bdf8',
                    bps: 200_000_000,
                    bpsFormatted: '200Mbps',
                    percent: 20,
                    capacityBps: 1_000_000_000,
                    capacityFormatted: '1Gbps',
                    label: '20%',
                    labelPos: { x: 400, y: 200 },
                    labelTextColor: '#0f172a',
                },
                bwFontSize: 9,
                inComment: 'RX Backup',
                outComment: 'TX Primary',
                inCommentPos: { x: 400, y: 218 },
                outCommentPos: { x: 200, y: 218 },
                staticBadge: {
                    text: 'Telkom Astinet 10G',
                    pos: { x: 300, y: 175 },
                    fontSize: 10,
                    bgColor: '#0f172a',
                    textColor: '#e2e8f0',
                    borderColor: '#334155',
                    iconAssetId: 7,
                    iconAssetUrl: '/weathermap/api/assets/7',
                    iconSize: 16,
                },
                isDown: false,
                isStale: false,
                dataTimestamp: 1_760_000_000,
                primaryPortId: 1,
                primaryDeviceId: 1,
                portLabel: 'core-01 Gi0/1',
                infoUrl: null,
                graphUrl: null,
                error: null,
            },
        ],
        annotations: [
            {
                id: 'ann-1',
                text: 'ISP Upstream\nCID: 998877',
                lines: ['ISP Upstream', 'CID: 998877'],
                x: 80,
                y: 80,
                style: 'card',
                align: 'left',
                fontSize: 12,
                textColor: '#f8fafc',
                bgColor: '#1e293b',
                borderColor: '#475569',
                iconAssetId: 7,
                iconAssetUrl: '/weathermap/api/assets/7',
                iconPosition: 'left',
                iconSize: 24,
                infoUrl: 'https://example.com/isp',
                zOrder: 700,
            },
        ],
        legend: null,
    };
}

describe('Viewer Fit Zoom & Layout Helpers', () => {
    it('scales oversized maps to fit both width and height, and caps small maps at 1.0', () => {
        // 1600x900 map in 836x636 stage -> avail 800x600 -> min(0.5, 0.666, 1) = 0.5
        expect(computeFitZoom(1600, 900, 836, 636, 36)).toBe(0.5);
        // 800x1200 tall map in 1236x636 stage -> avail 1200x600 -> min(1.5, 0.5, 1) = 0.5
        expect(computeFitZoom(800, 1200, 1236, 636, 36)).toBe(0.5);
        // Small 600x400 map in 1920x1080 stage -> capped at 1.0 (never stretched blurry)
        expect(computeFitZoom(600, 400, 1920, 1080, 36)).toBe(1);
        // Zero stage dimensions -> undefined (allows CSS intrinsic fallback)
        expect(computeFitZoom(1200, 800, 0, 0, 36)).toBeUndefined();
    });

    it('computes non-zero bounding boxes for static badges and multi-line annotations', () => {
        const badge = layoutStaticBadge(300, 180, 'Telkom Astinet', 10, true, 16);
        expect(badge.boxW).toBeGreaterThan(80);
        expect(badge.boxH).toBeGreaterThanOrEqual(24);
        expect(badge.hasIcon).toBe(true);
        expect(badge.hasText).toBe(true);

        const annLeft = layoutAnnotation({
            lines: ['Line 1', 'Line 2 longer'],
            style: 'card',
            align: 'left',
            fontSize: 12,
            hasIcon: true,
            iconPosition: 'left',
            iconSize: 24,
        });
        expect(annLeft.boxW).toBeGreaterThan(80);
        expect(annLeft.boxH).toBeGreaterThan(40);

        const annTop = layoutAnnotation({
            lines: ['Centered'],
            style: 'plain',
            align: 'center',
            fontSize: 14,
            hasIcon: true,
            iconPosition: 'top',
            iconSize: 32,
        });
        expect(annTop.textAnchor).toBe('middle');
        expect(annTop.boxH).toBeGreaterThan(50);
    });
});

describe('SceneSvg Sizing & Static Label Rendering', () => {
    it('always sets intrinsic width/height and non-zero styles when zoom is undefined (Fit/initial load)', async () => {
        const container = document.createElement('div');
        document.body.appendChild(container);
        const scene = makeSampleScene();

        const app = createApp({
            render: () => h(SceneSvg, { scene }),
        });
        app.mount(container);
        await nextTick();

        const svg = container.querySelector('svg');
        expect(svg).not.toBeNull();
        expect(svg?.getAttribute('width')).toBe('1200');
        expect(svg?.getAttribute('height')).toBe('800');
        expect(svg?.style.width).toBe('1200px');
        expect(svg?.style.maxWidth).toBe('100%');

        // Verify static badge and multi-line annotation rendered inside SVG
        expect(container.textContent).toContain('Telkom Astinet 10G');
        expect(container.textContent).toContain('ISP Upstream');
        expect(container.textContent).toContain('CID: 998877');
        expect(container.textContent).toContain('TX Primary');
        expect(container.querySelectorAll('image[href="/weathermap/api/assets/7"]').length).toBe(2);

        app.unmount();
        container.remove();
    });

    it('applies scaled dimensions with maxWidth none on explicit zoom and 100% in kiosk mode', async () => {
        const container = document.createElement('div');
        document.body.appendChild(container);
        const scene = makeSampleScene();

        const zoomApp = createApp({
            render: () => h(SceneSvg, { scene, zoom: 1.5 }),
        });
        zoomApp.mount(container);
        await nextTick();

        const zoomedSvg = container.querySelector('svg');
        expect(zoomedSvg?.style.width).toBe('1800px');
        expect(zoomedSvg?.style.height).toBe('1200px');
        expect(zoomedSvg?.style.maxWidth).toBe('none');
        zoomApp.unmount();

        const kioskApp = createApp({
            render: () => h(SceneSvg, { scene, fitMode: 'kiosk' }),
        });
        kioskApp.mount(container);
        await nextTick();

        const kioskSvg = container.querySelector('svg');
        expect(kioskSvg?.style.width).toBe('100%');
        expect(kioskSvg?.style.height).toBe('100%');
        kioskApp.unmount();
        container.remove();
    });
});

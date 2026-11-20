<script setup lang="ts">
import { computed } from 'vue';
import type { Scene, SceneLink, SceneNode } from '../shared/types';
import { polygonToSvgPoints } from '../shared/geometry';

const props = defineProps<{
    scene: Scene;
    zoom?: number;
}>();

const emit = defineEmits<{
    (e: 'hover-link', payload: { link: SceneLink; event: MouseEvent } | null): void;
    (e: 'hover-node', payload: { node: SceneNode; event: MouseEvent } | null): void;
}>();

const viewBox = computed(() => `0 0 ${props.scene.canvas.width} ${props.scene.canvas.height}`);

const legendBox = computed(() => {
    const leg = props.scene.legend;
    if (!leg || leg.stops.length === 0) return null;
    const pad = 10;
    const headerH = 22;
    if (leg.style === 'horizontal') {
        const barW = 240;
        const barH = 16;
        return {
            width: barW + pad * 2,
            height: headerH + barH + 26,
            barW,
            barH,
            pad,
            headerH,
        };
    }
    return {
        width: 154,
        height: headerH + leg.stops.length * 17 + pad,
        rowH: 17,
        swatchW: 22,
        pad,
        headerH,
    };
});

function openUrl(url: string | null): void {
    if (!url) return;
    if (url.startsWith('/') || url.startsWith('http://') || url.startsWith('https://')) {
        window.location.assign(url);
    }
}

function approxLabelWidth(text: string, fontSize: number): number {
    return Math.max(24, text.length * (fontSize * 0.65) + 10);
}
</script>

<template>
    <svg
        :viewBox="viewBox"
        class="wm-scene-svg"
        role="img"
        :aria-label="scene.canvas.title || 'Network Weathermap'"
        preserveAspectRatio="xMidYMid meet"
        :style="zoom ? { width: `${scene.canvas.width * zoom}px`, height: `${scene.canvas.height * zoom}px` } : {}"
    >
        <defs>
            <template v-if="scene.legend">
                <linearGradient
                    v-for="(stop, idx) in scene.legend.stops"
                    :id="`wm-leg-grad-${idx}`"
                    :key="idx"
                    x1="0%"
                    y1="0%"
                    x2="100%"
                    y2="0%"
                >
                    <stop offset="0%" :stop-color="stop.color1" />
                    <stop offset="100%" :stop-color="stop.color2 ?? stop.color1" />
                </linearGradient>
            </template>
        </defs>

        <!-- Canvas background -->
        <rect
            x="0"
            y="0"
            :width="scene.canvas.width"
            :height="scene.canvas.height"
            :fill="scene.canvas.bgColor"
        />
        <image
            v-if="scene.canvas.backgroundAssetUrl"
            :href="scene.canvas.backgroundAssetUrl"
            x="0"
            y="0"
            :width="scene.canvas.width"
            :height="scene.canvas.height"
            preserveAspectRatio="none"
        />

        <!-- Links -->
        <g v-for="link in scene.links" :key="link.id" class="wm-link-group">
            <g
                :style="{ cursor: link.infoUrl ? 'pointer' : 'default' }"
                @mouseenter="emit('hover-link', { link, event: $event })"
                @mousemove="emit('hover-link', { link, event: $event })"
                @mouseleave="emit('hover-link', null)"
                @click="openUrl(link.infoUrl)"
            >
                <polygon
                    v-if="link.outArrow.polygon.length >= 3"
                    :points="polygonToSvgPoints(link.outArrow.polygon)"
                    :fill="link.outArrow.color"
                    :stroke="link.outlineColor"
                    stroke-width="1"
                    stroke-linejoin="round"
                />
                <polygon
                    v-if="link.inArrow.polygon.length >= 3"
                    :points="polygonToSvgPoints(link.inArrow.polygon)"
                    :fill="link.inArrow.color"
                    :stroke="link.outlineColor"
                    stroke-width="1"
                    stroke-linejoin="round"
                />

                <!-- Outbound bandwidth label pill -->
                <g v-if="link.outArrow.label">
                    <rect
                        :x="link.outArrow.labelPos.x - approxLabelWidth(link.outArrow.label, link.bwFontSize) / 2"
                        :y="link.outArrow.labelPos.y - (link.bwFontSize + 6) / 2"
                        :width="approxLabelWidth(link.outArrow.label, link.bwFontSize)"
                        :height="link.bwFontSize + 6"
                        rx="3"
                        :fill="link.outArrow.color"
                        :stroke="link.outlineColor"
                        stroke-width="1"
                    />
                    <text
                        :x="link.outArrow.labelPos.x"
                        :y="link.outArrow.labelPos.y"
                        text-anchor="middle"
                        dominant-baseline="central"
                        :font-size="link.bwFontSize"
                        font-weight="700"
                        :fill="link.outArrow.labelTextColor"
                        font-family="WeathermapSans, sans-serif"
                    >
                        {{ link.outArrow.label }}
                    </text>
                </g>

                <!-- Inbound bandwidth label pill -->
                <g v-if="link.inArrow.label">
                    <rect
                        :x="link.inArrow.labelPos.x - approxLabelWidth(link.inArrow.label, link.bwFontSize) / 2"
                        :y="link.inArrow.labelPos.y - (link.bwFontSize + 6) / 2"
                        :width="approxLabelWidth(link.inArrow.label, link.bwFontSize)"
                        :height="link.bwFontSize + 6"
                        rx="3"
                        :fill="link.inArrow.color"
                        :stroke="link.outlineColor"
                        stroke-width="1"
                    />
                    <text
                        :x="link.inArrow.labelPos.x"
                        :y="link.inArrow.labelPos.y"
                        text-anchor="middle"
                        dominant-baseline="central"
                        :font-size="link.bwFontSize"
                        font-weight="700"
                        :fill="link.inArrow.labelTextColor"
                        font-family="WeathermapSans, sans-serif"
                    >
                        {{ link.inArrow.label }}
                    </text>
                </g>
            </g>
        </g>

        <!-- Nodes -->
        <g
            v-for="node in scene.nodes"
            :key="node.id"
            :style="{ cursor: node.infoUrl ? 'pointer' : 'default' }"
            @mouseenter="emit('hover-node', { node, event: $event })"
            @mousemove="emit('hover-node', { node, event: $event })"
            @mouseleave="emit('hover-node', null)"
            @click="openUrl(node.infoUrl)"
        >
            <image
                v-if="node.iconType === 'asset' && node.iconAssetUrl"
                :href="node.iconAssetUrl"
                :x="node.x - node.iconWidth / 2"
                :y="node.y - node.iconHeight / 2"
                :width="node.iconWidth"
                :height="node.iconHeight"
                preserveAspectRatio="xMidYMid meet"
            />
            <ellipse
                v-else-if="node.iconType === 'shape:round'"
                :cx="node.x"
                :cy="node.y"
                :rx="node.iconWidth / 2"
                :ry="node.iconHeight / 2"
                :fill="node.bgColor"
                :stroke="node.outlineColor"
                stroke-width="1.5"
            />
            <rect
                v-else
                :x="node.x - node.iconWidth / 2"
                :y="node.y - node.iconHeight / 2"
                :width="node.iconWidth"
                :height="node.iconHeight"
                rx="6"
                :fill="node.bgColor"
                :stroke="node.outlineColor"
                stroke-width="1.5"
            />

            <text
                v-if="node.label"
                :x="node.labelPos.x"
                :y="node.labelPos.y"
                text-anchor="middle"
                dominant-baseline="central"
                :font-size="node.labelFontSize"
                font-weight="700"
                :fill="node.labelColor"
                font-family="WeathermapSans, sans-serif"
            >
                {{ node.label }}
            </text>
        </g>

        <!-- Legend -->
        <g v-if="scene.legend && legendBox" :transform="`translate(${scene.legend.x}, ${scene.legend.y})`">
            <rect
                x="0"
                y="0"
                :width="legendBox.width"
                :height="legendBox.height"
                rx="6"
                :fill="scene.legend.bgColor"
                :stroke="scene.legend.outlineColor"
                stroke-width="1"
            />
            <text
                :x="legendBox.pad"
                y="18"
                :font-size="scene.legend.fontSize"
                font-weight="700"
                :fill="scene.legend.textColor"
                font-family="WeathermapSans, sans-serif"
            >
                {{ scene.legend.title }}
            </text>

            <template v-if="scene.legend.style === 'horizontal'">
                <rect
                    v-for="(_stop, idx) in scene.legend.stops"
                    :key="idx"
                    :x="legendBox.pad + (idx * (legendBox.barW ?? 240)) / scene.legend.stops.length"
                    :y="legendBox.headerH + 4"
                    :width="(legendBox.barW ?? 240) / scene.legend.stops.length + 0.5"
                    :height="legendBox.barH ?? 16"
                    :fill="`url(#wm-leg-grad-${idx})`"
                />
                <rect
                    :x="legendBox.pad"
                    :y="legendBox.headerH + 4"
                    :width="legendBox.barW ?? 240"
                    :height="legendBox.barH ?? 16"
                    fill="none"
                    :stroke="scene.legend.outlineColor"
                    stroke-width="1"
                />
                <text
                    :x="legendBox.pad"
                    :y="legendBox.height - 6"
                    :font-size="Math.max(8, scene.legend.fontSize - 1)"
                    :fill="scene.legend.textColor"
                    font-family="WeathermapSans, sans-serif"
                >
                    0%
                </text>
                <text
                    :x="legendBox.pad + (legendBox.barW ?? 240)"
                    :y="legendBox.height - 6"
                    text-anchor="end"
                    :font-size="Math.max(8, scene.legend.fontSize - 1)"
                    :fill="scene.legend.textColor"
                    font-family="WeathermapSans, sans-serif"
                >
                    100%
                </text>
            </template>

            <template v-else>
                <g v-for="(stop, idx) in scene.legend.stops" :key="idx">
                    <rect
                        :x="legendBox.pad"
                        :y="legendBox.headerH + 4 + idx * (legendBox.rowH ?? 17)"
                        :width="legendBox.swatchW ?? 22"
                        height="12"
                        :fill="`url(#wm-leg-grad-${idx})`"
                        :stroke="scene.legend.outlineColor"
                        stroke-width="0.8"
                    />
                    <text
                        :x="legendBox.pad + (legendBox.swatchW ?? 22) + 8"
                        :y="legendBox.headerH + 14 + idx * (legendBox.rowH ?? 17)"
                        :font-size="Math.max(8, scene.legend.fontSize - 1)"
                        :fill="scene.legend.textColor"
                        font-family="WeathermapSans, sans-serif"
                    >
                        {{ stop.label }}
                    </text>
                </g>
            </template>
        </g>

        <!-- Title & Timestamp -->
        <text
            v-if="scene.canvas.title"
            :x="scene.canvas.titleX"
            :y="scene.canvas.titleY"
            :font-size="scene.canvas.titleFontSize"
            font-weight="700"
            :fill="scene.canvas.titleColor"
            font-family="WeathermapSans, sans-serif"
        >
            {{ scene.canvas.title }}
        </text>
        <text
            v-if="scene.canvas.showTimestamp"
            :x="scene.canvas.timestampX"
            :y="scene.canvas.timestampY"
            :font-size="scene.canvas.timestampFontSize"
            :fill="scene.canvas.timestampColor"
            font-family="WeathermapSans, sans-serif"
        >
            {{ scene.timestampText }}
        </text>
    </svg>
</template>

<style scoped>
.wm-scene-svg {
    display: block;
    max-width: 100%;
    height: auto;
    user-select: none;
}
.wm-link-group:hover polygon {
    filter: brightness(1.12);
}
</style>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { apiGet, apiJson, apiUpload } from '../shared/api';
import type { AssetItem, AssetKind } from '../shared/types';

const props = defineProps<{
    open: boolean;
    kind: AssetKind;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'select', asset: AssetItem): void;
}>();

const dialogRef = ref<HTMLDialogElement | null>(null);
const assets = ref<AssetItem[]>([]);
const vendorDir = ref('storage/app/weathermap/assets/vendors');
const searchQuery = ref('');
const loading = ref(false);
const uploading = ref(false);
const syncing = ref(false);
const deletingId = ref<number | null>(null);
const statusMsg = ref<string | null>(null);
const errorMsg = ref<string | null>(null);

const modalTitle = computed(() => {
    if (props.kind === 'background') return 'Background Images';
    if (props.kind === 'vendor') return 'Vendor Logos & Icons (Reusable Folder)';
    return 'Node Icons';
});

const filteredAssets = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return assets.value;
    return assets.value.filter((a) => a.original_name.toLowerCase().includes(q));
});

async function loadAssets(): Promise<void> {
    loading.value = true;
    errorMsg.value = null;
    try {
        const res = await apiGet<{ assets: AssetItem[]; vendorDir?: string }>(
            `/weathermap/api/assets?kind=${encodeURIComponent(props.kind)}`,
        );
        assets.value = res.assets;
        if (res.vendorDir) {
            vendorDir.value = res.vendorDir;
        }
    } catch (e) {
        errorMsg.value = e instanceof Error ? e.message : 'Failed to load assets.';
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            searchQuery.value = '';
            statusMsg.value = null;
            errorMsg.value = null;
            dialogRef.value?.showModal();
            void loadAssets();
        } else {
            dialogRef.value?.close();
        }
    },
);

async function onFileChange(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const files = input.files ? Array.from(input.files) : [];
    if (files.length === 0) return;

    uploading.value = true;
    errorMsg.value = null;
    statusMsg.value = null;

    let uploadedCount = 0;
    const failures: string[] = [];

    for (const file of files) {
        try {
            const fd = new FormData();
            fd.append('kind', props.kind);
            fd.append('file', file);
            const res = await apiUpload<{ asset: AssetItem }>('/weathermap/api/assets', fd);
            assets.value = [res.asset, ...assets.value.filter((a) => a.id !== res.asset.id)];
            uploadedCount += 1;
        } catch (e) {
            const reason = e instanceof Error ? e.message : 'Upload failed';
            failures.push(`${file.name}: ${reason}`);
        }
    }

    if (uploadedCount > 0) {
        statusMsg.value =
            uploadedCount === 1
                ? '1 image uploaded and saved to the library.'
                : `${uploadedCount} images uploaded and saved to the library.`;
    }
    if (failures.length > 0) {
        errorMsg.value = failures.join(' | ');
    }

    uploading.value = false;
    input.value = '';
}

async function onSyncVendorFolder(): Promise<void> {
    if (syncing.value) return;
    syncing.value = true;
    errorMsg.value = null;
    statusMsg.value = null;
    try {
        const res = await apiJson<{
            imported: number;
            skipped: number;
            errors: string[];
            assets: AssetItem[];
        }>('POST', '/weathermap/api/assets/sync-vendors');
        assets.value = res.assets;
        statusMsg.value = `Folder synced: ${res.imported} imported, ${res.skipped} skipped.`;
        if (res.errors.length > 0) {
            errorMsg.value = res.errors.join(' | ');
        }
    } catch (e) {
        errorMsg.value = e instanceof Error ? e.message : 'Failed to sync vendor folder.';
    } finally {
        syncing.value = false;
    }
}

async function onDeleteAsset(asset: AssetItem): Promise<void> {
    if (deletingId.value !== null) return;
    if (!window.confirm(`Delete "${asset.original_name}" from the shared library?`)) {
        return;
    }

    deletingId.value = asset.id;
    errorMsg.value = null;
    statusMsg.value = null;
    try {
        await apiJson<{ deleted: boolean; id: number }>('DELETE', `/weathermap/api/assets/${asset.id}`);
        assets.value = assets.value.filter((a) => a.id !== asset.id);
        statusMsg.value = `Deleted "${asset.original_name}".`;
    } catch (e) {
        errorMsg.value = e instanceof Error ? e.message : 'Failed to delete asset.';
    } finally {
        deletingId.value = null;
    }
}
</script>

<template>
    <dialog ref="dialogRef" class="wm-dialog" aria-labelledby="wm-assets-title" @cancel.prevent="emit('close')">
        <div class="wm-dialog-header">
            <h3 id="wm-assets-title" style="margin: 0; font-size: 16px">
                {{ modalTitle }}
            </h3>
            <button type="button" class="wm-btn" aria-label="Close" @click="emit('close')">&times;</button>
        </div>

        <div class="wm-dialog-body">
            <!-- Centralized Vendor Folder Hint + Sync Button -->
            <div
                v-if="kind === 'vendor'"
                style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 10px; border: 1px solid var(--wm-border); border-radius: 8px; background: rgba(15,23,42,0.75); margin-bottom: 12px"
            >
                <div style="font-size: 12px; color: var(--wm-muted); flex: 1 1 240px">
                    Shared across all links &amp; maps. Upload multiple files below or drop PNG/JPEG/WebP into
                    <code style="color: var(--wm-primary)">{{ vendorDir }}/</code> and click Sync.
                </div>
                <button
                    type="button"
                    class="wm-btn"
                    :disabled="syncing"
                    style="min-height: 32px; font-size: 12px"
                    @click="onSyncVendorFolder"
                >
                    {{ syncing ? 'Syncing...' : 'Sync Folder' }}
                </button>
            </div>

            <div class="wm-row wm-field" style="grid-template-columns: 1.3fr 1fr; align-items: end">
                <div>
                    <label class="wm-label" for="wm-asset-upload">
                        Upload PNG, JPEG, or WebP (multi-select, max 2 MB each; SVG blocked)
                    </label>
                    <input
                        id="wm-asset-upload"
                        type="file"
                        multiple
                        accept="image/png,image/jpeg,image/webp"
                        :disabled="uploading"
                        class="wm-input"
                        @change="onFileChange"
                    />
                </div>
                <div>
                    <label class="wm-label" for="wm-asset-search">Filter Library ({{ filteredAssets.length }}/{{ assets.length }})</label>
                    <input
                        id="wm-asset-search"
                        v-model="searchQuery"
                        type="search"
                        class="wm-input"
                        placeholder="Search by filename..."
                    />
                </div>
            </div>

            <div v-if="statusMsg" style="color: #86efac; font-size: 12px; margin-bottom: 10px" role="status">
                {{ statusMsg }}
            </div>

            <div v-if="errorMsg" style="color: #fca5a5; font-size: 12px; margin-bottom: 10px" role="alert">
                {{ errorMsg }}
            </div>

            <div v-if="loading" class="wm-skeleton" style="height: 120px" />

            <div v-else-if="filteredAssets.length === 0" style="color: var(--wm-muted); font-size: 13px; padding: 16px 0">
                <template v-if="assets.length === 0">No uploaded {{ kind }} images yet.</template>
                <template v-else>No {{ kind }} images matching "{{ searchQuery }}".</template>
            </div>

            <div v-else style="display: grid; grid-template-columns: repeat(auto-fill, minmax(124px, 1fr)); gap: 10px">
                <div
                    v-for="asset in filteredAssets"
                    :key="asset.id"
                    style="position: relative; display: flex; flex-direction: column"
                >
                    <button
                        type="button"
                        class="wm-btn"
                        style="flex-direction: column; padding: 8px; height: auto; width: 100%"
                        @click="emit('select', asset); emit('close')"
                    >
                        <img
                            :src="asset.url"
                            :alt="asset.original_name"
                            style="width: 100%; height: 68px; object-fit: contain; background: #020617; border-radius: 4px; padding: 4px"
                        />
                        <span
                            :title="asset.original_name"
                            style="font-size: 11px; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap"
                        >
                            {{ asset.original_name }}
                        </span>
                    </button>
                    <button
                        type="button"
                        class="wm-btn wm-btn-danger"
                        :disabled="deletingId === asset.id"
                        :aria-label="`Delete ${asset.original_name}`"
                        title="Delete from library (blocked if used in any map)"
                        style="position: absolute; top: 4px; right: 4px; min-height: 22px; width: 22px; padding: 0; font-size: 12px; line-height: 1"
                        @click.stop="onDeleteAsset(asset)"
                    >
                        &times;
                    </button>
                </div>
            </div>
        </div>

        <div class="wm-dialog-footer">
            <button type="button" class="wm-btn" @click="emit('close')">Close</button>
        </div>
    </dialog>
</template>

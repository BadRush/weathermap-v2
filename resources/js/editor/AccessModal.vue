<script setup lang="ts">
import { ref, watch } from 'vue';
import { apiGet, apiJson } from '../shared/api';
import type { AccessRule } from '../shared/types';

const props = defineProps<{
    open: boolean;
    mapId: number;
    rules: AccessRule[];
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'updated', rules: AccessRule[]): void;
}>();

const dialogRef = ref<HTMLDialogElement | null>(null);
const localRules = ref<AccessRule[]>([]);
const availableRoles = ref<string[]>([]);
const availableUsers = ref<Array<{ id: string; label: string }>>([]);
const selectedRole = ref('');
const selectedUser = ref('');
const saving = ref(false);
const errorMsg = ref<string | null>(null);

watch(
    () => props.open,
    async (isOpen) => {
        if (isOpen) {
            localRules.value = props.rules.map((r) => ({ ...r }));
            errorMsg.value = null;
            dialogRef.value?.showModal();
            try {
                const res = await apiGet<{ roles: string[]; users: Array<{ id: string; label: string }> }>(
                    '/weathermap/api/lookup/subjects',
                );
                availableRoles.value = res.roles;
                availableUsers.value = res.users;
            } catch {
                // Non-fatal
            }
        } else {
            dialogRef.value?.close();
        }
    },
);

function addRole(): void {
    if (!selectedRole.value) return;
    if (!localRules.value.some((r) => r.subject_type === 'role' && r.subject_id === selectedRole.value)) {
        localRules.value.push({ subject_type: 'role', subject_id: selectedRole.value });
    }
    selectedRole.value = '';
}

function addUser(): void {
    if (!selectedUser.value) return;
    if (!localRules.value.some((r) => r.subject_type === 'user' && r.subject_id === selectedUser.value)) {
        localRules.value.push({ subject_type: 'user', subject_id: selectedUser.value });
    }
    selectedUser.value = '';
}

function removeRule(idx: number): void {
    localRules.value.splice(idx, 1);
}

async function save(): Promise<void> {
    saving.value = true;
    errorMsg.value = null;
    try {
        const res = await apiJson<{ accessRules: AccessRule[] }>('PUT', `/weathermap/api/maps/${props.mapId}/access`, {
            rules: localRules.value,
        });
        emit('updated', res.accessRules);
        emit('close');
    } catch (e) {
        errorMsg.value = e instanceof Error ? e.message : 'Failed to save access rules.';
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <dialog ref="dialogRef" class="wm-dialog" aria-labelledby="wm-acl-title" @cancel.prevent="emit('close')">
        <div class="wm-dialog-header">
            <h3 id="wm-acl-title" style="margin: 0; font-size: 16px">Map View Access (ACL)</h3>
            <button type="button" class="wm-btn" aria-label="Close" @click="emit('close')">&times;</button>
        </div>

        <div class="wm-dialog-body">
            <p style="margin: 0 0 12px; font-size: 13px; color: var(--wm-muted)">
                Users with <strong>admin</strong>, <strong>global-read</strong>, or <code>weathermap.edit</code> always have view access.
                Grant additional roles or users below.
            </p>

            <div class="wm-row wm-field">
                <div style="display: flex; gap: 6px">
                    <select v-model="selectedRole" class="wm-select" aria-label="Select role">
                        <option value="">-- Add Role --</option>
                        <option v-for="r in availableRoles" :key="r" :value="r">{{ r }}</option>
                    </select>
                    <button type="button" class="wm-btn" @click="addRole">Add</button>
                </div>

                <div style="display: flex; gap: 6px">
                    <select v-model="selectedUser" class="wm-select" aria-label="Select user">
                        <option value="">-- Add User --</option>
                        <option v-for="u in availableUsers" :key="u.id" :value="u.id">{{ u.label }} (#{{ u.id }})</option>
                    </select>
                    <button type="button" class="wm-btn" @click="addUser">Add</button>
                </div>
            </div>

            <div v-if="localRules.length === 0" style="font-size: 13px; color: var(--wm-muted); padding: 8px 0">
                No custom access grants (visible only to admin &amp; global-read).
            </div>

            <ul v-else style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 6px">
                <li
                    v-for="(rule, idx) in localRules"
                    :key="`${rule.subject_type}:${rule.subject_id}`"
                    style="display: flex; align-items: center; justify-content: space-between; padding: 6px 10px; background: var(--wm-surface-elevated); border-radius: 6px"
                >
                    <span>
                        <span class="wm-badge" style="margin-right: 8px">{{ rule.subject_type }}</span>
                        <strong>{{ rule.subject_id }}</strong>
                    </span>
                    <button type="button" class="wm-btn wm-btn-danger" @click="removeRule(idx)">Remove</button>
                </li>
            </ul>

            <div v-if="errorMsg" style="color: #fca5a5; margin-top: 10px; font-size: 13px" role="alert">{{ errorMsg }}</div>
        </div>

        <div class="wm-dialog-footer">
            <button type="button" class="wm-btn" @click="emit('close')">Cancel</button>
            <button type="button" class="wm-btn wm-btn-primary" :disabled="saving" @click="save">
                {{ saving ? 'Saving...' : 'Save Access' }}
            </button>
        </div>
    </dialog>
</template>

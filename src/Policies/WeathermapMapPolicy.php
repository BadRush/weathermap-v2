<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Policies;

use BadRush\Weathermap\Models\WeathermapMap;
use BadRush\Weathermap\Models\WeathermapMapAccess;
use BadRush\Weathermap\Support\LibreNmsBridge;
use Illuminate\Contracts\Auth\Authenticatable;

final class WeathermapMapPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return true;
    }

    public function view(Authenticatable $user, WeathermapMap $map): bool
    {
        if ($this->canEdit($user) || LibreNmsBridge::userHasRole($user, 'global-read')) {
            return true;
        }

        $userId = LibreNmsBridge::userId($user);
        $roles = LibreNmsBridge::userRoles($user);

        return $map->accessRules()
            ->where(function ($query) use ($userId, $roles): void {
                if ($userId !== null) {
                    $query->where(function ($q) use ($userId): void {
                        $q->where('subject_type', WeathermapMapAccess::SUBJECT_USER)
                            ->where('subject_id', (string) $userId);
                    });
                }

                if ($roles !== []) {
                    $query->orWhere(function ($q) use ($roles): void {
                        $q->where('subject_type', WeathermapMapAccess::SUBJECT_ROLE)
                            ->whereIn('subject_id', $roles);
                    });
                }
            })
            ->exists();
    }

    public function create(Authenticatable $user): bool
    {
        return $this->canEdit($user);
    }

    public function update(Authenticatable $user, WeathermapMap $map): bool
    {
        return $this->canEdit($user);
    }

    public function delete(Authenticatable $user, WeathermapMap $map): bool
    {
        return $this->canEdit($user);
    }

    public function manageAccess(Authenticatable $user, WeathermapMap $map): bool
    {
        return $this->canEdit($user);
    }

    public function canEdit(Authenticatable $user): bool
    {
        if (LibreNmsBridge::userHasRole($user, 'admin')) {
            return true;
        }

        if (LibreNmsBridge::userHasPermission($user, LibreNmsBridge::EDIT_PERMISSION)) {
            return true;
        }

        // Optional role allow-list configured on Plugin Settings page
        $settings = LibreNmsBridge::pluginSettings();
        $editorRolesRaw = $settings['editor_roles'] ?? '';
        if (is_string($editorRolesRaw) && trim($editorRolesRaw) !== '') {
            $allowedRoles = array_map('trim', explode(',', $editorRolesRaw));
            foreach ($allowedRoles as $role) {
                if ($role !== '' && LibreNmsBridge::userHasRole($user, $role)) {
                    return true;
                }
            }
        }

        return false;
    }
}

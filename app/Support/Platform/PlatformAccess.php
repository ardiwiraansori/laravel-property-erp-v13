<?php

namespace App\Support\Platform;

use App\Models\User;

/**
 * Platform privileges are independent of tenant roles and permissions.
 * A dedicated platform account may not belong to any tenant, including
 * an inactive tenant. Recheck the database on every protected request.
 */
final class PlatformAccess
{
    public function allows(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        // Do not let a platform identity double as an Owner or member of a tenant.
        if ($user->companies()->exists()) {
            return false;
        }

        return $user->platformAdmin()
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->exists();
    }
}

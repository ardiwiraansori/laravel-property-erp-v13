<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Support\Platform\PlatformMutationAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PlatformUserManagementController extends Controller
{
    public function __construct(private readonly PlatformMutationAudit $audit)
    {
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:255']]);

        DB::transaction(function () use ($request, $user, $data): void {
            $user->update(['name' => $data['name']]);
            $this->audit->record($request, 'platform.user.update', $user->id);
        });

        return response()->json(['data' => ['id' => $user->id, 'name' => $user->name]]);
    }

    public function attach(Request $request, User $user, Company $company): JsonResponse
    {
        DB::transaction(function () use ($request, $user, $company): void {
            if ($user->platformAdmin()->exists()) {
                abort(409, 'Platform administrator accounts cannot join tenant companies.');
            }
            if (! $company->is_active) {
                abort(409, 'Inactive companies cannot accept new memberships.');
            }
            if ($user->companies()->whereKey($company->id)->exists()) {
                abort(409, 'Membership already exists.');
            }

            $user->companies()->attach($company->id);
            // Association is not a grant of a tenant role or permission.
            $this->audit->record($request, 'platform.membership.attach', $user->id, $company->id);
        });

        return response()->json(['message' => 'Membership added without tenant permissions.'], 201);
    }

    public function detach(Request $request, User $user, Company $company): JsonResponse
    {
        DB::transaction(function () use ($request, $user, $company): void {
            if (! $user->companies()->whereKey($company->id)->exists()) {
                abort(404, 'Membership not found.');
            }

            // Do not orphan old tenant roles/permissions. Require a separate,
            // reviewed revocation workflow before detaching privileged users.
            $roleAssignments = DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->where('model_id', $user->id)
                ->where('company_id', $company->id)
                ->exists();
            $permissionAssignments = DB::table('model_has_permissions')
                ->where('model_type', User::class)
                ->where('model_id', $user->id)
                ->where('company_id', $company->id)
                ->exists();
            if ($roleAssignments || $permissionAssignments) {
                abort(409, 'Clear tenant roles and permissions using the reviewed revocation process first.');
            }

            $user->companies()->detach($company->id);
            $this->audit->record($request, 'platform.membership.detach', $user->id, $company->id);
        });

        return response()->json(['message' => 'Membership removed.']);
    }
}

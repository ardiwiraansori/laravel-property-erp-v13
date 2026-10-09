<?php

namespace App\Console\Commands;

use App\Enums\PermissionName;
use App\Models\Company;
use App\Models\PropertyDevelopment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Explicit, non-destructive provisioning for Batch 4B.7 browser E2E tests.
 * Default execution is a read-only preview. Never use this for production tenants.
 */
final class ProvisionE2eTestCompany extends Command
{
    private const DATABASE = 'property_erp_demo_v13';
    private const COMPANY_CODE = 'E2E-TEST';
    private const COMPANY_NAME = 'ERP Tenant E2E Test';
    private const ADMIN_ID = 1;

    protected $signature = 'company:provision-e2e-test
        {--apply : Explicitly create the test company and its scoped access}
        {--confirm= : Required confirmation code (E2E-TEST) when using --apply}';

    protected $description = 'Preview or explicitly provision an isolated view-only E2E test company';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $confirmation = (string) ($this->option('confirm') ?? '');

        if ($apply && $confirmation !== self::COMPANY_CODE) {
            $this->error('Stopped: --apply also requires --confirm=E2E-TEST.');
            return self::FAILURE;
        }

        if (! $apply && $confirmation !== '') {
            $this->error('Stopped: --confirm may only be passed together with --apply.');
            return self::FAILURE;
        }

        try {
            [$admin, $permissions] = $this->preflight();

            $existing = Company::query()->where('code', self::COMPANY_CODE)->first();
            if ($existing !== null) {
                $this->assertExpectedTestCompany($existing, $admin, $permissions);
                $this->info('E2E-TEST is already provisioned exactly as expected (no database changes).');
                $this->line('Company ID: '.$existing->id);
                return self::SUCCESS;
            }

            if (Company::query()->where('name', self::COMPANY_NAME)->exists()) {
                throw new RuntimeException('A company already uses the expected test name; manual review required.');
            }

            $this->info($apply ? 'E2E-TEST EXPLICIT APPLY' : 'E2E-TEST PREVIEW (READ ONLY)');
            $this->line('Database: '.self::DATABASE);
            $this->line('Company: '.self::COMPANY_NAME.' ('.self::COMPANY_CODE.')');
            $this->line('Membership: user ID '.self::ADMIN_ID.' only');
            $this->line('Role assignments: none (especially no Owner)');
            $this->line('Direct permissions, scoped to E2E-TEST:');

            foreach ($this->viewPermissionNames() as $name) {
                $this->line('  - '.$name);
            }

            $this->line('Property developments: none created');
            $this->line('Existing ARVYA data, roles and permissions: unchanged');

            if (! $apply) {
                $this->warn('PREVIEW ONLY: zero database writes. Apply needs explicit --apply --confirm=E2E-TEST.');
                return self::SUCCESS;
            }

            $company = DB::transaction(function () use ($admin, $permissions): Company {
                // Refuse an unexpected concurrent creation; no auto-repair or overwrite.
                if (Company::query()->where('code', self::COMPANY_CODE)->exists()) {
                    throw new RuntimeException('Test company appeared during provisioning; inspect it manually.');
                }

                $company = Company::query()->create([
                    'code' => self::COMPANY_CODE,
                    'name' => self::COMPANY_NAME,
                    'is_active' => true,
                ]);

                $company->users()->attach($admin->getKey());

                // Write the Spatie Teams pivot explicitly, so every direct
                // permission is bound to company B (never to global/null or A).
                $rows = $permissions->map(static fn (Permission $permission): array => [
                    'permission_id' => $permission->getKey(),
                    'model_type' => $admin->getMorphClass(),
                    'model_id' => $admin->getKey(),
                    'company_id' => $company->getKey(),
                ])->all();

                DB::table('model_has_permissions')->insert($rows);

                // Verification inside the transaction rolls everything back
                // if any required isolation invariant is not satisfied.
                $this->assertExpectedTestCompany($company, $admin, $permissions);
                $this->assertArvyaOwner($admin);

                return $company;
            });

            // Refresh Spatie's definitions cache only after successful commit.
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->info('SUCCESS: E2E-TEST provisioned and verified.');
            $this->line('New company ID: '.$company->id);
            $this->warn('Checkpoint 7.5 is NOT complete; browser E2E tests are still required.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Stopped without destructive changes: '.$exception->getMessage());
            return self::FAILURE;
        }
    }

    /** @return array{0: User, 1: \Illuminate\Database\Eloquent\Collection<int, Permission>} */
    private function preflight(): array
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('This command is restricted to APP_ENV=local.');
        }

        // Laravel supports both mysql and mariadb connection drivers.
        // Keep the database-name check strict; do not broaden this command
        // to SQLite, other databases, or non-local environments.
        $driver = DB::connection()->getDriverName();
        $database = DB::connection()->getDatabaseName();

        if (! in_array($driver, ['mysql', 'mariadb'], true) ||
            $database !== self::DATABASE) {
            throw new RuntimeException(
                'Unexpected database/driver (driver: '.$driver.', database: '.$database.'). '
                .'Expected local MySQL/MariaDB connection to '.self::DATABASE.'.'
            );
        }

        if (! config('permission.teams') ||
            config('permission.column_names.team_foreign_key') !== 'company_id') {
            throw new RuntimeException('Spatie Teams must be enabled with company_id as team key.');
        }

        foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $table) {
            if (! Schema::hasColumn($table, 'company_id')) {
                throw new RuntimeException('Missing '.$table.'.company_id; stop and review RBAC schema.');
            }
        }

        if (DB::table('model_has_roles')->whereNull('company_id')->exists() ||
            DB::table('model_has_permissions')->whereNull('company_id')->exists()) {
            throw new RuntimeException('Unscoped RBAC assignments detected; stop and review.');
        }

        $arvya = Company::query()->find(1);
        if ($arvya === null || $arvya->code !== 'ARVYA' ||
            $arvya->name !== 'PT Arvya Labs' || ! $arvya->is_active) {
            throw new RuntimeException('The expected active ARVYA company (ID 1) was not found.');
        }

        $admin = User::query()->find(self::ADMIN_ID);
        if ($admin === null || $admin->email !== 'ardi@example.com' ||
            ! $admin->companies()->whereKey($arvya->id)->exists()) {
            throw new RuntimeException('Administrator ID 1 / email / ARVYA membership do not match the checkpoint.');
        }

        $this->assertArvyaOwner($admin);

        $names = $this->viewPermissionNames();
        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $names)
            ->get(['id', 'name']);

        $foundNames = $permissions->pluck('name')->all();
        sort($foundNames);
        sort($names);
        if ($foundNames !== $names) {
            throw new RuntimeException('One or more existing view permissions are missing; no permissions will be created.');
        }

        return [$admin, $permissions];
    }

    private function assertArvyaOwner(User $admin): void
    {
        $ownerIsScopedToArvya = DB::table('model_has_roles as assignment')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.company_id', 1)
            ->where('assignment.model_type', $admin->getMorphClass())
            ->where('assignment.model_id', $admin->getKey())
            ->where('role.name', 'Owner')
            ->where('role.guard_name', 'web')
            ->exists();

        if (! $ownerIsScopedToArvya) {
            throw new RuntimeException('The expected ARVYA-scoped Owner assignment was not found.');
        }
    }

    /** @param \Illuminate\Database\Eloquent\Collection<int, Permission> $permissions */
    private function assertExpectedTestCompany(Company $company, User $admin, $permissions): void
    {
        if ($company->code !== self::COMPANY_CODE ||
            $company->name !== self::COMPANY_NAME || ! $company->is_active) {
            throw new RuntimeException('Existing E2E-TEST company has unexpected attributes.');
        }

        $members = DB::table('company_user')
            ->where('company_id', $company->id)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        if ($members !== [self::ADMIN_ID]) {
            throw new RuntimeException('Unexpected E2E-TEST membership; no automatic repairs.');
        }

        if (DB::table('model_has_roles')->where('company_id', $company->id)->exists() ||
            DB::table('roles')->where('company_id', $company->id)->exists()) {
            throw new RuntimeException('E2E-TEST must not have any role assignments or company-specific roles.');
        }

        $rows = DB::table('model_has_permissions')
            ->where('company_id', $company->id)
            ->get(['permission_id', 'model_type', 'model_id']);

        if ($rows->count() !== count($this->viewPermissionNames())) {
            throw new RuntimeException('Unexpected E2E-TEST direct permission count.');
        }

        foreach ($rows as $row) {
            if ($row->model_type !== $admin->getMorphClass() ||
                (int) $row->model_id !== self::ADMIN_ID) {
                throw new RuntimeException('E2E-TEST contains permission assignments for an unexpected user.');
            }
        }

        $assignedIds = $rows->pluck('permission_id')->map(static fn ($id): int => (int) $id)->all();
        $expectedIds = $permissions->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        sort($assignedIds);
        sort($expectedIds);
        if ($assignedIds !== $expectedIds) {
            throw new RuntimeException('E2E-TEST permissions do not match the expected view-only set.');
        }

        if (PropertyDevelopment::query()->where('company_id', $company->id)->exists()) {
            throw new RuntimeException('E2E-TEST has property developments; no automatic edits or deletes.');
        }
    }

    /** @return list<string> */
    private function viewPermissionNames(): array
    {
        return [
            PermissionName::DashboardView->value,
            PermissionName::MasterDataView->value,
            PermissionName::MasterDataPropertyDevelopmentsView->value,
        ];
    }
}

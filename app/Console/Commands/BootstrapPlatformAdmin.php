<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BootstrapPlatformAdmin extends Command
{
    protected $signature = 'platform:bootstrap-admin {email : Existing, verified dedicated account email} {--execute : Enable the first platform identity after interactive confirmation}';

    protected $description = 'Preview or explicitly grant the first platform administrator identity.';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->first();

        if ($user === null || $user->email_verified_at === null) {
            $this->error('An existing verified user account is required. No changes made.');
            return self::FAILURE;
        }
        if ($user->companies()->exists() || $user->platformAdmin()->exists()) {
            $this->error('This account is or was associated with a tenant/platform grant. No changes made.');
            return self::FAILURE;
        }
        if (DB::table('platform_admins')->exists()) {
            $this->error('Platform bootstrap has already been used. Review a separate recovery workflow.');
            return self::FAILURE;
        }

        $this->info('Eligible existing verified user ID: '.$user->id);
        $this->line('Tenant memberships: 0. Platform administrator grants: 0.');

        if (! $this->option('execute')) {
            $this->warn('PREVIEW ONLY. No database changes.');
            return self::SUCCESS;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Interactive confirmation is required; --no-interaction is denied.');
            return self::FAILURE;
        }
        if (! $this->confirm('Grant this existing account platform administrator privileges?', false)) {
            $this->warn('Cancelled. No database changes.');
            return self::SUCCESS;
        }

        try {
            DB::transaction(static function () use ($user): void {
                // Serialize bootstrap attempts using the same global anchor row.
                // This avoids granting two first administrators concurrently.
                User::query()->orderBy('id')->lockForUpdate()->firstOrFail();
                $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (DB::table('platform_admins')->exists()
                    || $locked->companies()->exists()
                    || $locked->platformAdmin()->exists()
                    || $locked->email_verified_at === null) {
                    throw new \RuntimeException('Eligibility changed; no grant issued.');
                }

                DB::table('platform_admins')->insert([
                    'user_id' => $locked->id,
                    'is_active' => true,
                    'granted_at' => now(),
                    'granted_by_user_id' => null,
                    'revoked_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('platform_audit_logs')->insert([
                    'event_id' => (string) Str::ulid(),
                    'request_id' => null,
                    'actor_user_id' => null,
                    'action' => 'platform.admin.bootstrap',
                    'target_type' => 'user',
                    'target_id' => $locked->id,
                    'company_id' => null,
                    'outcome' => 'success',
                    'reason' => null,
                    'metadata' => null,
                    'created_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            $this->error('Bootstrap failed: '.$exception->getMessage());
            return self::FAILURE;
        }

        $this->info('Initial platform identity enabled. Audit event saved.');
        return self::SUCCESS;
    }
}

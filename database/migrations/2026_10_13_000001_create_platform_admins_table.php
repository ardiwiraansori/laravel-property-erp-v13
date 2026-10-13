<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admins', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->restrictOnDelete();

            // Fail closed: creating an administrator record never activates access.
            $table->boolean('is_active')->default(false);

            // Null is reserved for a separately reviewed initial provisioning flow.
            $table->foreignId('granted_by_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Do not silently erase administrator identity or revocation history.
        throw new RuntimeException(
            'Platform administrator rollback requires an explicitly reviewed data-preserving plan.'
        );
    }
};

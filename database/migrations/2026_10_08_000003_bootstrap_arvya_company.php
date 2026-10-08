<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Historical bootstrap was already applied to the local development database.
     * New installations must provision companies and memberships explicitly,
     * not through schema migrations with hard-coded user IDs.
     */
    public function up(): void
    {
        // Intentionally empty. Company provisioning belongs to a separate command.
    }

    public function down(): void
    {
        // No schema or seed data to roll back.
    }
};

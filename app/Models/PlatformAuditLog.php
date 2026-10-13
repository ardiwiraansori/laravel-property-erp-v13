<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only audit record. A future platform audit writer will explicitly
 * allowlist actions and non-sensitive metadata before inserting a record.
 * No audit-write route is exposed in Batch 7.6-A.
 */
class PlatformAuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Platform audit events cannot be updated.');
        });

        static::deleting(static function (): void {
            throw new LogicException('Platform audit events cannot be deleted.');
        });
    }
}

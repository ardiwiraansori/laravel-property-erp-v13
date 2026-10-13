<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PlatformMfaCredential extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'last_used_step' => 'integer',
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** No free-form messages, request bodies, URLs, IPs, cookies or credentials. */
final class DiagnosticReport extends Model
{
    protected $guarded = ['*'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }
}

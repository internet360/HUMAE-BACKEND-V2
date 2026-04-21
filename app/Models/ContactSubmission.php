<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ContactSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContactSubmission extends Model
{
    /** @use HasFactory<ContactSubmissionFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'type',
        'name',
        'email',
        'phone',
        'company',
        'subject',
        'message',
        'source',
        'status',
        'assigned_to',
        'internal_notes',
        'responded_at',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}

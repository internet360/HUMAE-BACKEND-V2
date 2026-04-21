<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CompanyMemberRole;
use Database\Factories\CompanyMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property CompanyMemberRole|null $role
 * @property string|null $job_title
 * @property bool $is_primary_contact
 * @property Carbon|null $invited_at
 * @property Carbon|null $accepted_at
 * @property-read User|null $user
 */
class CompanyMember extends Model
{
    /** @use HasFactory<CompanyMemberFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id',
        'user_id',
        'role',
        'job_title',
        'is_primary_contact',
        'invited_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => CompanyMemberRole::class,
            'is_primary_contact' => 'boolean',
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

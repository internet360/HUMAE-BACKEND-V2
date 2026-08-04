<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CompanyMemberRole;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Contracts\CompanyOwned;
use App\Models\Scopes\CompanyOwnedScope;
use App\Support\Tenancy\CompanyTenancy;
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
class CompanyMember extends Model implements CompanyOwned
{
    use BelongsToCompany;

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

    /**
     * This table IS the tenancy map, so writing to it invalidates the answer
     * {@see CompanyTenancy} memoised for that user.
     */
    protected static function booted(): void
    {
        $flush = static function (self $member): void {
            app(CompanyTenancy::class)->flush((int) $member->user_id);
        };

        static::saved($flush);
        static::deleted($flush);
    }

    /**
     * Exempt from the tenancy scope: a membership row that survived the scope
     * already proves the caller may see its company.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)
            ->withoutGlobalScope(CompanyOwnedScope::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

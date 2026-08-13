<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Contracts\CompanyOwned;
use App\Models\Scopes\CompanyOwnedScope;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $legal_name
 * @property string|null $trade_name
 * @property string $slug
 * @property string|null $rfc
 * @property string|null $description
 * @property string|null $website
 * @property string|null $logo_url
 * @property string|null $cover_url
 * @property int|null $industry_id
 * @property int|null $company_size_id
 * @property int|null $ownership_type_id
 * @property int|null $founded_year
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $contact_position
 * @property int|null $country_id
 * @property int|null $state_id
 * @property int|null $city_id
 * @property string|null $address_line
 * @property string|null $postal_code
 * @property string|null $linkedin_url
 * @property string|null $facebook_url
 * @property string|null $instagram_url
 * @property string|null $twitter_url
 * @property string $status
 * @property bool $is_verified
 * @property Carbon|null $verified_at
 * @property int|null $account_manager_id
 * @property string|null $internal_notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Company extends Model implements CompanyOwned
{
    use BelongsToCompany;

    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'legal_name',
        'trade_name',
        'slug',
        'rfc',
        'description',
        'website',
        'logo_url',
        'cover_url',
        'industry_id',
        'company_size_id',
        'ownership_type_id',
        'founded_year',
        'contact_name',
        'contact_email',
        'contact_phone',
        'contact_position',
        'country_id',
        'state_id',
        'city_id',
        'address_line',
        'postal_code',
        'linkedin_url',
        'facebook_url',
        'instagram_url',
        'twitter_url',
        'status',
        'is_verified',
        'verified_at',
        'account_manager_id',
        'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'founded_year' => 'integer',
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * A company is owned by itself: the tenancy scope compares its primary key
     * against the caller's memberships.
     */
    public function companyOwnerKeyName(): string
    {
        return 'id';
    }

    /** @return BelongsTo<Industry, $this> */
    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class);
    }

    /** @return BelongsTo<CompanySize, $this> */
    public function size(): BelongsTo
    {
        return $this->belongsTo(CompanySize::class, 'company_size_id');
    }

    /** @return BelongsTo<OwnershipType, $this> */
    public function ownershipType(): BelongsTo
    {
        return $this->belongsTo(OwnershipType::class);
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return BelongsTo<State, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<User, $this> */
    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }

    /**
     * Exempt from the tenancy scope: reaching a Company instance already
     * required passing it, and HUMAE resolves the members to notify while
     * acting as the candidate (see InterviewService::notifyParties).
     *
     * @return HasMany<CompanyMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(CompanyMember::class)
            ->withoutGlobalScope(CompanyOwnedScope::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_members')
            ->withPivot('role', 'job_title', 'is_primary_contact', 'invited_at', 'accepted_at')
            ->withTimestamps();
    }

    /** @return HasMany<Vacancy, $this> */
    public function vacancies(): HasMany
    {
        return $this->hasMany(Vacancy::class);
    }

    /**
     * Historial de contratos firmados, del más reciente al más antiguo. Una
     * renegociación de términos emite un contrato nuevo, no edita el anterior.
     *
     * @return HasMany<CompanyContract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(CompanyContract::class)->orderByDesc('signed_at');
    }

    /**
     * El contrato MAESTRO vigente: el último firmado que no es adenda de una
     * vacante. Es lo que consulta el gate del frontend para decidir si la
     * empresa puede operar.
     *
     * El `whereNull('vacancy_id')` es la parte que importa. Una adenda de
     * honorarios para una vacante concreta también es un `company_contract`
     * firmado y sellado, pero no rige la relación: si se colara aquí, una
     * empresa sin contrato maestro pero con una adenda parecería habilitada
     * para operar, y la cláusula Primera —la que le prohíbe contactar
     * candidatos por fuera— no estaría firmada por nadie.
     *
     * @return HasOne<CompanyContract, $this>
     */
    public function latestContract(): HasOne
    {
        return $this->hasOne(CompanyContract::class)
            ->whereNull('vacancy_id')
            ->latestOfMany('signed_at');
    }
}

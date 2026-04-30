<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CandidateKind;
use App\Enums\CandidateState;
use Database\Factories\CandidateProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $headline
 * @property string|null $summary
 * @property Carbon|null $birth_date
 * @property string|null $gender
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property CandidateKind|null $candidate_kind
 * @property int|null $years_of_experience
 * @property int|null $salary_currency_id
 * @property string|null $expected_salary_min
 * @property string|null $expected_salary_max
 * @property string|null $expected_salary_period
 * @property string|null $availability
 * @property Carbon|null $available_from
 * @property bool $open_to_relocation
 * @property bool $open_to_remote
 * @property CandidateState|null $state
 * @property Carbon|null $approved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CandidateProfile extends Model
{
    /** @use HasFactory<CandidateProfileFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'headline',
        'summary',
        'birth_date',
        'gender',
        'curp',
        'rfc',
        'contact_email',
        'contact_phone',
        'whatsapp',
        'linkedin_url',
        'portfolio_url',
        'github_url',
        'country_id',
        'state_id',
        'city_id',
        'address_line',
        'postal_code',
        'career_level_id',
        'functional_area_id',
        'position_id',
        'candidate_kind',
        'other_area_text',
        'years_of_experience',
        'salary_currency_id',
        'expected_salary_min',
        'expected_salary_max',
        'expected_salary_period',
        'availability',
        'available_from',
        'open_to_relocation',
        'open_to_remote',
        'state',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'available_from' => 'date',
            'approved_at' => 'datetime',
            'open_to_relocation' => 'boolean',
            'open_to_remote' => 'boolean',
            'years_of_experience' => 'integer',
            'expected_salary_min' => 'decimal:2',
            'expected_salary_max' => 'decimal:2',
            'state' => CandidateState::class,
            'candidate_kind' => CandidateKind::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    /** @return BelongsTo<CareerLevel, $this> */
    public function careerLevel(): BelongsTo
    {
        return $this->belongsTo(CareerLevel::class);
    }

    /** @return BelongsTo<FunctionalArea, $this> */
    public function functionalArea(): BelongsTo
    {
        return $this->belongsTo(FunctionalArea::class);
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** @return BelongsTo<SalaryCurrency, $this> */
    public function salaryCurrency(): BelongsTo
    {
        return $this->belongsTo(SalaryCurrency::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<CandidateExperience, $this> */
    public function experiences(): HasMany
    {
        return $this->hasMany(CandidateExperience::class);
    }

    /**
     * Asignaciones del candidato a vacantes (pipeline). Útil para el matching:
     * permite excluir candidatos que ya estén asignados a una vacante dada.
     *
     * @return HasMany<VacancyAssignment, $this>
     */
    public function assignmentsForVacancy(): HasMany
    {
        return $this->hasMany(VacancyAssignment::class);
    }

    /** @return HasMany<CandidateEducation, $this> */
    public function educations(): HasMany
    {
        return $this->hasMany(CandidateEducation::class);
    }

    /** @return HasMany<CandidateCourse, $this> */
    public function courses(): HasMany
    {
        return $this->hasMany(CandidateCourse::class);
    }

    /** @return HasMany<CandidateCertification, $this> */
    public function certifications(): HasMany
    {
        return $this->hasMany(CandidateCertification::class);
    }

    /** @return HasMany<CandidateReference, $this> */
    public function references(): HasMany
    {
        return $this->hasMany(CandidateReference::class);
    }

    /** @return HasMany<CandidateDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(CandidateDocument::class);
    }

    /** @return BelongsToMany<Skill, $this> */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'candidate_skills')
            ->withPivot('level', 'years_of_experience')
            ->withTimestamps();
    }

    /** @return BelongsToMany<FunctionalArea, $this> */
    public function functionalAreas(): BelongsToMany
    {
        return $this->belongsToMany(FunctionalArea::class, 'candidate_functional_areas')
            ->withPivot('is_primary', 'sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /** @return BelongsToMany<Language, $this> */
    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class, 'candidate_languages')
            ->withPivot('level')
            ->withTimestamps();
    }
}

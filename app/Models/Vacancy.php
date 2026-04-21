<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Priority;
use App\Enums\VacancyState;
use Database\Factories\VacancyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int|null $created_by
 * @property int|null $assigned_recruiter_id
 * @property string $code
 * @property string $title
 * @property string $slug
 * @property string $description
 * @property string|null $responsibilities
 * @property string|null $requirements
 * @property string|null $benefits
 * @property int|null $position_id
 * @property int|null $functional_area_id
 * @property int|null $vacancy_category_id
 * @property int|null $vacancy_type_id
 * @property int|null $vacancy_shift_id
 * @property int|null $career_level_id
 * @property int|null $degree_level_id
 * @property int|null $min_years_of_experience
 * @property int|null $max_years_of_experience
 * @property int $vacancies_count
 * @property int|null $country_id
 * @property int|null $state_id
 * @property int|null $city_id
 * @property bool $is_remote
 * @property bool $is_hybrid
 * @property string|null $work_location
 * @property int|null $salary_currency_id
 * @property string|null $salary_min
 * @property string|null $salary_max
 * @property string|null $salary_period
 * @property bool $salary_is_public
 * @property VacancyState|null $state
 * @property Priority|null $priority
 * @property Carbon|null $published_at
 * @property Carbon|null $closes_at
 * @property Carbon|null $filled_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property string|null $fee_amount
 * @property string|null $fee_percentage
 * @property int|null $sla_days
 * @property string|null $internal_notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company|null $company
 */
class Vacancy extends Model
{
    /** @use HasFactory<VacancyFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'created_by',
        'assigned_recruiter_id',
        'code',
        'title',
        'slug',
        'description',
        'responsibilities',
        'requirements',
        'benefits',
        'position_id',
        'functional_area_id',
        'vacancy_category_id',
        'vacancy_type_id',
        'vacancy_shift_id',
        'career_level_id',
        'degree_level_id',
        'min_years_of_experience',
        'max_years_of_experience',
        'min_age',
        'max_age',
        'gender_preference',
        'vacancies_count',
        'country_id',
        'state_id',
        'city_id',
        'is_remote',
        'is_hybrid',
        'work_location',
        'salary_currency_id',
        'salary_min',
        'salary_max',
        'salary_period',
        'salary_is_public',
        'state',
        'priority',
        'published_at',
        'closes_at',
        'filled_at',
        'cancelled_at',
        'cancel_reason',
        'fee_amount',
        'fee_percentage',
        'sla_days',
        'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'state' => VacancyState::class,
            'priority' => Priority::class,
            'is_remote' => 'boolean',
            'is_hybrid' => 'boolean',
            'salary_is_public' => 'boolean',
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'fee_percentage' => 'decimal:2',
            'published_at' => 'datetime',
            'closes_at' => 'date',
            'filled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function recruiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_recruiter_id');
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** @return BelongsTo<FunctionalArea, $this> */
    public function functionalArea(): BelongsTo
    {
        return $this->belongsTo(FunctionalArea::class);
    }

    /** @return BelongsTo<VacancyCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(VacancyCategory::class);
    }

    /** @return BelongsTo<VacancyType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(VacancyType::class);
    }

    /** @return BelongsTo<VacancyShift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(VacancyShift::class);
    }

    /** @return BelongsTo<CareerLevel, $this> */
    public function careerLevel(): BelongsTo
    {
        return $this->belongsTo(CareerLevel::class);
    }

    /** @return BelongsTo<DegreeLevel, $this> */
    public function degreeLevel(): BelongsTo
    {
        return $this->belongsTo(DegreeLevel::class);
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

    /** @return BelongsTo<SalaryCurrency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(SalaryCurrency::class, 'salary_currency_id');
    }

    /** @return BelongsToMany<Skill, $this> */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'vacancy_skills')
            ->withPivot('required_level', 'is_required')
            ->withTimestamps();
    }

    /** @return BelongsToMany<Language, $this> */
    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class, 'vacancy_languages')
            ->withPivot('required_level', 'is_required')
            ->withTimestamps();
    }

    /** @return BelongsToMany<VacancyTag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(VacancyTag::class, 'vacancy_tag_vacancy')
            ->withTimestamps();
    }

    /** @return HasMany<VacancyAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(VacancyAssignment::class);
    }
}

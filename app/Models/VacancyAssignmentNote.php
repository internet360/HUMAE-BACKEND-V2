<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VacancyAssignmentNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vacancy_assignment_id
 * @property int $author_id
 * @property string $visibility
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $author
 */
class VacancyAssignmentNote extends Model
{
    /** @use HasFactory<VacancyAssignmentNoteFactory> */
    use HasFactory;

    protected $fillable = [
        'vacancy_assignment_id',
        'author_id',
        'visibility',
        'body',
    ];

    /** @return BelongsTo<VacancyAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(VacancyAssignment::class, 'vacancy_assignment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}

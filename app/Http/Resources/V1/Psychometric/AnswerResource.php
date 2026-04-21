<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Psychometric;

use App\Models\PsychometricAnswer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PsychometricAnswer
 */
class AnswerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'question_id' => $this->psychometric_question_id,
            'option_id' => $this->psychometric_question_option_id,
            'value' => $this->value,
            'time_spent_seconds' => $this->time_spent_seconds,
        ];
    }
}

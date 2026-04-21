<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Psychometric;

use App\Models\PsychometricQuestionOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PsychometricQuestionOption
 */
class QuestionOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'value' => $this->value,
            'sort_order' => $this->sort_order,
        ];
    }
}

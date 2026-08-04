<?php

declare(strict_types=1);

namespace App\Http\Requests\Shared;

use App\Enums\TutorialChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Body for POST /me/tutorials/{key}/complete. Ownership is structural: the
 * tutorial state always belongs to the authenticated user and there is no
 * id in the route to spoof.
 */
class CompleteTutorialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', new Enum(TutorialChannel::class)],
        ];
    }
}

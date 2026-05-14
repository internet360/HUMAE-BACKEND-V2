<?php

declare(strict_types=1);

namespace App\Http\Requests\Companies;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class AssignRecruiterRequest extends FormRequest
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
            'recruiter_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null) {
                        return;
                    }
                    $user = User::find((int) $value);
                    if ($user === null || ! $user->hasRole(UserRole::Recruiter->value)) {
                        $fail('El usuario seleccionado no es un reclutador válido.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recruiter_id.exists' => 'El reclutador seleccionado no existe.',
        ];
    }
}

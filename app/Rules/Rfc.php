<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Mexican RFC: persona moral (12 chars) or persona fisica (13 chars).
 *
 * The generic RFCs (XAXX010101000 national, XEXX010101000 foreign) are
 * refused: they stand for "no fiscal data", which contradicts a request that
 * asks for an invoice with the requester's own fiscal data.
 *
 * The value is expected already trimmed and uppercased by the FormRequest.
 */
class Rfc implements ValidationRule
{
    private const PATTERN = '/^[A-ZÑ&]{3,4}(\d{2})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])[A-Z\d]{2}[A\d]$/u';

    private const GENERIC = ['XAXX010101000', 'XEXX010101000'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value, $m) !== 1) {
            $fail('El campo :attribute no tiene un formato de RFC válido.');

            return;
        }

        if (in_array($value, self::GENERIC, true)) {
            $fail('El campo :attribute no puede ser un RFC genérico; captura tus datos fiscales.');

            return;
        }

        // Two-digit year is ambiguous: validate against a leap year so 29 Feb is
        // accepted and 31 Apr / 30 Feb are not.
        if (! checkdate((int) $m[2], (int) $m[3], 2000)) {
            $fail('El campo :attribute contiene una fecha inválida.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Shared;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query params for GET /catalogs/positions.
 *
 * `functional_area_id` is optional: the catalog is small (~70 rows) so the
 * frontend fetches it whole and groups client-side. The filter exists for
 * consumers that only care about one area and don't want the rest.
 */
class PositionCatalogRequest extends FormRequest
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
            'functional_area_id' => ['sometimes', 'nullable', 'integer', 'exists:functional_areas,id'],
        ];
    }
}

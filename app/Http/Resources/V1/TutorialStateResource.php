<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps the row already shaped by `TutorialService::present()`. Not bound to
 * the Eloquent model on purpose: a row can represent a tutorial the user has
 * never interacted with, in which case there is no `UserTutorialState` to
 * wrap, only the resolved defaults.
 */
class TutorialStateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{tutorial_key: string, version: int, should_show: bool, status: string|null, channel: string|null, completed_at: string|null, video_url: string|null} $data */
        $data = $this->resource;

        return $data;
    }
}

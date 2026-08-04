<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Admin;

use App\Models\ContactSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContactSubmission
 */
class ContactSubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company' => $this->company,
            'subject' => $this->subject,
            'message' => $this->message,
            'source' => $this->source,
            'status' => $this->status,
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee !== null ? [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ] : null),
            'internal_notes' => $this->internal_notes,
            'responded_at' => $this->responded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

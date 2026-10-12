<?php

namespace App\Http\Resources\Ticket;

use App\Models\TicketReply;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TicketReply
 */
class ReplyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            // Customers never receive an internal reply at all (see TicketReply::visibleTo), so for
            // them this is always false. Staff use it to style notes differently.
            'is_internal' => $this->is_internal,
            'author' => $this->whenLoaded('author', fn ($user) => new UserSummaryResource($user)),
            'author_is_staff' => $this->whenLoaded('author', fn ($user) => $user->isStaff()),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->created_at,
        ];
    }
}

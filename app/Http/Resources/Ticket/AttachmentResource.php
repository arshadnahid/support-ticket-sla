<?php

namespace App\Http\Resources\Ticket;

use App\Models\TicketAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TicketAttachment
 */
class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // The uuid is the public id; the numeric id, disk and storage path are never exposed.
            'id' => $this->uuid,
            'filename' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'reply_id' => $this->reply_id,
            'uploaded_by' => $this->whenLoaded('uploader', fn ($user) => new UserSummaryResource($user)),
            'created_at' => $this->created_at,
            // Needs the Authorization header like every other endpoint: a plain <a href> will not work.
            'download_url' => route('tickets.attachments.download', [
                'ticket' => $request->route('ticket')->number,
                'attachment' => $this->uuid,
            ]),
        ];
    }
}

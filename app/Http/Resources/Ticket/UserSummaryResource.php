<?php

namespace App\Http\Resources\Ticket;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user as shown on a ticket (creator / assignee). Deliberately small: no role,
 * login time or status. Email is only shown to staff, so customers never see an
 * agent's address.
 */
class UserSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->when($request->user()?->isStaff(), $this->email),
        ];
    }
}

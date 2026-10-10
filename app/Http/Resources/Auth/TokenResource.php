<?php

namespace App\Http\Resources\Auth;

use App\DTOs\Auth\AuthTokenDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property AuthTokenDTO $resource
 */
class TokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'access_token' => $this->resource->accessToken,
            'token_type' => 'bearer',
            'expires_in' => $this->resource->expiresIn,
            'user' => new UserResource($this->resource->user),
        ];
    }
}

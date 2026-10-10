<?php

namespace App\DTOs\Auth;

use App\Models\User;

class AuthTokenDTO
{
    public function __construct(
        public readonly string $accessToken,
        public readonly int $expiresIn,
        public readonly User $user
    ) {}
}

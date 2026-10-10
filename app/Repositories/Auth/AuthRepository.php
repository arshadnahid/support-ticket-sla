<?php

namespace App\Repositories\Auth;

use App\DTOs\Auth\LoginCredentialDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Enums\UserRole;
use App\Models\User;
use App\Repositories\Auth\AuthInterfaces\AuthInterface;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\JWTGuard;

class AuthRepository implements AuthInterface
{
    public function validateCredentials(LoginCredentialDTO $loginCredentialDTO): ?User
    {
        $guard = $this->guard();

        if (! $guard->validate($loginCredentialDTO->toArray())) {
            return null;
        }

        /** @var User */
        return $guard->getLastAttempted();
    }

    public function issueToken(User $user): string
    {
        return $this->guard()->login($user);
    }

    public function createUser(RegisterDTO $registerDTO, UserRole $role): User
    {
        return User::create($registerDTO->toArray() + ['role' => $role]);
    }

    public function recordLogin(User $user): void
    {
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
    }

    public function currentUser(): ?User
    {
        return $this->guard()->user();
    }

    public function refreshToken(): string
    {
        return $this->guard()->refresh();
    }

    public function userForToken(string $token): ?User
    {
        return User::find($this->guard()->setToken($token)->getPayload()->get('sub'));
    }

    public function logout(): void
    {
        $this->guard()->logout();
    }

    public function tokenTtl(): int
    {
        return $this->guard()->factory()->getTTL() * 60;
    }

    private function guard(): JWTGuard
    {
        return Auth::guard('api');
    }
}

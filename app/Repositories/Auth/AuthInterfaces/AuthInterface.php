<?php

namespace App\Repositories\Auth\AuthInterfaces;

use App\DTOs\Auth\LoginCredentialDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Enums\UserRole;
use App\Models\User;

interface AuthInterface
{
    /**
     * Check credentials without issuing a token. Returns the user, or null when invalid.
     */
    public function validateCredentials(LoginCredentialDTO $loginCredentialDTO): ?User;

    /**
     * Issue a JWT for the given user.
     */
    public function issueToken(User $user): string;

    public function createUser(RegisterDTO $registerDTO, UserRole $role): User;

    public function recordLogin(User $user): void;

    public function currentUser(): ?User;

    /**
     * Invalidate the current token and issue a new one. Works for tokens that are
     * expired but still inside the refresh window (jwt.refresh_ttl).
     */
    public function refreshToken(): string;

    /**
     * Resolve the user a token was issued to.
     */
    public function userForToken(string $token): ?User;

    /**
     * Invalidate the current token.
     */
    public function logout(): void;

    /**
     * Token lifetime in seconds.
     */
    public function tokenTtl(): int;
}

<?php

namespace App\Services\Auth;

use App\DTOs\Auth\AuthTokenDTO;
use App\DTOs\Auth\LoginCredentialDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Enums\UserRole;
use App\Exceptions\AccountInactiveException;
use App\Exceptions\InvalidCredentialsException;
use App\Models\User;
use App\Repositories\Auth\AuthInterfaces\AuthInterface;

class AuthService
{
    public function __construct(
        private readonly AuthInterface $auth
    ) {}

    /**
     * @throws InvalidCredentialsException
     * @throws AccountInactiveException
     */
    public function login(LoginCredentialDTO $loginCredentialDTO): AuthTokenDTO
    {
        $user = $this->auth->validateCredentials($loginCredentialDTO)
            ?? throw new InvalidCredentialsException();

        // Checked before a token is issued, so deactivated users never get one.
        if (! $user->is_active) {
            throw new AccountInactiveException();
        }

        $this->auth->recordLogin($user);

        return $this->tokenFor($user, $this->auth->issueToken($user));
    }

    /**
     * Self-registration always creates a customer; staff accounts are created by admins.
     */
    public function register(RegisterDTO $registerDTO): AuthTokenDTO
    {
        $user = $this->auth->createUser($registerDTO, UserRole::Customer);

        return $this->tokenFor($user, $this->auth->issueToken($user));
    }

    public function me(): User
    {
        return $this->auth->currentUser();
    }

    /**
     * @throws AccountInactiveException
     */
    public function refresh(): AuthTokenDTO
    {
        $token = $this->auth->refreshToken();
        $user = $this->auth->userForToken($token);

        if (! $user?->is_active) {
            $this->auth->logout();

            throw new AccountInactiveException();
        }

        return $this->tokenFor($user, $token);
    }

    public function logout(): void
    {
        $this->auth->logout();
    }

    private function tokenFor(User $user, string $token): AuthTokenDTO
    {
        return new AuthTokenDTO($token, $this->auth->tokenTtl(), $user);
    }
}

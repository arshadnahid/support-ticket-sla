<?php

namespace App\Http\Controllers\Api\v1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Resources\Auth\TokenResource;
use App\Http\Resources\Auth\UserResource;
use App\Services\Auth\AuthService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly AuthService $authService) {}

    /**
     * Register a new customer account and return a JWT.
     */
    #[OA\Post(
        path: '/api/auth/register',
        operationId: 'authRegister',
        description: 'Self-registration always creates a **customer**. Admin and agent accounts are created by administrators.',
        summary: 'Register a customer account',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password', 'password_confirmation'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Jane Customer'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'jane@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, example: 'secret123'),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'secret123'),
                ],
            ),
        ),
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Account created',
                content: new OA\JsonContent(
                    allOf: [
                        new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                        new OA\Schema(properties: [
                            new OA\Property(property: 'data', ref: '#/components/schemas/Token'),
                            new OA\Property(property: 'message', type: 'string', example: 'Registration successful'),
                        ]),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
            new OA\Response(ref: '#/components/responses/TooManyRequests', response: 429),
        ],
    )]
    public function register(RegisterRequest $request): JsonResponse
    {
        $token = $this->authService->register($request->toDTO());

        return $this->sendCreatedResponse('Registration successful', new TokenResource($token));
    }

    /**
     * Get a JWT via given credentials.
     */
    #[OA\Post(
        path: '/api/auth/login',
        operationId: 'authLogin',
        summary: 'Log in and obtain a JWT',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'nahid@gmail.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: '12345678'),
                ],
            ),
        ),
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Login successful',
                content: new OA\JsonContent(
                    allOf: [
                        new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                        new OA\Schema(properties: [
                            new OA\Property(property: 'data', ref: '#/components/schemas/Token'),
                            new OA\Property(property: 'message', type: 'string', example: 'Login successful'),
                        ]),
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Invalid email or password',
                content: new OA\JsonContent(
                    allOf: [new OA\Schema(ref: '#/components/schemas/ApiResponse')],
                    example: ['data' => null, 'message' => 'Invalid email or password.', 'errors' => [], 'success' => false, 'status' => 'error'],
                ),
            ),
            new OA\Response(ref: '#/components/responses/AccountInactive', response: 403),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
            new OA\Response(ref: '#/components/responses/TooManyRequests', response: 429),
        ],
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $token = $this->authService->login($request->toDTO());

        return $this->sendResponse('Login successful', new TokenResource($token));
    }

    /**
     * Get the authenticated User.
     */
    #[OA\Get(
        path: '/api/auth/me',
        operationId: 'authMe',
        summary: 'Get the authenticated user',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Authenticated user',
                content: new OA\JsonContent(
                    allOf: [
                        new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                        new OA\Schema(properties: [
                            new OA\Property(property: 'data', properties: [
                                new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                            ], type: 'object'),
                        ]),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AccountInactive', response: 403),
        ],
    )]
    public function me(): JsonResponse
    {
        return $this->sendResponse(null, ['user' => new UserResource($this->authService->me())]);
    }

    /**
     * Log the user out (Invalidate the token).
     */
    #[OA\Post(
        path: '/api/auth/logout',
        operationId: 'authLogout',
        summary: 'Log out (invalidate the current token)',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successfully logged out',
                content: new OA\JsonContent(
                    allOf: [new OA\Schema(ref: '#/components/schemas/ApiResponse')],
                    example: ['data' => null, 'message' => 'Successfully logged out', 'errors' => [], 'success' => true, 'status' => 'success'],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AccountInactive', response: 403),
        ],
    )]
    public function logout(): JsonResponse
    {
        $this->authService->logout();

        return $this->sendResponse('Successfully logged out', null);
    }

    /**
     * Refresh a token.
     */
    #[OA\Post(
        path: '/api/auth/refresh',
        operationId: 'authRefresh',
        description: 'Accepts a valid token, or an expired one still inside the refresh window (JWT_REFRESH_TTL). The old token is blacklisted.',
        summary: 'Refresh the current token',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Token refreshed',
                content: new OA\JsonContent(
                    allOf: [
                        new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                        new OA\Schema(properties: [
                            new OA\Property(property: 'data', ref: '#/components/schemas/Token'),
                            new OA\Property(property: 'message', type: 'string', example: 'Token refreshed'),
                        ]),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AccountInactive', response: 403),
            new OA\Response(ref: '#/components/responses/TooManyRequests', response: 429),
        ],
    )]
    public function refresh(): JsonResponse
    {
        return $this->sendResponse('Token refreshed', new TokenResource($this->authService->refresh()));
    }
}

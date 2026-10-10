<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Global OpenAPI definitions (info, servers, security, shared schemas).
 * Run `php artisan l5-swagger:generate` after changing any attribute.
 */
#[OA\Info(
    version: '1.0.0',
    title: 'Support Ticket SLA API',
    description: 'REST API for the Support Ticket SLA system. Authenticated endpoints expect a JWT in the `Authorization: Bearer <token>` header.',
)]
#[OA\Server(url: L5_SWAGGER_CONST_HOST, description: 'API server')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: 'JWT access token returned by `POST /auth/login`.',
)]
#[OA\Tag(name: 'Auth', description: 'Authentication & token management')]
#[OA\Schema(
    schema: 'User',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Nahid'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'nahid@gmail.com'),
        new OA\Property(property: 'role', type: 'string', enum: ['admin', 'agent', 'customer'], example: 'customer'),
        new OA\Property(property: 'role_label', type: 'string', example: 'Customer'),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'is_available', description: 'Only present for agents', type: 'boolean', example: true),
        new OA\Property(property: 'email_verified_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'last_login_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Token',
    properties: [
        new OA\Property(property: 'access_token', type: 'string', example: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...'),
        new OA\Property(property: 'token_type', type: 'string', example: 'bearer'),
        new OA\Property(property: 'expires_in', description: 'Token lifetime in seconds', type: 'integer', example: 3600),
        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ApiResponse',
    description: 'Standard response envelope used by every endpoint.',
    properties: [
        new OA\Property(property: 'data', nullable: true),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'errors', type: 'object'),
        new OA\Property(property: 'success', type: 'boolean'),
        new OA\Property(property: 'status', type: 'string', enum: ['success', 'error']),
    ],
    type: 'object',
)]
#[OA\Response(
    response: 'Unauthenticated',
    description: 'Missing, invalid or expired token',
    content: new OA\JsonContent(
        allOf: [new OA\Schema(ref: '#/components/schemas/ApiResponse')],
        example: ['data' => null, 'message' => 'Unauthenticated.', 'errors' => [], 'success' => false, 'status' => 'error'],
    ),
)]
#[OA\Response(
    response: 'ValidationError',
    description: 'Validation failed',
    content: new OA\JsonContent(
        allOf: [new OA\Schema(ref: '#/components/schemas/ApiResponse')],
        example: [
            'data' => null,
            'message' => 'The given data was invalid.',
            'errors' => ['email' => ['The email field is required.']],
            'success' => false,
            'status' => 'error',
        ],
    ),
)]
#[OA\Response(
    response: 'AccountInactive',
    description: 'Account has been deactivated',
    content: new OA\JsonContent(
        allOf: [new OA\Schema(ref: '#/components/schemas/ApiResponse')],
        example: ['data' => null, 'message' => 'Your account has been deactivated. Please contact an administrator.', 'errors' => [], 'success' => false, 'status' => 'error'],
    ),
)]
#[OA\Response(
    response: 'TooManyRequests',
    description: 'Rate limit exceeded (5/min per email+IP, 20/min per IP)',
    content: new OA\JsonContent(
        allOf: [new OA\Schema(ref: '#/components/schemas/ApiResponse')],
        example: ['data' => null, 'message' => 'Too many requests. Please try again later.', 'errors' => [], 'success' => false, 'status' => 'error'],
    ),
)]
class OpenApiSpec {}

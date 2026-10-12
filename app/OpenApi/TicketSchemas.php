<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI components for the ticket endpoints (schemas, parameters, error responses).
 */
#[OA\Tag(name: 'Tickets', description: 'Create, view, update, resolve and close support tickets')]
#[OA\Tag(name: 'Categories', description: 'Ticket categories')]
#[OA\Schema(
    schema: 'TicketUser',
    description: 'Creator or assignee. `email` is only included for staff.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 3),
        new OA\Property(property: 'name', type: 'string', example: 'Agent One'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'agent1@example.com'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Category',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Technical'),
        new OA\Property(property: 'slug', type: 'string', example: 'technical'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Ticket',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'number', description: 'Public ticket ID, also used in URLs', type: 'string', example: 'TKT-2026-000001'),
        new OA\Property(property: 'subject', type: 'string', example: 'Cannot log in'),
        new OA\Property(property: 'description', type: 'string', example: 'I get an error after entering my password.'),
        new OA\Property(property: 'priority', properties: [
            new OA\Property(property: 'value', type: 'string', enum: ['low', 'medium', 'high', 'critical'], example: 'high'),
            new OA\Property(property: 'label', type: 'string', example: 'High'),
        ], type: 'object'),
        new OA\Property(property: 'status', properties: [
            new OA\Property(property: 'value', type: 'string', enum: ['open', 'in_progress', 'waiting_for_customer', 'resolved', 'closed'], example: 'open'),
            new OA\Property(property: 'label', type: 'string', example: 'Open'),
        ], type: 'object'),
        new OA\Property(property: 'category', ref: '#/components/schemas/Category'),
        new OA\Property(property: 'created_by', ref: '#/components/schemas/TicketUser'),
        new OA\Property(property: 'assigned_to', ref: '#/components/schemas/TicketUser', nullable: true),
        new OA\Property(property: 'assigned_at', description: 'When the current assignee got the ticket', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'due_at', description: 'SLA deadline (set by the SLA module)', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'resolved_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Pagination',
    properties: [
        new OA\Property(property: 'current_page', type: 'integer', example: 1),
        new OA\Property(property: 'per_page', type: 'integer', example: 15),
        new OA\Property(property: 'total', type: 'integer', example: 42),
        new OA\Property(property: 'last_page', type: 'integer', example: 3),
        new OA\Property(property: 'from', type: 'integer', nullable: true, example: 1),
        new OA\Property(property: 'to', type: 'integer', nullable: true, example: 15),
        new OA\Property(property: 'next_page_url', type: 'string', nullable: true),
        new OA\Property(property: 'prev_page_url', type: 'string', nullable: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TicketEnvelope',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/ApiResponse'),
        new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Ticket')]),
    ],
)]
#[OA\Schema(
    schema: 'TicketListEnvelope',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/ApiResponse'),
        new OA\Schema(properties: [
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Ticket')),
            new OA\Property(property: 'pagination', ref: '#/components/schemas/Pagination'),
        ]),
    ],
)]
class TicketSchemas {}

<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared path parameters and error responses for the ticket endpoints.
 */
#[OA\Parameter(
    parameter: 'TicketNumber',
    name: 'ticket',
    description: 'Ticket number',
    in: 'path',
    required: true,
    schema: new OA\Schema(type: 'string', example: 'TKT-2026-000001'),
)]
#[OA\Parameter(
    parameter: 'Page',
    name: 'page',
    in: 'query',
    schema: new OA\Schema(type: 'integer', minimum: 1, example: 1),
)]
#[OA\Parameter(
    parameter: 'PerPage',
    name: 'per_page',
    description: 'Items per page (1-100, default 15)',
    in: 'query',
    schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1, example: 15),
)]
#[OA\Parameter(
    parameter: 'Keyword',
    name: 'q',
    description: 'Search text (2 to 100 characters). Matches anywhere in the ticket number, subject or description. Not case sensitive.',
    in: 'query',
    schema: new OA\Schema(type: 'string', minLength: 2, maxLength: 100, example: 'login'),
)]
#[OA\Parameter(
    parameter: 'TicketNumberFilter',
    name: 'number',
    description: 'Exact ticket number',
    in: 'query',
    schema: new OA\Schema(type: 'string', example: 'TKT-2026-000001'),
)]
#[OA\Parameter(
    parameter: 'PriorityFilter',
    name: 'priority',
    description: 'Only tickets with this priority',
    in: 'query',
    schema: new OA\Schema(type: 'string', enum: ['low', 'medium', 'high', 'critical']),
)]
#[OA\Parameter(
    parameter: 'CategoryFilter',
    name: 'category_id',
    description: 'Only tickets in this category (id from GET /api/categories)',
    in: 'query',
    schema: new OA\Schema(type: 'integer', example: 1),
)]
#[OA\Parameter(
    parameter: 'AssignedToFilter',
    name: 'assigned_to',
    description: 'Only tickets assigned to this agent (user id). Cannot be combined with `unassigned`.',
    in: 'query',
    schema: new OA\Schema(type: 'integer', example: 3),
)]
#[OA\Parameter(
    parameter: 'UnassignedFilter',
    name: 'unassigned',
    description: '`true` = only tickets nobody has taken yet. Cannot be combined with `assigned_to`.',
    in: 'query',
    schema: new OA\Schema(type: 'boolean'),
)]
#[OA\Parameter(
    parameter: 'CustomerFilter',
    name: 'customer_id',
    description: 'Only tickets created by this customer (user id)',
    in: 'query',
    schema: new OA\Schema(type: 'integer', example: 5),
)]
#[OA\Parameter(
    parameter: 'CreatedFrom',
    name: 'created_from',
    description: 'Created on or after this day (YYYY-MM-DD, UTC)',
    in: 'query',
    schema: new OA\Schema(type: 'string', format: 'date', example: '2026-10-01'),
)]
#[OA\Parameter(
    parameter: 'CreatedTo',
    name: 'created_to',
    description: 'Created on or before this day, the whole day included (YYYY-MM-DD, UTC). Not before `created_from`.',
    in: 'query',
    schema: new OA\Schema(type: 'string', format: 'date', example: '2026-10-31'),
)]
#[OA\Parameter(
    parameter: 'StatusFilter',
    name: 'status',
    description: 'Only tickets with this status',
    in: 'query',
    schema: new OA\Schema(type: 'string', enum: ['open', 'in_progress', 'waiting_for_customer', 'resolved', 'closed']),
)]
#[OA\Parameter(
    parameter: 'TicketSort',
    name: 'sort',
    description: 'Order of the results. `sla_deadline`: earliest SLA deadline first, tickets without one last. `created_asc`: oldest first. `created_desc`: newest first. Replaces the default order of the endpoint.',
    in: 'query',
    schema: new OA\Schema(type: 'string', enum: ['sla_deadline', 'created_asc', 'created_desc']),
)]
#[OA\Parameter(
    parameter: 'SlaFilter',
    name: 'sla',
    description: 'Only ACTIVE tickets in this SLA state: `within`, `approaching` (most of the SLA time used) or `breached` (past the deadline). Tickets without a deadline match none.',
    in: 'query',
    schema: new OA\Schema(type: 'string', enum: ['within', 'approaching', 'breached']),
)]
#[OA\Response(
    response: 'Forbidden',
    description: 'You are not allowed to do this',
    content: new OA\JsonContent(
        allOf: [new OA\Schema(ref: '#/components/schemas/ApiResponse')],
        example: ['data' => null, 'message' => 'You are not authorized to perform this action.', 'errors' => [], 'success' => false, 'status' => 'error'],
    ),
)]
#[OA\Response(
    response: 'NotFound',
    description: 'Ticket not found',
    content: new OA\JsonContent(
        allOf: [new OA\Schema(ref: '#/components/schemas/ApiResponse')],
        example: ['data' => null, 'message' => 'The requested resource was not found.', 'errors' => [], 'success' => false, 'status' => 'error'],
    ),
)]
#[OA\Response(
    response: 'Conflict',
    description: 'The ticket is in a state that does not allow this',
    content: new OA\JsonContent(
        allOf: [new OA\Schema(ref: '#/components/schemas/ApiResponse')],
        example: ['data' => null, 'message' => 'A ticket cannot move from "Closed" to "Open".', 'errors' => [], 'success' => false, 'status' => 'error'],
    ),
)]
class TicketComponents {}

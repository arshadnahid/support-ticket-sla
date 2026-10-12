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

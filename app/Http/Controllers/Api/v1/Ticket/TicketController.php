<?php

namespace App\Http\Controllers\Api\v1\Ticket;

use App\Enums\TicketListScope;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Ticket\ChangeTicketStatusRequest;
use App\Http\Requests\Api\Ticket\ListTicketsRequest;
use App\Http\Requests\Api\Ticket\StoreTicketRequest;
use App\Http\Requests\Api\Ticket\UpdateTicketRequest;
use App\Http\Resources\Ticket\TicketResource;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ticket\TicketService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;

/**
 * Every action first asks TicketPolicy (Gate::authorize); a refusal becomes a 403
 * through ApiExceptionRenderer. Lists are filtered by Ticket::visibleTo() instead.
 */
class TicketController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly TicketService $ticketService) {}

    #[OA\Get(
        path: '/api/tickets',
        operationId: 'ticketsIndex',
        description: 'Every ticket the current user may see, newest first. Customers see their own tickets, agents see tickets assigned to them plus the unassigned queue, admins see all.',
        summary: 'List tickets',
        security: [['bearerAuth' => []]],
        tags: ['Tickets'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/Page'),
            new OA\Parameter(ref: '#/components/parameters/PerPage'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of tickets', content: new OA\JsonContent(ref: '#/components/schemas/TicketListEnvelope')),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function index(ListTicketsRequest $request): JsonResponse
    {
        return $this->listing($request, TicketListScope::All);
    }

    #[OA\Get(
        path: '/api/tickets/active',
        operationId: 'ticketsActive',
        description: 'Open, in-progress and waiting-for-customer tickets, earliest SLA deadline first (tickets without a deadline last).',
        summary: 'List upcoming / active tickets',
        security: [['bearerAuth' => []]],
        tags: ['Tickets'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/Page'),
            new OA\Parameter(ref: '#/components/parameters/PerPage'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of active tickets', content: new OA\JsonContent(ref: '#/components/schemas/TicketListEnvelope')),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function active(ListTicketsRequest $request): JsonResponse
    {
        return $this->listing($request, TicketListScope::Active);
    }

    #[OA\Get(
        path: '/api/tickets/resolved',
        operationId: 'ticketsResolved',
        description: 'Resolved and closed tickets, most recently updated first.',
        summary: 'List resolved / closed tickets',
        security: [['bearerAuth' => []]],
        tags: ['Tickets'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/Page'),
            new OA\Parameter(ref: '#/components/parameters/PerPage'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of resolved or closed tickets', content: new OA\JsonContent(ref: '#/components/schemas/TicketListEnvelope')),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function resolved(ListTicketsRequest $request): JsonResponse
    {
        return $this->listing($request, TicketListScope::Finished);
    }

    #[OA\Post(
        path: '/api/tickets',
        operationId: 'ticketsStore',
        description: 'Creates a ticket with status `open` and a new unique number such as `TKT-2026-000001`. `priority` defaults to `medium`.',
        summary: 'Create a ticket',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['subject', 'description', 'category_id'],
                properties: [
                    new OA\Property(property: 'subject', type: 'string', maxLength: 255, example: 'Cannot log in'),
                    new OA\Property(property: 'description', type: 'string', maxLength: 10000, example: 'I get an error after entering my password.'),
                    new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high', 'critical'], example: 'high'),
                    new OA\Property(property: 'category_id', description: 'Id from GET /api/categories', type: 'integer', example: 1),
                ],
            ),
        ),
        tags: ['Tickets'],
        responses: [
            new OA\Response(response: 201, description: 'Ticket created', content: new OA\JsonContent(ref: '#/components/schemas/TicketEnvelope')),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function store(StoreTicketRequest $request): JsonResponse
    {
        Gate::authorize('create', Ticket::class);

        $ticket = $this->ticketService->create($request->toDTO(), $request->user());

        return $this->sendCreatedResponse('Ticket created', new TicketResource($ticket));
    }

    #[OA\Get(
        path: '/api/tickets/{ticket}',
        operationId: 'ticketsShow',
        summary: 'View a ticket',
        security: [['bearerAuth' => []]],
        tags: ['Tickets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TicketNumber')],
        responses: [
            new OA\Response(response: 200, description: 'The ticket', content: new OA\JsonContent(ref: '#/components/schemas/TicketEnvelope')),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
        ],
    )]
    public function show(Ticket $ticket): JsonResponse
    {
        Gate::authorize('view', $ticket);

        return $this->sendResponse(null, new TicketResource($this->ticketService->show($ticket)));
    }

    #[OA\Patch(
        path: '/api/tickets/{ticket}',
        operationId: 'ticketsUpdate',
        description: 'Send only the fields to change. Customers may edit `subject`, `description` and `category_id` of their own ticket while it is still `open`, and may not send `priority`. Agents can edit tickets assigned to them, admins any ticket. Closed tickets cannot be edited.',
        summary: 'Update a ticket',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'subject', type: 'string', maxLength: 255),
                    new OA\Property(property: 'description', type: 'string', maxLength: 10000),
                    new OA\Property(property: 'priority', description: 'Staff only', type: 'string', enum: ['low', 'medium', 'high', 'critical']),
                    new OA\Property(property: 'category_id', type: 'integer'),
                ],
            ),
        ),
        tags: ['Tickets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TicketNumber')],
        responses: [
            new OA\Response(response: 200, description: 'Ticket updated', content: new OA\JsonContent(ref: '#/components/schemas/TicketEnvelope')),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function update(UpdateTicketRequest $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('update', $ticket);

        $ticket = $this->ticketService->update($ticket, $request->toDTO(), $request->user());

        return $this->sendUpdatedResponse('Ticket updated', new TicketResource($ticket));
    }

    #[OA\Post(
        path: '/api/tickets/{ticket}/status',
        operationId: 'ticketsChangeStatus',
        description: 'Allowed moves: open -> in_progress / waiting_for_customer / resolved / closed; in_progress -> waiting_for_customer / resolved / closed; waiting_for_customer -> in_progress / resolved / closed; resolved -> in_progress (reopen) / closed. `closed` is final. Customers may only close their own ticket.',
        summary: 'Change a ticket status',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['open', 'in_progress', 'waiting_for_customer', 'resolved', 'closed'], example: 'in_progress'),
                ],
            ),
        ),
        tags: ['Tickets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TicketNumber')],
        responses: [
            new OA\Response(response: 200, description: 'Status changed', content: new OA\JsonContent(ref: '#/components/schemas/TicketEnvelope')),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/Conflict', response: 409),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function changeStatus(ChangeTicketStatusRequest $request, Ticket $ticket): JsonResponse
    {
        return $this->transition($ticket, $request->status(), $request->user());
    }

    #[OA\Post(
        path: '/api/tickets/{ticket}/resolve',
        operationId: 'ticketsResolve',
        description: 'Shortcut for setting the status to `resolved`. Agents (assigned) and admins only.',
        summary: 'Resolve a ticket',
        security: [['bearerAuth' => []]],
        tags: ['Tickets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TicketNumber')],
        responses: [
            new OA\Response(response: 200, description: 'Ticket resolved', content: new OA\JsonContent(ref: '#/components/schemas/TicketEnvelope')),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/Conflict', response: 409),
        ],
    )]
    public function resolve(Request $request, Ticket $ticket): JsonResponse
    {
        return $this->transition($ticket, TicketStatus::Resolved, $request->user());
    }

    #[OA\Post(
        path: '/api/tickets/{ticket}/close',
        operationId: 'ticketsClose',
        description: 'Shortcut for setting the status to `closed` (final). The ticket owner, the assigned agent and admins may close a ticket.',
        summary: 'Close a ticket',
        security: [['bearerAuth' => []]],
        tags: ['Tickets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TicketNumber')],
        responses: [
            new OA\Response(response: 200, description: 'Ticket closed', content: new OA\JsonContent(ref: '#/components/schemas/TicketEnvelope')),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/Conflict', response: 409),
        ],
    )]
    public function close(Request $request, Ticket $ticket): JsonResponse
    {
        return $this->transition($ticket, TicketStatus::Closed, $request->user());
    }

    private function listing(ListTicketsRequest $request, TicketListScope $scope): JsonResponse
    {
        Gate::authorize('viewAny', Ticket::class);

        $tickets = $this->ticketService->list($request->user(), $scope, $request->perPage());

        return $this->sendResponse(null, TicketResource::collection($tickets));
    }

    private function transition(Ticket $ticket, TicketStatus $to, User $actor): JsonResponse
    {
        Gate::authorize('changeStatus', [$ticket, $to]);

        $ticket = $this->ticketService->changeStatus($ticket, $to, $actor);

        return $this->sendUpdatedResponse('Ticket status changed to '.$to->label(), new TicketResource($ticket));
    }
}

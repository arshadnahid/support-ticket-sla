<?php

namespace App\Http\Controllers\Api\v1\Ticket;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Ticket\AssignTicketRequest;
use App\Http\Resources\Ticket\TicketResource;
use App\Models\Ticket;
use App\Services\Ticket\TicketAssignmentService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;

class TicketAssignmentController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly TicketAssignmentService $assignment) {}

    #[OA\Post(
        path: '/api/tickets/{ticket}/assign',
        operationId: 'ticketsAssign',
        description: 'Admin only. Gives the ticket to an active support agent, replacing the current one if there is one. The agent does not have to be marked available. Resolved and closed tickets cannot be assigned.',
        summary: 'Assign a ticket to an agent',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['agent_id'],
                properties: [new OA\Property(property: 'agent_id', description: 'User id of an active agent', type: 'integer', example: 3)],
            ),
        ),
        tags: ['Tickets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TicketNumber')],
        responses: [
            new OA\Response(response: 200, description: 'Ticket assigned', content: new OA\JsonContent(ref: '#/components/schemas/TicketEnvelope')),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/Conflict', response: 409),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function assign(AssignTicketRequest $request, Ticket $ticket): JsonResponse
    {
        // Authorization ('assign') is done by AssignTicketRequest::authorize(), before validation.
        $ticket = $this->assignment->assign($ticket, $request->agentId(), $request->user());

        return $this->sendUpdatedResponse('Ticket assigned', new TicketResource($ticket));
    }

    #[OA\Post(
        path: '/api/tickets/{ticket}/accept',
        operationId: 'ticketsAccept',
        description: 'Agents only. Takes an unassigned ticket for yourself. If two agents accept at the same time, exactly one succeeds and the other gets 409. Accepting a ticket that is already yours succeeds without changing anything.',
        summary: 'Accept an unassigned ticket',
        security: [['bearerAuth' => []]],
        tags: ['Tickets'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TicketNumber')],
        responses: [
            new OA\Response(response: 200, description: 'The ticket is now yours', content: new OA\JsonContent(ref: '#/components/schemas/TicketEnvelope')),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/Conflict', response: 409),
        ],
    )]
    public function accept(Request $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('accept', $ticket);

        $ticket = $this->assignment->accept($ticket, $request->user());

        return $this->sendUpdatedResponse('Ticket accepted', new TicketResource($ticket));
    }
}

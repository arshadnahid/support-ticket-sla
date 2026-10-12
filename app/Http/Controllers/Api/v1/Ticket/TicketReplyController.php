<?php

namespace App\Http\Controllers\Api\v1\Ticket;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Ticket\ListRepliesRequest;
use App\Http\Requests\Api\Ticket\StoreReplyRequest;
use App\Http\Resources\Ticket\ReplyResource;
use App\Models\Ticket;
use App\Services\Ticket\TicketConversationService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;

class TicketReplyController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly TicketConversationService $conversation) {}

    #[OA\Get(
        path: '/api/tickets/{ticket}/replies',
        operationId: 'ticketRepliesIndex',
        description: 'The conversation on a ticket, oldest first. Customers see replies only; **internal agent notes are never included for customers**. Staff (the assigned agent, or any agent while the ticket is unassigned, and admins) see replies and internal notes, marked with `is_internal`. Returns 404 "not found" when the ticket has no visible replies yet.',
        summary: 'List replies and notes',
        security: [['bearerAuth' => []]],
        tags: ['Conversation'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/TicketNumber'),
            new OA\Parameter(ref: '#/components/parameters/Page'),
            new OA\Parameter(ref: '#/components/parameters/PerPage'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of replies', content: new OA\JsonContent(ref: '#/components/schemas/ReplyListEnvelope')),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function index(ListRepliesRequest $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('view', $ticket);

        $replies = $this->conversation->conversation($ticket, $request->user(), $request->perPage());

        return $this->sendResponse(null, ReplyResource::collection($replies));
    }

    #[OA\Post(
        path: '/api/tickets/{ticket}/replies',
        operationId: 'ticketRepliesStore',
        description: "Adds a reply to the ticket, optionally with files. Who may reply: the customer who owns the ticket, the agent it is assigned to, and admins. An agent must accept an unassigned ticket before replying.\n\nSet `is_internal` to `true` to write an **internal note**: only staff may do this (a customer gets 403), and the note and its files are never shown to customers.\n\nSend JSON for text only, or `multipart/form-data` to attach files (`attachments[]`). Files are validated by real content, not just name; see the `attachments` field. Closed tickets return 409.",
        summary: 'Reply to a ticket or add an internal note',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: [
                new OA\JsonContent(
                    required: ['body'],
                    properties: [
                        new OA\Property(property: 'body', type: 'string', maxLength: 10000, example: 'Please try clearing your cache and sign in again.'),
                        new OA\Property(property: 'is_internal', description: 'true = internal note, staff only. Default false.', type: 'boolean', example: false),
                    ],
                ),
                new OA\MediaType(
                    mediaType: 'multipart/form-data',
                    schema: new OA\Schema(
                        required: ['body'],
                        properties: [
                            new OA\Property(property: 'body', type: 'string', maxLength: 10000),
                            new OA\Property(property: 'is_internal', description: '`true`/`1` = internal note, staff only. Default false.', type: 'boolean'),
                            new OA\Property(
                                property: 'attachments',
                                description: 'Up to 5 files, 5 MB each. Allowed: jpg, jpeg, png, gif, webp, pdf, txt, log, csv, docx, xlsx.',
                                type: 'array',
                                items: new OA\Items(type: 'string', format: 'binary'),
                            ),
                        ],
                    ),
                ),
            ],
        ),
        tags: ['Conversation'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TicketNumber')],
        responses: [
            new OA\Response(response: 201, description: 'Reply added', content: new OA\JsonContent(ref: '#/components/schemas/ReplyEnvelope')),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/Conflict', response: 409),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function store(StoreReplyRequest $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize($request->isInternal() ? 'addInternalNote' : 'reply', $ticket);

        $reply = $this->conversation->reply(
            $ticket,
            $request->user(),
            $request->body(),
            $request->isInternal(),
            $request->files(),
        );

        return $this->sendCreatedResponse($reply->is_internal ? 'Internal note added' : 'Reply added', new ReplyResource($reply));
    }
}

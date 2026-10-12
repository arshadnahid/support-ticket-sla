<?php

namespace App\Http\Controllers\Api\v1\Ticket;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Ticket\StoreAttachmentsRequest;
use App\Http\Resources\Ticket\AttachmentResource;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Services\Ticket\AttachmentStorage;
use App\Services\Ticket\TicketConversationService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketAttachmentController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly TicketConversationService $conversation,
        private readonly AttachmentStorage $storage,
    ) {}

    #[OA\Get(
        path: '/api/tickets/{ticket}/attachments',
        operationId: 'ticketAttachmentsIndex',
        description: 'Every file on the ticket and on its replies that you may see. Files on internal notes are only listed for staff. Each entry has a `download_url`.',
        summary: 'List attachments',
        security: [['bearerAuth' => []]],
        tags: ['Attachments'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TicketNumber')],
        responses: [
            new OA\Response(response: 200, description: 'The attachments', content: new OA\JsonContent(ref: '#/components/schemas/AttachmentListEnvelope')),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
        ],
    )]
    public function index(Request $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('view', $ticket);

        return $this->sendResponse(null, AttachmentResource::collection($this->conversation->attachmentsFor($ticket, $request->user())));
    }

    #[OA\Post(
        path: '/api/tickets/{ticket}/attachments',
        operationId: 'ticketAttachmentsStore',
        description: "Attaches files to the ticket itself (to attach to a reply, send them with the reply instead). Same people as replying: the owning customer, the assigned agent, admins. Closed tickets return 409.\n\nLimits: up to 5 files per request, 5 MB each. Allowed types: jpg, jpeg, png, gif, webp, pdf, txt, log, csv, docx, xlsx. The file content is checked, not only its name. Files are stored privately and served only through `download_url`.",
        summary: 'Upload files to a ticket',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['files'],
                    properties: [
                        new OA\Property(property: 'files', type: 'array', items: new OA\Items(type: 'string', format: 'binary')),
                    ],
                ),
            ),
        ),
        tags: ['Attachments'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/TicketNumber')],
        responses: [
            new OA\Response(response: 201, description: 'Files attached', content: new OA\JsonContent(ref: '#/components/schemas/AttachmentListEnvelope')),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/Conflict', response: 409),
            new OA\Response(ref: '#/components/responses/ValidationError', response: 422),
        ],
    )]
    public function store(StoreAttachmentsRequest $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('reply', $ticket);

        $attachments = $this->conversation->attach($ticket, $request->user(), $request->files());

        return $this->sendCreatedResponse('Files attached', AttachmentResource::collection($attachments));
    }

    #[OA\Get(
        path: '/api/tickets/{ticket}/attachments/{attachment}',
        operationId: 'ticketAttachmentsDownload',
        description: 'Downloads one file. Requires the Bearer token like every other endpoint (a plain browser link will not work: fetch it with the header and save the response). The file is always sent as a download with `X-Content-Type-Options: nosniff`, never displayed inline. A file on an internal note returns 404 to customers, as if it did not exist.',
        summary: 'Download an attachment',
        security: [['bearerAuth' => []]],
        tags: ['Attachments'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/TicketNumber'),
            new OA\Parameter(name: 'attachment', description: 'Attachment id (uuid) from the attachment list', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The file', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
        ],
    )]
    public function download(Request $request, Ticket $ticket, TicketAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $ticket);

        // Same answer for "does not exist", "not on this ticket" (scoped binding) and "internal":
        // a customer cannot even tell that a hidden file exists.
        abort_unless($attachment->isVisibleTo($request->user()) && $this->storage->exists($attachment), 404);

        return $this->storage->download($attachment);
    }
}

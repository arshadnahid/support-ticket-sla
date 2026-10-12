<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * OpenAPI schemas for replies, internal notes and attachments.
 */
#[OA\Tag(name: 'Conversation', description: 'Customer replies, agent replies and internal agent notes')]
#[OA\Tag(name: 'Attachments', description: 'Files on tickets and replies (private storage, authorised download)')]
#[OA\Schema(
    schema: 'Attachment',
    properties: [
        new OA\Property(property: 'id', description: 'Public id (uuid)', type: 'string', format: 'uuid', example: '5f0e4c1a-6b1e-4f7e-9a3c-2d8b7c1e9f10'),
        new OA\Property(property: 'filename', type: 'string', example: 'screenshot.png'),
        new OA\Property(property: 'mime_type', description: 'Detected from the file content', type: 'string', example: 'image/png'),
        new OA\Property(property: 'size', description: 'Bytes', type: 'integer', example: 48213),
        new OA\Property(property: 'reply_id', description: 'null = attached to the ticket itself', type: 'integer', nullable: true, example: 7),
        new OA\Property(property: 'uploaded_by', ref: '#/components/schemas/TicketUser'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'download_url', description: 'Requires the Authorization header', type: 'string', example: 'http://support-ticket-sla.test/api/tickets/TKT-2026-000001/attachments/5f0e4c1a-6b1e-4f7e-9a3c-2d8b7c1e9f10'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Reply',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 7),
        new OA\Property(property: 'body', type: 'string', example: 'Please try clearing your cache and sign in again.'),
        new OA\Property(property: 'is_internal', description: 'true = internal note. Only ever returned to staff.', type: 'boolean', example: false),
        new OA\Property(property: 'author', ref: '#/components/schemas/TicketUser'),
        new OA\Property(property: 'author_is_staff', type: 'boolean', example: true),
        new OA\Property(property: 'attachments', type: 'array', items: new OA\Items(ref: '#/components/schemas/Attachment')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ReplyEnvelope',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/ApiResponse'),
        new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Reply')]),
    ],
)]
#[OA\Schema(
    schema: 'ReplyListEnvelope',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/ApiResponse'),
        new OA\Schema(properties: [
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Reply')),
            new OA\Property(property: 'pagination', ref: '#/components/schemas/Pagination'),
        ]),
    ],
)]
#[OA\Schema(
    schema: 'AttachmentListEnvelope',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/ApiResponse'),
        new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Attachment'))]),
    ],
)]
class ConversationSchemas {}

<?php

namespace App\Http\Controllers\Api\v1\Ticket;

use App\Http\Controllers\Controller;
use App\Http\Resources\Ticket\CategoryResource;
use App\Repositories\Category\CategoryInterfaces\CategoryInterface;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class CategoryController extends Controller
{
    use ApiResponses;

    // No service layer: listing categories has no business rules to put in one.
    public function __construct(private readonly CategoryInterface $categories) {}

    /**
     * List the categories a ticket can be filed under.
     */
    #[OA\Get(
        path: '/api/categories',
        operationId: 'categoriesIndex',
        summary: 'List active ticket categories',
        security: [['bearerAuth' => []]],
        tags: ['Categories'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Active categories, ordered by name',
                content: new OA\JsonContent(
                    allOf: [
                        new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                        new OA\Schema(properties: [
                            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Category')),
                        ]),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AccountInactive', response: 403),
        ],
    )]
    public function index(): JsonResponse
    {
        return $this->sendResponse(null, CategoryResource::collection($this->categories->active()));
    }
}

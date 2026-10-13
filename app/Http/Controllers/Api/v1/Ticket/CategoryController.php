<?php

namespace App\Http\Controllers\Api\v1\Ticket;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Ticket\ListCategoriesRequest;
use App\Http\Resources\Ticket\CategoryResource;
use App\Services\Category\CategoryService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class CategoryController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly CategoryService $categoryService) {}

    /**
     * List the categories a ticket can be filed under.
     */
    #[OA\Get(
        path: '/api/categories',
        operationId: 'categoriesIndex',
        summary: 'List active ticket categories',
        security: [['bearerAuth' => []]],
        tags: ['Categories'],
        parameters: [
            new OA\Parameter(name: 'name', description: 'Part of the category name (not case sensitive)', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 100, example: 'billing')),
            new OA\Parameter(ref: '#/components/parameters/Page'),
            new OA\Parameter(ref: '#/components/parameters/PerPage'),
        ],
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
    public function index(ListCategoriesRequest $request): JsonResponse
    {
        $categories = $this->categoryService->getActiveCategories($request->toDTO());

        return $this->sendResponse(null, CategoryResource::collection($categories));
    }
}

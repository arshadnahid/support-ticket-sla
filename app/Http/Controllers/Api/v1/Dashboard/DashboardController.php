<?php

namespace App\Http\Controllers\Api\v1\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DashboardController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly DashboardService $dashboard) {}

    #[OA\Get(
        path: '/api/dashboard',
        operationId: 'dashboardShow',
        description: 'Ticket numbers for the logged-in user, counted over the tickets they may see: customers their own tickets, agents the tickets assigned to them plus the unassigned queue, admins all tickets.

`open_tickets`, `unassigned`, `critical`, `sla_breached` and `sla_approaching` count **active** tickets only (open, in progress, waiting for customer). `resolved` and `closed` count finished tickets.

**Staff only** (agents and admins): `sla_breached`, `sla_approaching` and `by_agent`. Customers do not get these keys. For an agent, `by_agent` only contains themselves.',
        summary: 'Dashboard',
        security: [['bearerAuth' => []]],
        tags: ['Dashboard'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dashboard numbers',
                content: new OA\JsonContent(
                    allOf: [
                        new OA\Schema(ref: '#/components/schemas/ApiResponse'),
                        new OA\Schema(properties: [
                            new OA\Property(property: 'data', properties: [
                                new OA\Property(property: 'summary', properties: [
                                    new OA\Property(property: 'open_tickets', description: 'Active tickets', type: 'integer', example: 42),
                                    new OA\Property(property: 'unassigned', description: 'Active tickets nobody has taken', type: 'integer', example: 5),
                                    new OA\Property(property: 'critical', description: 'Active critical-priority tickets', type: 'integer', example: 3),
                                    new OA\Property(property: 'resolved', type: 'integer', example: 20),
                                    new OA\Property(property: 'closed', type: 'integer', example: 80),
                                    new OA\Property(property: 'sla_breached', description: 'Staff only', type: 'integer', example: 4),
                                    new OA\Property(property: 'sla_approaching', description: 'Staff only', type: 'integer', example: 6),
                                ], type: 'object'),
                                new OA\Property(property: 'by_priority', description: 'Active tickets per priority', properties: [
                                    new OA\Property(property: 'low', type: 'integer', example: 10),
                                    new OA\Property(property: 'medium', type: 'integer', example: 20),
                                    new OA\Property(property: 'high', type: 'integer', example: 9),
                                    new OA\Property(property: 'critical', type: 'integer', example: 3),
                                ], type: 'object'),
                                new OA\Property(property: 'by_agent', description: 'Staff only. Active tickets per assigned agent, busiest first', type: 'array', items: new OA\Items(properties: [
                                    new OA\Property(property: 'agent_id', type: 'integer', example: 2),
                                    new OA\Property(property: 'name', type: 'string', example: 'Rahim'),
                                    new OA\Property(property: 'active_tickets', type: 'integer', example: 12),
                                    new OA\Property(property: 'sla_breached', type: 'integer', example: 1),
                                ], type: 'object')),
                            ], type: 'object'),
                        ]),
                    ],
                ),
            ),
            new OA\Response(ref: '#/components/responses/Unauthenticated', response: 401),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        return $this->sendResponse(null, $this->dashboard->for($request->user()));
    }
}

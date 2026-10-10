<?php

namespace App\Exceptions;

use App\Traits\ApiResponses;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tymon\JWTAuth\Exceptions\JWTException;

/**
 * Turns exceptions into the standard API envelope (see ApiResponses) for API requests.
 * Registered once in bootstrap/app.php; non-API requests keep Laravel's default handling.
 */
class ApiExceptionRenderer
{
    use ApiResponses;

    public function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $this->isApi($request));

        $exceptions->render(fn (ValidationException $e, Request $request) => $this->when($request, fn () => $this->validationErrorsResponse(null, $e->errors(), $e->status)
        ));

        $exceptions->render(fn (InvalidCredentialsException $e, Request $request) => $this->when($request, fn () => $this->unauthenticatedResponse(__('apiresponse.TEXT_INVALID_CREDENTIALS'))
        ));

        $exceptions->render(fn (AuthenticationException|JWTException $e, Request $request) => $this->when($request, fn () => $this->unauthenticatedResponse()
        ));

        $exceptions->render(fn (AccountInactiveException $e, Request $request) => $this->when($request, fn () => $this->sendErrors(__('apiresponse.TEXT_ACCOUNT_INACTIVE'), [], Response::HTTP_FORBIDDEN)
        ));

        $exceptions->render(fn (AuthorizationException|AccessDeniedHttpException $e, Request $request) => $this->when($request, fn () => $this->sendErrors(__('apiresponse.TEXT_FORBIDDEN'), [], Response::HTTP_FORBIDDEN)
        ));

        // Laravel turns ModelNotFoundException (route model binding) into NotFoundHttpException first.
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $this->when($request, fn () => $this->sendErrors(__('apiresponse.TEXT_NOT_FOUND'), [], Response::HTTP_NOT_FOUND)
        ));

        $exceptions->render(fn (MethodNotAllowedHttpException $e, Request $request) => $this->when($request, fn () => $this->sendErrors(__('apiresponse.TEXT_METHOD_NOT_ALLOWED'), [], Response::HTTP_METHOD_NOT_ALLOWED)
        ));

        // The ticket is in a state that does not allow the request (e.g. reopening a closed ticket).
        $exceptions->render(fn (InvalidTicketTransitionException $e, Request $request) => $this->when($request, fn () => $this->sendErrors($e->getMessage(), [], Response::HTTP_CONFLICT)
        ));

        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => $this->when($request, fn () => $this->sendErrors(__('apiresponse.TEXT_TOO_MANY_REQUESTS'), [], Response::HTTP_TOO_MANY_REQUESTS)
            ->withHeaders($e->getHeaders())
        ));
    }

    private function isApi(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    /**
     * Returning null tells Laravel to fall back to its default rendering.
     *
     * @param  callable(): JsonResponse  $respond
     */
    private function when(Request $request, callable $respond): ?JsonResponse
    {
        return $this->isApi($request) ? $respond() : null;
    }
}

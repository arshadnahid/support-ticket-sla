<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

trait ApiResponses
{
    /**
     * Success response. Paginators (plain or wrapped in a resource collection)
     * are unwrapped into "data" and a "pagination" block is added.
     */
    public function sendResponse(?string $message = null, mixed $data = [], int $code = Response::HTTP_OK): JsonResponse
    {
        $paginator = $this->resolvePaginator($data);

        if ($paginator && $paginator->isEmpty()) {
            return $this->dataNotFoundJsonResponse($message, [], $this->paginationMeta($paginator));
        }

        if (($data instanceof Collection || $data instanceof ResourceCollection) && $data->isEmpty()) {
            return $this->dataNotFoundJsonResponse($message);
        }

        return $this->respond(
            $paginator && ! $data instanceof ResourceCollection ? $paginator->items() : $data,
            $message ?: __('apiresponse.TEXT_GET_DATA'),
            [],
            true,
            $code,
            $paginator ? $this->paginationMeta($paginator) : [],
        );
    }

    public function dataNotFoundJsonResponse(?string $message = null, mixed $data = [], array $extra = []): JsonResponse
    {
        return $this->respond($data, $message ?: __('apiresponse.TXT_DATA_NOT_FOUND'), [], false, Response::HTTP_NOT_FOUND, $extra);
    }

    public function sendCreatedResponse(?string $message = null, mixed $data = [], int $code = Response::HTTP_CREATED): JsonResponse
    {
        return $this->respond($data, $message ?: __('apiresponse.TEXT_CREATED_SUCCESSFULLY'), [], true, $code);
    }

    public function sendUpdatedResponse(?string $message = null, mixed $data = [], int $code = Response::HTTP_OK): JsonResponse
    {
        return $this->respond($data, $message ?: __('apiresponse.TEXT_UPDATED_SUCCESSFULLY'), [], true, $code);
    }

    /**
     * @param  array  $errors  Field => messages, or any error details.
     */
    public function sendErrors(?string $message = null, array $errors = [], int $code = Response::HTTP_UNPROCESSABLE_ENTITY): JsonResponse
    {
        return $this->respond(null, $message ?: __('apiresponse.TEXT_ERROR'), $errors, false, $code);
    }

    public function validationErrorsResponse(?string $message = null, array $errors = [], int $code = Response::HTTP_UNPROCESSABLE_ENTITY): JsonResponse
    {
        return $this->respond(null, $message ?: __('apiresponse.TEXT_ERROR_VALIDATION'), $errors, false, $code);
    }

    public function unauthenticatedResponse(?string $message = null): JsonResponse
    {
        return $this->respond(null, $message ?: __('apiresponse.TEXT_UNAUTHENTICATED'), [], false, Response::HTTP_UNAUTHORIZED);
    }

    private function respond(mixed $data, string $message, array $errors, bool $success, int $code, array $extra = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'message' => $message,
            'errors' => $errors,
            'success' => $success,
            'status' => $success ? 'success' : 'error',
        ] + $extra, $code);
    }

    private function resolvePaginator(mixed $data): ?AbstractPaginator
    {
        if ($data instanceof AbstractPaginator) {
            return $data;
        }

        if ($data instanceof ResourceCollection && $data->resource instanceof AbstractPaginator) {
            return $data->resource;
        }

        return null;
    }

    private function paginationMeta(AbstractPaginator $paginator): array
    {
        return ['pagination' => [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => method_exists($paginator, 'total') ? $paginator->total() : null,
            'last_page' => method_exists($paginator, 'lastPage') ? $paginator->lastPage() : null,
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'next_page_url' => $paginator->nextPageUrl(),
            'prev_page_url' => $paginator->previousPageUrl(),
        ]];
    }
}

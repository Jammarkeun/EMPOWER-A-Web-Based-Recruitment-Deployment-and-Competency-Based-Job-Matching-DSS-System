<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The single response envelope defined in the REST API contract.
 *
 * Every endpoint answers with the same shape, so the React client has one
 * success path and one error path to handle rather than a different structure
 * per module.
 */
class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'Request completed',
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    public static function created(mixed $data, string $message = 'Record created'): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    public static function error(
        string $message,
        int $status = 400,
        array $errors = [],
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    /**
     * Flattens Laravel's pagination envelope into the contract's meta block, so
     * the client never has to deal with two levels of "data".
     */
    public static function paginated(
        LengthAwarePaginator|ResourceCollection $paginator,
        string $message = 'Request completed',
    ): JsonResponse {
        $resolved = $paginator instanceof ResourceCollection
            ? $paginator->resource
            : $paginator;

        $items = $paginator instanceof ResourceCollection
            ? $paginator->collection
            : $resolved->items();

        return self::success($items, $message, 200, [
            'current_page' => $resolved->currentPage(),
            'per_page' => $resolved->perPage(),
            'total' => $resolved->total(),
            'last_page' => $resolved->lastPage(),
            'from' => $resolved->firstItem(),
            'to' => $resolved->lastItem(),
        ]);
    }
}

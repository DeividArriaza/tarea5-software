<?php

namespace App\Traits;

use App\Http\Middleware\CorrelationId;
use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    protected function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        $body = ['success' => true, 'message' => $message];
        if ($data !== null) {
            $body = array_merge($body, is_array($data) ? $data : ['data' => $data]);
        }
        return response()->json($this->withCorrelationId($body), $status);
    }

    protected function error(string $message, int $status = 400, array $errors = []): JsonResponse
    {
        $body = ['success' => false, 'message' => $message];
        if (!empty($errors)) {
            $body['errors'] = $errors;
        }
        return response()->json($this->withCorrelationId($body), $status);
    }

    private function withCorrelationId(array $body): array
    {
        $correlationId = request()?->attributes->get(CorrelationId::ATTRIBUTE);

        if ($correlationId) {
            $body['correlation_id'] = $correlationId;
        }

        return $body;
    }
}

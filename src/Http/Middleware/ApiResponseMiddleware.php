<?php

namespace SchemaCraft\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wraps all API responses in a consistent JSON envelope.
 *
 * Apply this middleware to any route group that should return a uniform
 * response shape regardless of what the underlying route returns:
 *
 *   Route::middleware(['auth:sanctum', 'sc-api-response'])->group(...);
 *
 * How it works:
 *   Laravel's global exception handler converts thrown exceptions into responses
 *   BEFORE they flow back through the middleware stack — so we never see raw
 *   exceptions here. Instead we detect error status codes on the returned
 *   response and reformat whatever body Laravel produced into our envelope.
 *
 * The one exception to the above: if a middleware earlier in the pipeline
 * throws BEFORE calling $next(), that exception can propagate up to us directly.
 * We catch that with a bare Throwable handler as a safety net.
 *
 * Success envelope:
 *   { "status": "success", "status_code": 200, "message": "Success.", "data": {...} }
 *
 * Error envelope:
 *   { "status": "error", "status_code": 422, "message": "...", "errors": {...}, "data": null }
 *
 * Pass-through: non-JSON responses with content (HTML pages, file downloads)
 * are returned unchanged — safe to use on mixed route groups.
 */
class ApiResponseMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            // Safety net for exceptions that escape the global handler (e.g. thrown
            // by middleware earlier in the chain before $next() is called).
            report($e);

            return $this->error(
                message: config('app.debug') ? $e->getMessage() : 'An unexpected error occurred.',
                statusCode: 500,
                debug: config('app.debug') ? $this->debugInfo($e) : null,
            );
        }

        if (! $this->shouldWrap($response)) {
            return $response;
        }

        $statusCode = $response->getStatusCode();

        // 204 No Content (e.g. delete actions) — convert to 200 with null data
        // so the caller always receives the same envelope shape.
        if ($statusCode === 204) {
            return $this->success(null, 200);
        }

        // Error responses: read what Laravel's exception handler already put in
        // the body and reformat it into our envelope.
        if ($statusCode >= 400) {
            return $this->wrapErrorResponse($response, $statusCode);
        }

        // Success: unwrap Laravel's JsonResource outer `data` key so we don't
        // double-wrap — {"data": {...}} becomes {"data": {...}} not {"data": {"data": {...}}}.
        $body = json_decode($response->getContent(), true);

        $data = (is_array($body) && array_keys($body) === ['data'])
            ? $body['data']
            : $body;

        return $this->success($data, $statusCode);
    }

    // ─── Response builders ────────────────────────────────────────

    private function success(mixed $data, int $statusCode): JsonResponse
    {
        return new JsonResponse([
            'status' => 'success',
            'status_code' => $statusCode,
            'message' => 'Success.',
            'data' => $data,
        ], $statusCode);
    }

    private function error(
        string $message,
        int $statusCode,
        array $errors = [],
        ?array $debug = null,
    ): JsonResponse {
        $body = [
            'status' => 'error',
            'status_code' => $statusCode,
            'message' => $message,
            'errors' => $errors,
            'data' => null,
        ];

        if ($debug !== null) {
            $body['debug'] = $debug;
        }

        return new JsonResponse($body, $statusCode);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * Reformat an error response (4xx/5xx) that Laravel's exception handler
     * already converted from a thrown exception.
     *
     * We read the existing JSON body so the original message and errors array
     * are preserved rather than replaced with generic text.
     */
    private function wrapErrorResponse(Response $response, int $statusCode): JsonResponse
    {
        $body = json_decode($response->getContent(), true) ?? [];

        // 422: always use a generic message — field-level detail lives in $errors.
        // 404: ModelNotFoundException renders with an empty message string, so use ?: not ??.
        // 500 debug: pass through Laravel's original exception message.
        // 500 production: never leak internal details.
        $message = match (true) {
            $statusCode === 422 => 'The given data was invalid.',
            $statusCode === 404 => $body['message'] ?: 'Record not found.',
            $statusCode >= 500 && config('app.debug') => $body['message'] ?? 'An unexpected error occurred.',
            $statusCode >= 500 => 'An unexpected error occurred.',
            default => $body['message'] ?: 'An error occurred.',
        };

        $errors = $body['errors'] ?? [];

        // When APP_DEBUG=true, Laravel includes exception class, file, line, and trace
        // in the 500 response body. Mirror those into our debug block so callers
        // get the same detail they would from a raw Laravel response.
        $debug = null;
        if ($statusCode >= 500 && config('app.debug') && isset($body['exception'])) {
            $debug = [
                'exception' => class_basename($body['exception']),
                'file' => $body['file'] ?? null,
                'line' => $body['line'] ?? null,
                'trace' => array_slice($body['trace'] ?? [], 0, 10),
            ];
        }

        return $this->error($message, $statusCode, $errors, $debug);
    }

    /**
     * Determine whether the response should be wrapped in the envelope.
     *
     * JSON responses and empty-body responses (e.g. a route closure that
     * returns nothing) are always wrapped. Non-JSON responses that carry
     * content (HTML pages, file downloads) are passed through unchanged.
     */
    private function shouldWrap(Response $response): bool
    {
        if ($response instanceof JsonResponse) {
            return true;
        }

        if ($response->getStatusCode() === 204) {
            return true;
        }

        $contentType = $response->headers->get('Content-Type', '');
        $hasContent = $response->getContent() !== '' && $response->getContent() !== false;

        // Pass non-JSON responses that actually carry content through unchanged.
        if ($hasContent && ! str_contains($contentType, 'application/json')) {
            return false;
        }

        return true;
    }

    /**
     * Build a debug block from a throwable for inclusion in 500 responses.
     *
     * Only attached when APP_DEBUG=true — never expose stack traces in production.
     *
     * @return array{exception: string, file: string, line: int, trace: array<int, array{file: ?string, line: ?int, function: string}>}
     */
    private function debugInfo(\Throwable $e): array
    {
        return [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => collect($e->getTrace())
                ->take(10)
                ->map(fn (array $frame) => [
                    'file' => $frame['file'] ?? null,
                    'line' => $frame['line'] ?? null,
                    'function' => ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? ''),
                ])
                ->values()
                ->toArray(),
        ];
    }
}

<?php

namespace SchemaCraft\Visualizer;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SchemaCraftErrorHandler
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } catch (\Throwable $e) {
            $data = [
                'success' => false,
                'error' => true,
                'message' => $e->getMessage(),
            ];

            if (config('app.debug')) {
                $data['exception'] = get_class($e);
                $data['file'] = $e->getFile();
                $data['line'] = $e->getLine();
                $data['trace'] = collect($e->getTrace())
                    ->take(15)
                    ->map(fn (array $frame) => [
                        'file' => $frame['file'] ?? null,
                        'line' => $frame['line'] ?? null,
                        'function' => ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? ''),
                    ])
                    ->values()
                    ->toArray();
            }

            return new JsonResponse($data, 500);
        }
    }
}

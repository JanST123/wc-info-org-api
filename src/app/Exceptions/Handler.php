<?php

namespace App\Exceptions;

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler
{
    public static function configure(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(function (Request $request) {
            return true;
        });

        $exceptions->renderable(function (Throwable $e, Request $request) {
            $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;

            if ($e instanceof NotFoundHttpException) {
                $status = 404;
            } elseif ($e instanceof ValidationException) {
                $status = 400;
            }

            $response = [
                'message' => $e->getMessage() ?: 'An error occurred',
            ];

            if ($e instanceof ValidationException) {
                $response['errors'] = $e->errors();
            }

            if (config('app.debug')) {
                $response['exception'] = get_class($e);
                $response['file'] = $e->getFile();
                $response['line'] = $e->getLine();
                $response['trace'] = $e->getTrace();
            }

            return response()->json($response, $status);
        });

        $exceptions->reportable(function (Throwable $e) {
            // Errors are already logged by Laravel; Sentry is registered separately.
        });
    }
}

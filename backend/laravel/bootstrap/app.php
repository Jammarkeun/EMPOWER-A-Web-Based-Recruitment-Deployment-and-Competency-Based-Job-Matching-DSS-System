<?php

use App\Exceptions\InvalidTransitionException;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        /*
         * Sanctum's EnsureFrontendRequestsAreStateful is deliberately NOT
         * registered. It switches requests from a listed domain to cookie and
         * session authentication, which brings CSRF protection with it. This
         * API is consumed with Bearer tokens instead, so that middleware would
         * demand a CSRF token the client never holds and every request would
         * fail with "CSRF token mismatch".
         *
         * Token auth is also what the production topology needs: the frontend
         * is deployed to a different origin from the API, where cookie-based
         * session auth is considerably more fragile.
         */
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
            'portal' => \App\Http\Middleware\EnsurePortalUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /*
         * Every API failure is translated into the contract's error envelope.
         * Without this Laravel returns its own HTML or JSON shapes depending on
         * the exception, and the React client would need a special case for each.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => ApiResponse::error(
                    'The submitted data is invalid.',
                    422,
                    $e->errors()
                ),

                $e instanceof AuthenticationException => ApiResponse::error(
                    'Authentication required.',
                    401
                ),

                $e instanceof AuthorizationException => ApiResponse::error(
                    $e->getMessage() ?: 'You do not have permission to perform this action.',
                    403
                ),

                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::error(
                    'The requested record was not found.',
                    404
                ),

                // A lifecycle move that the transition map forbids. The request
                // was well-formed, but the record is not in a state where the
                // change makes sense, which is what 409 describes.
                $e instanceof InvalidTransitionException => ApiResponse::error(
                    $e->getMessage(),
                    409
                ),

                $e instanceof TooManyRequestsHttpException => ApiResponse::error(
                    'Too many attempts. Please wait a moment and try again.',
                    429
                ),

                /*
                 * Symfony's HttpException already carries the correct status, and
                 * it must be checked before the RuntimeException arm below
                 * because it extends RuntimeException. Laravel rewrites a denied
                 * authorisation into AccessDeniedHttpException, so without this
                 * a 403 would be reported to the client as a 400.
                 */
                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    $e->getMessage() ?: 'The request could not be completed.',
                    $e->getStatusCode()
                ),

                // Business rules raised from the service layer, such as
                // attempting to deploy an applicant with missing documents.
                $e instanceof RuntimeException => ApiResponse::error(
                    $e->getMessage(),
                    $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400
                ),

                default => null,
            };
        });
    })->create();

<?php

use App\Exceptions\InvalidTransitionException;
use App\Http\Responses\ApiResponse;
use App\Services\SessionLifetime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            'password.current' => \App\Http\Middleware\EnsurePasswordIsCurrent::class,
            'portal' => \App\Http\Middleware\EnsurePortalUser::class,
            'staff' => \App\Http\Middleware\EnsureStaffAccount::class,
            'coordinator' => \App\Http\Middleware\EnsureCoordinator::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /*
         * Database failures are logged without their bound values.
         *
         * Laravel's own report of a QueryException interpolates every binding
         * into the message - names, addresses, birth dates - and writes it to
         * the log. The statement with its placeholders and the SQLSTATE are
         * enough to diagnose the failure; the personal data is not needed.
         */
        $exceptions->report(function (QueryException $e) {
            Log::error('Database query failed', [
                'sqlstate' => $e->errorInfo[0] ?? (string) $e->getCode(),
                'driver_code' => $e->errorInfo[1] ?? null,
                'connection' => $e->getConnectionName(),
                'sql' => $e->getSql(),
                'path' => request()?->path(),
            ]);
        })->stop();

        /*
         * Every API failure is translated into the contract's error envelope.
         * Without this Laravel returns its own HTML or JSON shapes depending on
         * the exception, and the React client would need a special case for each.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            // Shown in place of any detail that belongs in the log, not the
            // browser: SQL, storage endpoints, file paths, library internals.
            $serverError = fn () => ApiResponse::error(
                'Something went wrong on our side. Please try again, and contact the '
                .'system administrator if it keeps happening.',
                500
            );

            return match (true) {
                $e instanceof ValidationException => ApiResponse::error(
                    'The submitted data is invalid.',
                    422,
                    $e->errors()
                ),

                /*
                 * A sign-in that ran out says so, with a code the screen reads
                 * to tell the person why they are back at the sign-in page. Any
                 * other refusal stays the plain message: an unknown or revoked
                 * token is told nothing about why.
                 */
                $e instanceof AuthenticationException => ($ended = $request->attributes->get(SessionLifetime::ENDED))
                    ? ApiResponse::error(app(SessionLifetime::class)->message($ended), 401, code: $ended)
                    : ApiResponse::error('Authentication required.', 401),

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

                /*
                 * Says how long to wait. "A moment" was the message even when
                 * the wait was most of an hour, which reads as broken rather
                 * than limited. Retry-After is passed on for any client that
                 * reads it.
                 */
                $e instanceof TooManyRequestsHttpException => ApiResponse::error(
                    ($wait = (int) ($e->getHeaders()['Retry-After'] ?? 0)) > 60
                        ? sprintf('Too many attempts. Please try again in %d minutes.', (int) ceil($wait / 60))
                        : sprintf('Too many attempts. Please try again in %d seconds.', max($wait, 1)),
                    429
                )->withHeaders(array_intersect_key($e->getHeaders(), array_flip(['Retry-After']))),

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

                /*
                 * A database error never reaches the browser.
                 *
                 * QueryException is a RuntimeException (through PDOException),
                 * so without this arm the one below returned its message - the
                 * SQL statement with every bound value - to whoever made the
                 * request, whatever APP_DEBUG said. Checked first, and in debug
                 * mode too: a demo runs with debug on.
                 */
                $e instanceof QueryException,
                $e instanceof PDOException => $serverError(),

                /*
                 * Business rules raised by EMPOWER's own services, such as
                 * attempting to deploy an applicant with missing documents.
                 *
                 * Only messages written in this application are shown. A
                 * RuntimeException thrown inside a library - the storage SDK,
                 * the filesystem, the spreadsheet writer - describes endpoints,
                 * object keys and paths, so it is logged and answered with the
                 * generic message instead.
                 */
                $e instanceof RuntimeException && str_starts_with($e->getFile(), app_path()) => ApiResponse::error(
                    $e->getMessage(),
                    is_int($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400
                ),

                $e instanceof RuntimeException => $serverError(),

                // Anything else: Laravel's detailed page while developing, the
                // generic envelope everywhere else.
                default => config('app.debug') ? null : $serverError(),
            };
        });
    })->create();

<?php

use App\Http\Middleware\LogApiRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            LogApiRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Sanitize all API error responses — no internal details leak
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return;
            }

            // Let Laravel handle ValidationException natively (422 + field errors)
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return;
            }

            if ($e instanceof TooManyRequestsException) {
                return response()->json(['error' => 'Terlalu banyak permintaan, coba lagi nanti.'], Response::HTTP_TOO_MANY_REQUESTS);
            }

            if ($e instanceof NotFoundHttpException) {
                return response()->json(['error' => 'Data tidak ditemukan.'], Response::HTTP_NOT_FOUND);
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                if (in_array($status, [400, 401, 403, 404, 405, 409, 429, 500, 503])) {
                    $messages = [
                        400 => 'Permintaan tidak valid.',
                        401 => 'Autentikasi gagal, token tidak valid atau kedaluwarsa.',
                        403 => 'Anda tidak memiliki akses untuk operasi ini.',
                        404 => 'Data tidak ditemukan.',
                        405 => 'Metode HTTP tidak diizinkan.',
                        409 => 'Konflik data, operasi ini sudah dilakukan sebelumnya.',
                        422 => 'Validasi data gagal, periksa kembali input Anda.',
                        429 => 'Terlalu banyak permintaan, tunggu beberapa saat.',
                        500 => 'Terjadi kesalahan internal, coba lagi nanti.',
                        503 => 'Layanan sedang tidak tersedia.',
                    ];
                    $msg = $messages[$status] ?? 'Terjadi kesalahan, coba lagi nanti.';
                    // Never leak internal details (SQL, file paths, class names)
                    return response()->json(['error' => $msg], $status);
                }
            }

            if ($e instanceof \Illuminate\Database\QueryException) {
                return response()->json(['error' => 'Terjadi kesalahan database.'], 500);
            }

            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return response()->json(['error' => 'Data tidak ditemukan.'], 404);
            }

            // Generic catch-all: never leak stack trace or file paths
            return response()->json([
                'error' => 'Terjadi kesalahan, coba lagi nanti.',
            ], 500);
        });
    })->create();

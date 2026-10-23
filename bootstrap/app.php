<?php

use App\Http\Middleware\EnsureCustomer;
use App\Http\Middleware\EnsureStaffRole;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'staff' => EnsureStaffRole::class,
            'customer' => EnsureCustomer::class,
        ]);

        // Check the actor before route model binding, so a wrong role gets 403
        // instead of learning from 404 vs 200 which records exist.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureStaffRole::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureCustomer::class);

        // There is no login page: API guests get a 401 JSON response instead of a redirect.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Don't leak model class names ("No query results for model [App\Models\...]").
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') && $e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json(['message' => 'Data tidak ditemukan.'], 404);
            }
        });
    })->create();

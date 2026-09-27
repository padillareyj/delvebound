<?php

use App\Exceptions\GameException;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RefuseDuringRun;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
        $middleware->alias([
            'run.guard' => RefuseDuringRun::class,
            'admin' => EnsureAdmin::class,
        ]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(GameException::class);
        $exceptions->render(function (GameException $e, Request $request) {
            return back()->with('error', $e->getMessage());
        });
        // TokenMismatchException is converted to a plain 419 before render callbacks run.
        $exceptions->respond(function (Response $response) {
            if ($response->getStatusCode() === 419) {
                return back()->with('error', 'Your session expired. Please try again.');
            }

            return $response;
        });
    })->create();

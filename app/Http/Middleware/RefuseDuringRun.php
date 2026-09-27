<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Alias "run.guard": the website is read-only while a simulation run is in progress (DESIGN.md §10.3 item 7). */
class RefuseDuringRun
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && DB::table('simulation_runs')->where('status', 'running')->exists()) {
            return back()->with('error', 'A simulation run is in progress, try again in a moment.');
        }
        if (! $request->isMethodSafe() && DB::table('arena_tournaments')->where('status', 'running')->exists()) {
            return back()->with('error', 'The arena is in progress, try again in a moment.');
        }

        return $next($request);
    }
}

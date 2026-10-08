<?php

namespace App\Http\Controllers;

use App\Services\DashboardStatistics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardStatistics $statistics) {}

    public function __invoke(Request $request): View
    {
        return view('dashboard', ['dashboard' => $this->statistics->generate($request->query())]);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->statistics->generate($request->query()))
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }
}

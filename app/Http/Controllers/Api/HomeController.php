<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HomeSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One payload for the whole cockpit, so Home makes a single request.
 *
 * The controller does no work beyond identifying the customer: what Home
 * shows is assembled by HomeSnapshotService from the domain services that
 * already own each figure.
 */
class HomeController extends Controller
{
    public function __construct(private readonly HomeSnapshotService $home) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->home->build($request->user(), $request),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ModelCapabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModelController extends Controller
{
    public function index(Request $request, ModelCapabilityService $capabilityService): JsonResponse
    {
        $search = $request->query('search');

        $models = $capabilityService->getAvailableModels($search);

        return response()->json([
            'data' => $models,
        ]);
    }
}

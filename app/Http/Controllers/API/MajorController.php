<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Major;
use Illuminate\Http\JsonResponse;

class MajorController extends Controller
{
    /**
     * All majors, for the account form's dropdown.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Major::orderBy('major_name')->get(['id', 'code', 'major_name']),
        ]);
    }
}

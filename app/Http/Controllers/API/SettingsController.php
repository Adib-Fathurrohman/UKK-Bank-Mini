<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global configuration. Every staff member can read it (the teller screen
 * needs the scanner mode and minimum balance); only administrators change it.
 */
class SettingsController extends Controller
{
    public function __construct(private SettingsService $settings)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings->all()]);
    }

    public function update(Request $request): JsonResponse
    {
        $values = $request->validate($this->settings->rules());

        return response()->json(['data' => $this->settings->update($values, $request->user())]);
    }
}

<?php

use Illuminate\Support\Facades\Route;

// The API has no web pages; this tells a visitor (or a monitor) what is here.
Route::get('/', fn () => response()->json([
    'name' => config('bank.name').' API',
    'status' => 'ok',
]));

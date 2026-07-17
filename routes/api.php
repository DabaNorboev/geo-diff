<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\RetryController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/sessions', [SessionController::class, 'store']);
Route::get('/sessions/{session}', [SessionController::class, 'show']);

Route::get('/sessions/{session}/export/points', [SessionController::class, 'exportPoints']);
Route::get('/sessions/{session}/export/districts', [SessionController::class, 'exportDistricts']);
Route::get('/sessions/{session}/export/pairs', [SessionController::class, 'exportPairs']);
Route::get('/sessions/{session}/coverage', [SessionController::class, 'coverage']);
Route::get('/sessions/{session}/report', [SessionController::class, 'report']);

Route::get('/sessions/{session}/retry-candidates', [RetryController::class, 'candidates']);
Route::post('/sessions/{session}/retry', [RetryController::class, 'retry']);

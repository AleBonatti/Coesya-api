<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{ApiController, AuthController, FamilyController, ChoreController, CategoryController};

Route::middleware('guest')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', function (Request $request) {
        $user = $request->user();
        if ($user) {
            $user->load('families');
        }
        return response()->json($user);
    });
    Route::resource('family', FamilyController::class);
    Route::post('family/{id}/uploadPhoto', [FamilyController::class, 'upload']);

    Route::resource('chores', ChoreController::class);
    Route::patch('chores/{id}/toggle', [ChoreController::class, 'toggle']);
    Route::post('chores/{id}/complete', [ChoreController::class, 'complete']);

    Route::resource('categories', CategoryController::class);

    Route::post('logout', [AuthController::class, 'logout']);
});

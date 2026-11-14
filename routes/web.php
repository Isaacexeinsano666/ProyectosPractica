<?php

use App\Http\Controllers\BoardController;
use App\Http\Controllers\ClassroomController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ClassroomController::class, 'home'])->name('home');
Route::redirect('/index.html', '/');
Route::post('/sesiones', [ClassroomController::class, 'create'])->middleware('throttle:classroom-create')->name('classroom.create');
Route::post('/unirse', [ClassroomController::class, 'join'])->middleware('throttle:classroom-join')->name('classroom.join');
Route::prefix('/sala/{room:code}')->scopeBindings()->group(function () {
    Route::get('/', [ClassroomController::class, 'show'])->name('classroom.show');
    Route::get('/estado', [ClassroomController::class, 'state'])->name('classroom.state');
    Route::put('/estado', [ClassroomController::class, 'update'])->name('classroom.update');
    Route::post('/finalizar', [ClassroomController::class, 'end'])->name('classroom.end');

    Route::get('/pizarra', [BoardController::class, 'show'])->name('board.show');
    Route::middleware('throttle:classroom-board')->group(function () {
        Route::post('/pizarra/trazos', [BoardController::class, 'storeStroke'])->name('board.strokes.store');
        Route::delete('/pizarra/trazos/{stroke}', [BoardController::class, 'destroyStroke'])->name('board.strokes.destroy');
        Route::delete('/pizarra', [BoardController::class, 'clear'])->name('board.clear');
        Route::post('/pizarra/mano', [BoardController::class, 'raiseHand'])->name('board.hand');
        Route::put('/pizarra/permisos/{member}', [BoardController::class, 'permissions'])->name('board.permissions');
        Route::put('/pizarra/libre', [BoardController::class, 'setOpen'])->name('board.open');
    });
});

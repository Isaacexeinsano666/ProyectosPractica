<?php

use App\Http\Controllers\ClassroomController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ClassroomController::class, 'home'])->name('home');
Route::redirect('/index.html', '/');
Route::post('/sesiones', [ClassroomController::class, 'create'])->middleware('throttle:classroom-create')->name('classroom.create');
Route::post('/unirse', [ClassroomController::class, 'join'])->middleware('throttle:classroom-join')->name('classroom.join');
Route::prefix('/sala/{room:code}')->group(function () {
    Route::get('/', [ClassroomController::class, 'show'])->name('classroom.show');
    Route::get('/estado', [ClassroomController::class, 'state'])->name('classroom.state');
    Route::put('/estado', [ClassroomController::class, 'update'])->name('classroom.update');
    Route::post('/finalizar', [ClassroomController::class, 'end'])->name('classroom.end');
});

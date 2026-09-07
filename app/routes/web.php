<?php

use App\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/media/piece/{piece}', [MediaController::class, 'piece'])->name('media.piece');
    Route::get('/media/upload/{upload}', [MediaController::class, 'upload'])->name('media.upload');
    Route::get('/media/cover/{campaign}', [MediaController::class, 'cover'])->name('media.cover');
    Route::get('/media/logo/{brand}', [MediaController::class, 'logo'])->name('media.logo');
});

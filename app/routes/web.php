<?php

use App\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/media/piece/{piece}', [MediaController::class, 'piece'])->name('media.piece');
    Route::get('/media/upload/{upload}/{filename?}', [MediaController::class, 'upload'])->name('media.upload')->where('filename', '[A-Za-z0-9._-]+');
    Route::get('/media/cover/{campaign}/{filename?}', [MediaController::class, 'cover'])->name('media.cover')->where('filename', '[A-Za-z0-9._-]+');
    Route::get('/media/logo/{brand}/{filename?}', [MediaController::class, 'logo'])->name('media.logo')->where('filename', '[A-Za-z0-9._-]+');
});

<?php
use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

// Endpoint for downloading a specific video link
Route::post('/download', [VideoController::class, 'getVideoInfo']);

// Endpoint for trending/featured videos (Vidmate style)
Route::get('/trending', [VideoController::class, 'getTrendingVideos']);

Route::get('/search', [VideoController::class, 'search']);
Route::post('/extract', [VideoController::class, 'extractInfo']);
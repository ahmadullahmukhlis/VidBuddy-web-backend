<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // This pulls the FRONTEND_URL from your .env file
    return redirect(env('FRONTEND_URL', 'http://localhost:3000'));
});

<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


Route::get('/debug-worker', function() {
    return response()->json([
        'worker_mode' => isset($_SERVER['FRANKENPHP_WORKER']),
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
        'frankenphp_version' => $_SERVER['FRANKENPHP_VERSION'] ?? 'unknown',
    ]);
});
Route::get('/count', function() {

    static $counter = 0;
    $counter++;
    return response()->json([
        'counter' => $counter,
    ]);
});
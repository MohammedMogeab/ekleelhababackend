<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__ . '/../vendor/autoload.php';

// Create Laravel application
$app = require_once __DIR__ . '/../bootstrap/app.php';

// Create kernel
$kernel = $app->make(Kernel::class);

// FrankenPHP worker loop
frankenphp_handle_request(function () use ($kernel) {
    // Capture the current request
    $request = Request::capture();

    // Handle and send the response
    $response = $kernel->handle($request);
    $response->send();

    // Terminate the kernel (important for middleware etc.)
    $kernel->terminate($request, $response);
});

<?php

use Illuminate\Support\Facades\Route;

/*
 * The frontend is a separate React application, so this backend serves only the
 * JSON API. This root route exists to give something meaningful to anyone who
 * opens the API host in a browser.
 */
Route::get('/', function () {
    return response()->json([
        'system' => 'EMPOWER — Recruitment and Deployment Management System',
        'organisation' => 'CDE Manpower Services',
        'api' => '/api/v1',
        'health' => '/up',
    ]);
});

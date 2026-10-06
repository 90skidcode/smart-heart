<?php

use Illuminate\Support\Facades\Route;

/*
 * The React admin panel is built into public/admin (see admin/vite.config.js).
 * Apache serves its files directly; this route returns index.html for deep links
 * such as /admin/participants/12 so the React router can take over.
 */
Route::get('/', fn () => redirect('/admin/'));

Route::get('/admin/{path?}', function () {
    $index = public_path('admin/index.html');
    abort_unless(is_file($index), 404, 'Admin panel not built. Run "npm run build" in the admin folder.');

    return response()->file($index, ['Cache-Control' => 'no-cache']);
})->where('path', '.*');

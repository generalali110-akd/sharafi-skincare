<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

Route::get('/', static fn () => response()->json([
    'service' => 'Sharafi Skin Care API',
    'status' => 'ok',
]));

if (app()->environment(['local', 'testing'])) {
    Route::get('/{path}', function (string $path = 'index.html') {
        $frontendRoot = realpath(base_path('../frontend'));
        abort_unless($frontendRoot, Response::HTTP_NOT_FOUND);

        $path = trim($path, '/');
        $target = $path === '' ? 'index.html' : $path;
        $file = realpath($frontendRoot.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $target));

        abort_unless($file && str_starts_with($file, $frontendRoot.DIRECTORY_SEPARATOR) && is_file($file), Response::HTTP_NOT_FOUND);

        return response()->file($file);
    })->where('path', '.*');
}

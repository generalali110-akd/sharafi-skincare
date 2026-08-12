<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('about:sharafi', function (): void {
    $this->info('Sharafi Skin Care backend');
})->purpose('Show the Sharafi backend identifier');

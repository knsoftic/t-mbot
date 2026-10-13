<?php

namespace App\Http\Controllers;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the uploaded logo without requiring `php artisan storage:link`.
 */
class BrandingLogoController extends Controller
{
    public function __invoke(SettingsService $settings): StreamedResponse
    {
        $path = $settings->get('general.logo_path');
        $disk = Storage::disk('public');

        abort_unless($path && $disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
        ]);
    }
}

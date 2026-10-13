<?php

namespace App\Http\Controllers;

use App\Models\Content;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams an uploaded media file to authenticated admins for previews.
 * Files live on a private disk and are never publicly reachable.
 */
class ContentMediaController extends Controller
{
    public function __invoke(Content $content): StreamedResponse
    {
        $disk = Storage::disk($content->media_disk ?: 'local');

        abort_unless($content->media_path && $disk->exists($content->media_path), 404);

        return $disk->response($content->media_path, $content->metadata['original_name'] ?? null, [
            'Content-Type' => $content->media_mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'",
            'Cache-Control' => 'private, max-age=3600',
        ], 'inline');
    }
}

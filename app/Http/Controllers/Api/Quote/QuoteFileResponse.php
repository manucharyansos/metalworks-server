<?php

namespace App\Http\Controllers\Api\Quote;

use App\Models\QuoteRequest;
use Illuminate\Support\Facades\Storage;

/** Streams one uploaded part of a request; the storage path never leaves the server. */
final class QuoteFileResponse
{
    public static function make(QuoteRequest $quote, string $item, bool $download)
    {
        abort_unless(ctype_digit($item), 404);
        $file = $quote->items()->whereKey((int) $item)->firstOrFail();
        $disk = Storage::disk('private');
        abort_unless($disk->exists($file->path), 404, 'Ֆայլը չի գտնվել։');
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        return $download
            ? $disk->download($file->path, $file->original_name, $headers)
            : $disk->response($file->path, $file->original_name, $headers);
    }
}

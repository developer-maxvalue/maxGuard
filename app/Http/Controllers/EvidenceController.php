<?php

namespace App\Http\Controllers;

use App\Models\EvidenceItem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EvidenceController extends Controller
{
    public function download(EvidenceItem $evidence): StreamedResponse
    {
        abort_if(auth()->id() !== null && $evidence->finding->website->user_id !== auth()->id(), 403);
        abort_unless(Storage::disk($evidence->disk)->exists($evidence->path), 404);
        $stream = Storage::disk($evidence->disk)->readStream($evidence->path);
        abort_if($stream === false, 404);

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, basename($evidence->path), [
            'Content-Type' => $evidence->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

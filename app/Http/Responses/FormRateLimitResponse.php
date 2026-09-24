<?php

namespace App\Http\Responses;

use App\Models\FormIntake;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FormRateLimitResponse
{
    public static function make(Request $request, array $headers): Response
    {
        $retryAfter = max(1, (int) ($headers['Retry-After'] ?? 60));
        $message = 'Permintaan terlalu sering. Coba lagi dalam '.$retryAfter.' detik. Jika email sudah diterima, gunakan tautan di email tersebut.';
        $headers = [...$headers, 'Retry-After' => $retryAfter, 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'];
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'retry_after' => $retryAfter], 429, $headers);
        }
        $intake = $request->route('intake');
        $backUrl = $intake instanceof FormIntake ? route('forms.show', $intake->slug) : null;

        return response()->view('forms.rate-limited', compact('message', 'retryAfter', 'backUrl'), 429, $headers);
    }
}

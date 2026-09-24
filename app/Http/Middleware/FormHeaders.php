<?php

namespace App\Http\Middleware;

use App\Services\CandidateFormService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FormHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('candidate_forms.enabled'), 404);
        abort_unless(CandidateFormService::available(), 503, 'Formulir belum diaktifkan. Hubungi HR.');
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}

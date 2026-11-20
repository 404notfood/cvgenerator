<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class ProtectCvWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);
        $embeddedPreview = $request->routeIs('candidatures.download') && $request->boolean('preview')
            && $request->boolean('embedded') && $response->isSuccessful()
            && $response->headers->get('Content-Type') === 'text/html; charset=UTF-8';
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', $embeddedPreview ? 'SAMEORIGIN' : 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if (! $request->routeIs('home') || ! config('cvgenerator.indexable')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        if ($request->user() || ! $request->routeIs('home', 'seo.*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        if (! Vite::isRunningHot()) {
            $ancestors = $embeddedPreview ? "'self'" : "'none'";
            $response->headers->set('Content-Security-Policy',
                "default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; object-src 'none'; frame-ancestors $ancestors; base-uri 'none'; form-action 'self'");
        }
        if ($request->isSecure() && app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}

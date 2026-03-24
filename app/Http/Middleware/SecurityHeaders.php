<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Previne MIME-type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Previne clickjacking — permite iframe doar din același origin (Filament modals)
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Nu trimite Referer complet către domenii externe
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Dezactivează feature-uri browser neutilizate
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');

        // Content-Security-Policy
        //
        // 'unsafe-inline' (script-src):  Filament v3 injectează inline <script> via renderHook
        //                                (sidebar CSS, mobile collapse, QR scanner init)
        // 'unsafe-eval'   (script-src):  Alpine.js v3 parsează expresii x-data via new Function()
        // 'unsafe-inline' (style-src):   Filament injectează ~600 linii CSS inline via renderHook
        //
        // CDN-uri permise:
        //   cdn.jsdelivr.net       — @zxing/library (QR scanner), SortableJS
        //   cdnjs.cloudflare.com   — Fabric.js (editor template grafic)
        //   fonts.googleapis.com   — Google Fonts stylesheet
        //   fonts.bunny.net        — font alternativ (welcome page)
        //   fonts.gstatic.com      — fișiere font Google
        //
        // img-src https:  — imaginile produselor pot veni de pe domenii externe (WooCommerce, furnizori)
        //
        // NOTE viitor: dacă se adaugă Laravel Broadcasting (Echo + Reverb/Pusher),
        //   adaugă în connect-src: wss://your-domain.com sau wss://ws.pusherapp.com

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' cdn.jsdelivr.net cdnjs.cloudflare.com",
            "style-src 'self' 'unsafe-inline' fonts.googleapis.com fonts.bunny.net",
            "font-src 'self' fonts.gstatic.com fonts.bunny.net data:",
            "img-src 'self' data: blob: https:",
            "connect-src 'self'",
            "frame-src 'self'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}

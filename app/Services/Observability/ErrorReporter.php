<?php

namespace App\Services\Observability;

use App\Models\ErrorEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Captează excepțiile neprinse într-un tabel agregat (error_events) și trimite
 * o alertă email rate-limited. Self-contained — fără serviciu extern.
 */
class ErrorReporter
{
    private const NOTIFY_EMAIL = 'codrut@ikonia.ro';

    /** Excepții „de zgomot" care nu ne interesează ca erori de aplicație. */
    private const IGNORED = [
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class,
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Illuminate\Validation\ValidationException::class,
        \Illuminate\Session\TokenMismatchException::class,
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
        \Livewire\Exceptions\PublicPropertyNotFoundException::class,
    ];

    public function capture(Throwable $e): void
    {
        try {
            foreach (self::IGNORED as $class) {
                if ($e instanceof $class) {
                    return;
                }
            }
            // HttpException 4xx (inclusiv 419) — nu ne interesează
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                && $e->getStatusCode() < 500) {
                return;
            }

            $fingerprint = $this->fingerprint($e);
            $now         = now();

            $existing = ErrorEvent::where('fingerprint', $fingerprint)->first();

            if ($existing) {
                // dacă fusese rezolvată și reapare, pornim un ciclu nou (opened_at) și ștergem resolved_at
                $reopening = $existing->status === 'resolved';
                $existing->increment('count');
                $existing->update([
                    'last_seen_at' => $now,
                    'message'      => \Illuminate\Support\Str::limit($e->getMessage(), 1000),
                    'url'          => $this->url(),
                    'method'       => request()?->method(),
                    'user_id'      => auth()->id(),
                    // dacă fusese rezolvată și reapare, o redeschidem
                    'status'       => $existing->status === 'ignored' ? 'ignored' : 'open',
                ] + ($reopening ? ['opened_at' => $now, 'resolved_at' => null] : []));
                $event = $existing;
                $isNew = false;
            } else {
                $event = ErrorEvent::create([
                    'fingerprint'     => $fingerprint,
                    'exception_class' => get_class($e),
                    'message'         => \Illuminate\Support\Str::limit($e->getMessage(), 1000),
                    'file'            => $e->getFile(),
                    'line'            => $e->getLine(),
                    'trace'           => \Illuminate\Support\Str::limit($e->getTraceAsString(), 8000),
                    'url'             => $this->url(),
                    'method'          => request()?->method(),
                    'context'         => $this->context(),
                    'user_id'         => auth()->id(),
                    'count'           => 1,
                    'status'          => 'open',
                    'first_seen_at'   => $now,
                    'last_seen_at'    => $now,
                    'opened_at'       => $now,
                ]);
                $isNew = true;
            }

            $this->maybeNotify($event, $e, $isNew);
        } catch (Throwable) {
            // Observabilitatea nu trebuie să spargă niciodată requestul.
        }
    }

    private function fingerprint(Throwable $e): string
    {
        // Clasă + primul cadru din trace (fișier:linie) — stabil chiar dacă mesajul variază.
        $frame = $e->getFile() . ':' . $e->getLine();

        return hash('sha256', get_class($e) . '|' . $frame);
    }

    private function url(): ?string
    {
        try {
            return request()?->fullUrl();
        } catch (Throwable) {
            return null;
        }
    }

    private function context(): string
    {
        if (app()->runningInConsole()) {
            return app()->runningUnitTests() ? 'test' : 'console';
        }

        return 'web';
    }

    /** Alertă email: o dată la prima apariție, apoi max o dată/oră per fingerprint. */
    private function maybeNotify(ErrorEvent $event, Throwable $e, bool $isNew): void
    {
        if ($event->status === 'ignored') {
            return;
        }

        $key = 'errmail:' . $event->fingerprint;
        if (! Cache::add($key, 1, now()->addHour())) {
            return; // deja notificat în ultima oră
        }

        try {
            $subject = ($isNew ? '[ERP][EROARE NOUĂ] ' : '[ERP][EROARE] ')
                . class_basename($e) . ' — ' . \Illuminate\Support\Str::limit($e->getMessage(), 80);

            $body = '<p><b>' . e(get_class($e)) . '</b></p>'
                . '<p>' . e(\Illuminate\Support\Str::limit($e->getMessage(), 400)) . '</p>'
                . '<p><code>' . e($e->getFile()) . ':' . $e->getLine() . '</code></p>'
                . '<p>Context: ' . e($event->context) . ($event->url ? ' · ' . e($event->url) : '') . '</p>'
                . '<p>Apariții: ' . $event->count . '</p>'
                . '<pre style="font-size:11px;background:#f6f8fa;padding:10px;border-radius:6px;overflow:auto;">'
                . e(\Illuminate\Support\Str::limit($e->getTraceAsString(), 2500)) . '</pre>';

            Mail::html($body, function ($m) use ($subject) {
                $m->to(self::NOTIFY_EMAIL)->subject($subject);
            });
        } catch (Throwable) {
            // fără email = nu blocăm
        }
    }
}

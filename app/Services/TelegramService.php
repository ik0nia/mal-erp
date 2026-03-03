<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private string $token;
    private string $chatId;

    public function __construct()
    {
        $this->token  = AppSetting::getEncrypted(AppSetting::KEY_TELEGRAM_BOT_TOKEN) ?? '';
        $this->chatId = AppSetting::get(AppSetting::KEY_TELEGRAM_CHAT_ID, '');
    }

    public function isConfigured(): bool
    {
        return filled($this->token) && filled($this->chatId);
    }

    /**
     * Trimite un mesaj text simplu (suportă HTML basic: <b>, <i>, <code>, <a>).
     */
    public function send(string $message): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::timeout(8)->post(
                "https://api.telegram.org/bot{$this->token}/sendMessage",
                [
                    'chat_id'                  => $this->chatId,
                    'text'                     => $message,
                    'parse_mode'               => 'HTML',
                    'disable_web_page_preview' => true,
                ]
            );

            if (! $response->successful()) {
                Log::warning('TelegramService: trimitere eșuată', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('TelegramService: excepție', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Notificare lead nou din chat widget.
     */
    public function sendLeadNotification(
        ?string $email,
        ?string $phone,
        bool    $wantsSpecialist,
        ?string $interestedIn,
        ?string $summary,
        string  $sessionId,
    ): bool {
        $lines = [];
        $lines[] = '🔔 <b>Lead nou — Malinco Chat</b>';
        $lines[] = '';

        if ($email) {
            $lines[] = "📧 <a href=\"mailto:{$email}\">{$email}</a>";
        }
        if ($phone) {
            $lines[] = "📞 <a href=\"tel:{$phone}\">{$phone}</a>";
        }
        if ($wantsSpecialist) {
            $lines[] = '⭐ <b>Vrea specialist</b>';
        }
        if ($interestedIn) {
            $lines[] = '';
            $lines[] = "🛒 <b>Interes:</b> {$interestedIn}";
        }
        if ($summary) {
            $lines[] = '';
            $lines[] = "💬 <i>{$summary}</i>";
        }

        $lines[] = '';
        $lines[] = '🕐 ' . now()->format('d.m.Y H:i');
        $lines[] = "🔗 <a href=\"https://erp.malinco.ro/chat-logs-page\">Vezi în ERP</a>";

        return $this->send(implode("\n", $lines));
    }
}

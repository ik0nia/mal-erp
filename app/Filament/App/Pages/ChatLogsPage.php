<?php

namespace App\Filament\App\Pages;

use App\Models\AppSetting;
use App\Models\ChatContact;
use App\Models\ChatLog;
use Filament\Actions\Action;
use App\Services\TelegramService;
use Filament\Forms\Components\ColorPicker;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChatLogsPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationLabel = 'Chat Clienți';
    protected static string|\UnitEnum|null $navigationGroup = 'Comunicare';
    protected static ?int    $navigationSort  = 10;
    protected string  $view            = 'filament.app.pages.chat-logs';

    public static function shouldRegisterNavigation(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    public static function canAccess(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test_telegram')
                ->label('Test Telegram')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->action(function () {
                    $telegram = app(TelegramService::class);
                    if (! $telegram->isConfigured()) {
                        Notification::make()->title('Telegram neconfigurat')->body('Adaugă Bot Token și Chat ID în Setări Widget.')->warning()->send();
                        return;
                    }
                    $ok = $telegram->send("✅ <b>Test conexiune Malinco ERP</b>\n\nTelegram funcționează corect! Vei primi notificări aici când apar lead-uri noi din chat.");
                    $ok
                        ? Notification::make()->title('Mesaj trimis!')->body('Verifică grupul Telegram.')->success()->send()
                        : Notification::make()->title('Eroare trimitere')->body('Verifică token-ul și chat ID-ul.')->danger()->send();
                }),

            Action::make('chat_settings')
                ->label('Setări Widget')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->modalHeading('Setări chatbot Malinco')
                ->modalWidth('lg')
                ->fillForm(fn () => [
                    'primary_color' => AppSetting::get(AppSetting::KEY_CHAT_PRIMARY_COLOR, '#e65100'),
                    'bot_name'      => AppSetting::get(AppSetting::KEY_CHAT_BOT_NAME,      'Malinco'),
                    'subtitle'      => AppSetting::get(AppSetting::KEY_CHAT_SUBTITLE,      'Asistent virtual'),
                    'welcome_msg'   => AppSetting::get(AppSetting::KEY_CHAT_WELCOME_MSG,   'Bună ziua! Cu ce vă pot ajuta?'),
                    'telegram_token'        => AppSetting::getEncrypted(AppSetting::KEY_TELEGRAM_BOT_TOKEN) ?? '',
                    'telegram_chat_id'      => AppSetting::get(AppSetting::KEY_TELEGRAM_CHAT_ID, ''),
                    'max_cost_per_session'  => AppSetting::get(AppSetting::KEY_CHAT_MAX_COST_PER_SESSION, '0'),
                ])
                ->form([
                    Section::make('Aspect widget')
                        ->schema([
                            ColorPicker::make('primary_color')
                                ->label('Culoare principală')
                                ->helperText('Culoarea butonului, header-ului și prețurilor din widget.')
                                ->required(),
                            TextInput::make('bot_name')
                                ->label('Numele botului')
                                ->maxLength(50)
                                ->required(),
                            TextInput::make('subtitle')
                                ->label('Subtitlu')
                                ->helperText('"Asistent virtual", "Online acum" etc.')
                                ->maxLength(80),
                            Textarea::make('welcome_msg')
                                ->label('Mesaj de bun-venit')
                                ->rows(3)
                                ->maxLength(300),
                        ]),

                    Section::make('Notificări Telegram')
                        ->description('Primești un mesaj pe Telegram de fiecare dată când un vizitator lasă date de contact.')
                        ->schema([
                            TextInput::make('telegram_token')
                                ->label('Bot Token')
                                ->helperText('De la @BotFather pe Telegram. Format: 7123456789:AAF...')
                                ->password()
                                ->revealable()
                                ->maxLength(200),
                            TextInput::make('telegram_chat_id')
                                ->label('Chat ID grup')
                                ->helperText('ID-ul grupului Telegram unde botul va trimite notificările. Format: -100xxxxxxxxx')
                                ->maxLength(50),
                        ]),

                    Section::make('Limite AI')
                        ->description('Controlează costul maxim per sesiune de chat. La depășirea limitei, botul va redirecționa clientul la telefon.')
                        ->schema([
                            TextInput::make('max_cost_per_session')
                                ->label('Cost maxim per sesiune (USD)')
                                ->helperText('Ex: 0.05 = maxim 5 cenți per sesiune (~25 mesaje). Lasă 0 pentru fără limită.')
                                ->numeric()
                                ->minValue(0)
                                ->step(0.01)
                                ->suffix('USD')
                                ->placeholder('0'),
                        ]),
                ])
                ->action(function (array $data) {
                    AppSetting::set(AppSetting::KEY_CHAT_PRIMARY_COLOR, $data['primary_color']);
                    AppSetting::set(AppSetting::KEY_CHAT_BOT_NAME,      $data['bot_name']);
                    AppSetting::set(AppSetting::KEY_CHAT_SUBTITLE,      $data['subtitle'] ?? '');
                    AppSetting::set(AppSetting::KEY_CHAT_WELCOME_MSG,   $data['welcome_msg'] ?? '');

                    if (filled($data['telegram_token'] ?? '')) {
                        AppSetting::setEncrypted(AppSetting::KEY_TELEGRAM_BOT_TOKEN, $data['telegram_token']);
                    }
                    AppSetting::set(AppSetting::KEY_TELEGRAM_CHAT_ID, $data['telegram_chat_id'] ?? '');
                    AppSetting::set(AppSetting::KEY_CHAT_MAX_COST_PER_SESSION, $data['max_cost_per_session'] ?? '0');

                    Notification::make()->title('Setări salvate')->success()->send();
                }),
        ];
    }

    /**
     * Sesiuni cu mesajele lor pre-încărcate (evită N+1).
     * Returnează max 50 sesiuni recente.
     */
    public function getSessions(): Collection
    {
        // Agregat sesiuni
        $sessions = DB::table('chat_logs')
            ->select([
                'session_id',
                'ip_address',
                DB::raw('MIN(created_at) as started_at'),
                DB::raw('MAX(created_at) as last_at'),
                DB::raw('COUNT(*) as total_messages'),
                DB::raw('SUM(CASE WHEN role = "user" THEN 1 ELSE 0 END) as user_messages'),
                DB::raw('MAX(CASE WHEN role = "assistant" AND has_products = 1 THEN 1 ELSE 0 END) as had_products'),
                DB::raw('SUM(COALESCE(input_tokens, 0))  as total_input_tokens'),
                DB::raw('SUM(COALESCE(output_tokens, 0)) as total_output_tokens'),
            ])
            ->groupBy('session_id', 'ip_address')
            ->orderByDesc('last_at')
            ->limit(50)
            ->get();

        if ($sessions->isEmpty()) {
            return collect();
        }

        $sessionIds = $sessions->pluck('session_id')->toArray();

        // Mesaje pentru toate sesiunile — un singur query
        $allMessages = ChatLog::whereIn('session_id', $sessionIds)
            ->orderBy('created_at')
            ->get()
            ->groupBy('session_id');

        // Date de contact colectate — un singur query (cu user-ul care a marcat contactul)
        $contacts = ChatContact::whereIn('session_id', $sessionIds)
            ->with('contactedByUser:id,name')
            ->get()
            ->keyBy('session_id');

        return $sessions->map(function ($row) use ($allMessages, $contacts) {
            $msgs = $allMessages->get($row->session_id, collect());

            // Primul mesaj al clientului ca preview
            $first = $msgs->where('role', 'user')->first();
            $row->first_message  = $first ? mb_substr($first->content, 0, 90) : '—';
            $row->first_page_url   = $first?->page_url;
            $row->first_page_title = $first?->page_title;
            $row->messages         = $msgs->values();

            // Date contact
            $contact = $contacts->get($row->session_id);
            $row->contact_email      = $contact?->email;
            $row->contact_phone      = $contact?->phone;
            $row->wants_specialist   = (bool) ($contact?->wants_specialist ?? false);
            $row->summary            = $contact?->summary;
            $row->interested_in      = $contact?->interested_in;
            $row->contacted_at       = $contact?->contacted_at;
            $row->contacted_by_name  = $contact?->contactedByUser?->name;

            return $row;
        });
    }

    /**
     * Lead-uri necontactate: sesiuni cu date contact (email/telefon) dar fără contacted_at.
     */
    public function getUncontactedLeads(): Collection
    {
        return ChatContact::whereNull('contacted_at')
            ->where(function ($q) {
                $q->whereNotNull('email')->orWhereNotNull('phone');
            })
            ->with('contactedByUser:id,name')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Marchează un lead ca și contactat de userul curent.
     */
    public function markAsContacted(string $sessionId): void
    {
        ChatContact::where('session_id', $sessionId)->update([
            'contacted_at' => now(),
            'contacted_by' => auth()->id(),
        ]);

        Notification::make()
            ->title('Lead marcat ca și contactat')
            ->success()
            ->send();
    }

    /**
     * Calculează costul în USD.
     * Haiku: $0.80/MTok input, $4.00/MTok output.
     */
    public function formatCost(int $inputTokens, int $outputTokens): ?string
    {
        if ($inputTokens === 0 && $outputTokens === 0) {
            return null;
        }

        $usd = ($inputTokens * 0.80 + $outputTokens * 4.00) / 1_000_000;

        if ($usd < 0.001) {
            return number_format($usd * 100, 4) . '¢';
        }

        return '$' . number_format($usd, 4);
    }

    public function getTotalSessions(): int
    {
        return (int) DB::table('chat_logs')->distinct('session_id')->count('session_id');
    }

    public function getTodayMessages(): int
    {
        return ChatLog::whereDate('created_at', today())->count();
    }

    public function getTodaySessions(): int
    {
        return (int) DB::table('chat_logs')
            ->whereDate('created_at', today())
            ->distinct('session_id')
            ->count('session_id');
    }
}

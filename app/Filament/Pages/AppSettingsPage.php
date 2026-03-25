<?php

namespace App\Filament\Pages;

use App\Models\AppSetting;
use App\Models\IntegrationConnection;
use App\Services\WooCommerce\WooClient;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class AppSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'Setări aplicație';
    protected static ?string $title           = 'Setări aplicație';
    protected static ?int    $navigationSort  = 100;
    protected string  $view            = 'filament.pages.app-settings';

    public ?string $brand_name = null;
    public ?string $logo_path  = null;

    public function mount(): void
    {
        $this->form->fill([
            'company_name'      => AppSetting::get(AppSetting::KEY_COMPANY_NAME, 'SC Malinco SRL'),
            'company_cui'       => AppSetting::get(AppSetting::KEY_COMPANY_CUI),
            'company_reg_com'   => AppSetting::get(AppSetting::KEY_COMPANY_REG_COM),
            'company_address'   => AppSetting::get(AppSetting::KEY_COMPANY_ADDRESS),
            'company_email'     => AppSetting::get(AppSetting::KEY_COMPANY_EMAIL),
            'company_phone'     => AppSetting::get(AppSetting::KEY_COMPANY_PHONE),
            'brand_name'        => AppSetting::get(AppSetting::KEY_BRAND_NAME, 'Malinco ERP'),
            'logo_path'         => AppSetting::get(AppSetting::KEY_LOGO_PATH) ? [AppSetting::get(AppSetting::KEY_LOGO_PATH)] : [],
            'imap_host'         => AppSetting::get(AppSetting::KEY_IMAP_HOST, 'mail.malinco.ro'),
            'imap_port'         => AppSetting::get(AppSetting::KEY_IMAP_PORT, '993'),
            'imap_encryption'   => AppSetting::get(AppSetting::KEY_IMAP_ENCRYPTION, 'ssl'),
            'imap_username'     => AppSetting::get(AppSetting::KEY_IMAP_USERNAME),
            'imap_password'     => null,
            'anthropic_api_key'    => null, // nu preîncărcăm cheia în formular
            'woo_plugin_api_key'   => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Branding')
                    ->schema([
                        TextInput::make('brand_name')
                            ->label('Nume aplicație')
                            ->placeholder('Malinco ERP')
                            ->maxLength(100),

                        FileUpload::make('logo_path')
                            ->label('Logo (recomandat: PNG transparent, minim 200px înălțime)')
                            ->image()
                            ->disk('public')
                            ->directory('brand')
                            ->imagePreviewHeight('80')
                            ->maxSize(2048),
                    ]),

                Section::make('Email inbox (IMAP)')
                    ->description('Conexiune read-only — inbox-ul din Outlook/webmail rămâne complet nemodificat.')
                    ->schema([
                        TextInput::make('imap_host')
                            ->label('Server IMAP')
                            ->placeholder('mail.malinco.ro')
                            ->maxLength(255),
                        TextInput::make('imap_port')
                            ->label('Port')
                            ->placeholder('993')
                            ->maxLength(10),
                        Select::make('imap_encryption')
                            ->label('Criptare')
                            ->options([
                                'ssl'     => 'SSL (port 993)',
                                'tls'     => 'TLS (port 143)',
                                'starttls'=> 'STARTTLS',
                                'notls'   => 'Fără criptare',
                            ])
                            ->native(false),
                        TextInput::make('imap_username')
                            ->label('Adresă email')
                            ->placeholder('office@malinco.ro')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('imap_password')
                            ->label('Parolă')
                            ->password()
                            ->revealable()
                            ->placeholder('Lasă gol pentru a păstra parola existentă')
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Section::make('WooCommerce Plugin Bridge')
                    ->description('Cheia secretă de autentificare pentru plugin-ul malinco-erp-bridge instalat pe WordPress.')
                    ->schema([
                        TextInput::make('woo_plugin_api_key')
                            ->label('API Key plugin')
                            ->password()
                            ->revealable()
                            ->placeholder('Lasă gol pentru a păstra cheia existentă')
                            ->helperText('Trebuie să coincidă cu cheia setată în WP Admin → Settings → ERP Bridge')
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ]),

                Section::make('Inteligență Artificială (Claude)')
                    ->description('Cheia API Anthropic pentru procesarea automată a emailurilor.')
                    ->schema([
                        TextInput::make('anthropic_api_key')
                            ->label('Anthropic API Key')
                            ->password()
                            ->revealable()
                            ->placeholder('sk-ant-... (lasă gol pentru a păstra cheia existentă)')
                            ->helperText('Generează cheia pe console.anthropic.com → API Keys')
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public array $data = [];

    public function save(): void
    {
        $state = $this->form->getState();

        AppSetting::set(AppSetting::KEY_BRAND_NAME, $state['brand_name'] ?? null);

        $logoPaths = $state['logo_path'] ?? [];
        $logoPath  = is_array($logoPaths) ? (reset($logoPaths) ?: null) : ($logoPaths ?: null);
        AppSetting::set(AppSetting::KEY_LOGO_PATH, $logoPath);

        // IMAP
        AppSetting::set(AppSetting::KEY_IMAP_HOST,       $state['imap_host'] ?? null);
        AppSetting::set(AppSetting::KEY_IMAP_PORT,       $state['imap_port'] ?? null);
        AppSetting::set(AppSetting::KEY_IMAP_ENCRYPTION, $state['imap_encryption'] ?? null);
        AppSetting::set(AppSetting::KEY_IMAP_USERNAME,   $state['imap_username'] ?? null);
        if (filled($state['imap_password'] ?? null)) {
            AppSetting::setEncrypted(AppSetting::KEY_IMAP_PASSWORD, $state['imap_password']);
        }

        // AI
        if (filled($state['anthropic_api_key'] ?? null)) {
            AppSetting::setEncrypted(AppSetting::KEY_ANTHROPIC_API_KEY, $state['anthropic_api_key']);
        }

        // WooCommerce Plugin Bridge
        if (filled($state['woo_plugin_api_key'] ?? null)) {
            AppSetting::setEncrypted(AppSetting::KEY_WOO_PLUGIN_API_KEY, $state['woo_plugin_api_key']);
        }

        Notification::make()
            ->title('Setări salvate')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')
                ->label('Salvează setările')
                ->action('save')
                ->color('primary'),
        ];
    }
}

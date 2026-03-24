<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Concerns\HasDynamicNavSort;
use App\Jobs\AnalyzeReferenceImagesJob;
use App\Jobs\AnalyzeSocialStyleJob;
use App\Jobs\FetchSocialPostsJob;
use App\Models\SocialAccount;
use App\Models\AppSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;

class SocialAccountsPage extends Page
{
    use HasDynamicNavSort;

    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-cog-6-tooth';

    /** Căile imaginilor de referință selectate pentru generare template-uri */
    public array $selectedReferences = [];
    protected static ?string $navigationLabel = 'Conturi';
    protected static string|\UnitEnum|null $navigationGroup = 'Social Media';
    protected static ?int    $navigationSort  = 2;
    protected string  $view            = 'filament.app.pages.social-accounts';

    public static function shouldRegisterNavigation(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    public static function canAccess(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    public function getAccounts()
    {
        return SocialAccount::orderBy('name')->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add_account')
                ->label('Adaugă cont')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->form([
                    TextInput::make('name')
                        ->label('Nume cont (ex: Malinco Facebook)')
                        ->required(),
                    TextInput::make('account_id')
                        ->label('Facebook Page ID')
                        ->required(),
                    TextInput::make('access_token')
                        ->label('Page Access Token')
                        ->password()
                        ->revealable()
                        ->required(),
                    DateTimePicker::make('token_expires_at')
                        ->label('Expiră la (opțional)'),
                ])
                ->action(function (array $data) {
                    $account = new SocialAccount([
                        'name'             => $data['name'],
                        'platform'         => 'facebook',
                        'account_id'       => $data['account_id'],
                        'token_expires_at' => $data['token_expires_at'] ?? null,
                        'is_active'        => true,
                    ]);
                    $account->setAccessToken($data['access_token']);
                    $account->save();

                    Notification::make()->title('Cont adăugat')->success()->send();
                }),

            Action::make('upload_references')
                ->label('Încarcă referințe vizuale')
                ->icon('heroicon-o-photo')
                ->color('success')
                ->form([
                    FileUpload::make('images')
                        ->label('Imagini referință stil (PNG/JPG/WEBP)')
                        ->image()
                        ->multiple()
                        ->maxFiles(20)
                        ->maxSize(5120)
                        ->disk('public')
                        ->directory('social/style-references')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->helperText('Încarcă imagini cu stilul grafic dorit. Gemini le va folosi ca inspirație vizuală.'),
                ])
                ->action(function (array $data) {
                    $count = count($data['images'] ?? []);
                    Notification::make()
                        ->title("{$count} imagine(i) încărcate")
                        ->body('Vor fi folosite ca referință vizuală la generarea postărilor.')
                        ->success()
                        ->send();
                }),

            Action::make('save_gemini_key')
                ->label('Setează Gemini API Key')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->form([
                    TextInput::make('gemini_api_key')
                        ->label('Gemini API Key')
                        ->password()
                        ->revealable()
                        ->required(),
                    TextInput::make('meta_app_id')
                        ->label('Meta App ID (opțional)')
                        ->default(AppSetting::get('meta_app_id')),
                    TextInput::make('meta_app_secret')
                        ->label('Meta App Secret (opțional — pentru token permanent)')
                        ->password()
                        ->revealable(),
                ])
                ->action(function (array $data) {
                    AppSetting::setEncrypted('gemini_api_key', $data['gemini_api_key']);
                    if (filled($data['meta_app_id'])) {
                        AppSetting::set('meta_app_id', $data['meta_app_id']);
                    }
                    if (filled($data['meta_app_secret'])) {
                        AppSetting::setEncrypted('meta_app_secret', $data['meta_app_secret']);
                    }
                    Notification::make()->title('Setări salvate')->success()->send();
                }),
        ];
    }

    public function fetchPosts(int $accountId): void
    {
        FetchSocialPostsJob::dispatch($accountId);
        Notification::make()->title('Fetch pornit')->body('Postările istorice se descarcă în background.')->info()->send();
    }

    public function analyzeStyle(int $accountId): void
    {
        AnalyzeSocialStyleJob::dispatch($accountId);
        Notification::make()->title('Analiză pornită')->body('Stilul paginii va fi analizat în background.')->info()->send();
    }

    public function updateToken(int $accountId, string $token, ?string $expiresAt): void
    {
        $account = SocialAccount::find($accountId);
        if (! $account) {
            return;
        }
        $account->setAccessToken($token);
        $account->token_expires_at = $expiresAt ? \Carbon\Carbon::parse($expiresAt) : null;
        $account->save();
        Notification::make()->title('Token actualizat')->success()->send();
    }

    public function getStyleReferences(): array
    {
        $files = Storage::disk('public')->files('social/style-references');
        return array_map(fn ($f) => [
            'path'  => $f,
            'url'   => Storage::disk('public')->url($f),
            'name'  => basename($f),
            'size'  => round(Storage::disk('public')->size($f) / 1024) . ' KB',
        ], $files);
    }

    public function deleteStyleReference(string $path): void
    {
        Storage::disk('public')->delete($path);
        Notification::make()->title('Imagine ștearsă')->success()->send();
        $this->redirect(static::getUrl());
    }

    /**
     * Trimite imaginile selectate la Claude pentru analiză și generare template-uri.
     * Rulează în background (queue) și notifică utilizatorul la final.
     */
    public function generateTemplates(): void
    {
        if (empty($this->selectedReferences)) {
            Notification::make()
                ->title('Nicio imagine selectată')
                ->body('Selectează cel puțin o imagine de referință pentru a genera template-uri.')
                ->warning()
                ->send();
            return;
        }

        // Verificăm că imaginile există efectiv
        $valid = array_filter(
            $this->selectedReferences,
            fn ($path) => Storage::disk('public')->exists($path)
        );

        if (empty($valid)) {
            Notification::make()
                ->title('Imagini invalide')
                ->body('Imaginile selectate nu mai există. Reîncarcă pagina.')
                ->danger()
                ->send();
            return;
        }

        AnalyzeReferenceImagesJob::dispatch(array_values($valid), auth()->id());

        $count = count($valid);
        Notification::make()
            ->title('Analiză pornită')
            ->body("Se analizează {$count} imagine(i) cu Claude AI. Vei fi notificat când template-urile sunt gata.")
            ->info()
            ->send();

        // Resetăm selecția
        $this->selectedReferences = [];
    }

    /**
     * Toggle-ează selecția unei imagini de referință.
     */
    public function toggleReferenceSelection(string $path): void
    {
        if (in_array($path, $this->selectedReferences, true)) {
            $this->selectedReferences = array_values(
                array_filter($this->selectedReferences, fn ($p) => $p !== $path)
            );
        } else {
            $this->selectedReferences[] = $path;
        }
    }

    public function toggleActive(int $accountId): void
    {
        $account = SocialAccount::find($accountId);
        if (! $account) {
            return;
        }
        $account->update(['is_active' => ! $account->is_active]);
        Notification::make()->title($account->is_active ? 'Cont activat' : 'Cont dezactivat')->success()->send();
    }
}

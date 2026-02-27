<?php

namespace App\Filament\Pages;

use App\Models\AppSetting;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class AppSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'Setări aplicație';
    protected static ?string $title           = 'Setări aplicație';
    protected static ?int    $navigationSort  = 100;
    protected static string  $view            = 'filament.pages.app-settings';

    public ?string $brand_name = null;
    public ?string $logo_path  = null;

    public function mount(): void
    {
        $this->brand_name = AppSetting::get(AppSetting::KEY_BRAND_NAME, 'Malinco ERP');
        $this->logo_path  = AppSetting::get(AppSetting::KEY_LOGO_PATH);

        $this->form->fill([
            'brand_name' => $this->brand_name,
            'logo_path'  => $this->logo_path ? [$this->logo_path] : [],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
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

        Notification::make()
            ->title('Setări salvate')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [];
    }
}

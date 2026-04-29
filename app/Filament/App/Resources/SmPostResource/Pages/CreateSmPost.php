<?php

namespace App\Filament\App\Resources\SmPostResource\Pages;

use App\Filament\App\Resources\SmPostResource;
use App\Jobs\Social\GenerateSmPostJob;
use App\Models\Brand;
use App\Models\SmPost;
use App\Models\WooCategory;
use App\Models\WooProduct;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;

class CreateSmPost extends CreateRecord
{
    protected static string $resource = SmPostResource::class;

    protected static ?string $title = 'Postare nouă';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label('Ce vrei să postezi?')
                ->options(SmPost::typeLabels())
                ->required()
                ->live()
                ->native(false)
                ->columnSpanFull(),

            Select::make('sourceable_id')
                ->label('Alege sursa')
                ->required()
                ->native(false)
                ->searchable()
                ->visible(fn ($get) => filled($get('type')))
                ->options(function ($get) {
                    return match ($get('type')) {
                        'product'  => WooProduct::whereNotNull('name')
                            ->orderBy('name')
                            ->limit(500)
                            ->pluck('name', 'id'),
                        'category' => WooCategory::orderBy('name')
                            ->pluck('name', 'id'),
                        'brand'    => Brand::where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id'),
                        default    => [],
                    };
                })
                ->columnSpanFull(),
        ]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['sourceable_type'] = match ($data['type']) {
            'product'  => WooProduct::class,
            'category' => WooCategory::class,
            'brand'    => Brand::class,
        };
        $data['status']     = SmPost::STATUS_DRAFT;
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        GenerateSmPostJob::dispatch($this->record);

        Notification::make()
            ->title('Postarea se generează...')
            ->body('Vei vedea rezultatul în câteva momente.')
            ->success()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return ViewSmPost::getUrl(['record' => $this->record]);
    }
}

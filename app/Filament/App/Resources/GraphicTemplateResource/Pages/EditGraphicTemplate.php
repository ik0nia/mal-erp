<?php

namespace App\Filament\App\Resources\GraphicTemplateResource\Pages;

use App\Filament\App\Resources\GraphicTemplateResource;
use App\Services\SocialMedia\NodeImageRenderer;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;

class EditGraphicTemplate extends EditRecord
{
    protected static string $resource = GraphicTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('preview')
                ->label('Generează Preview')
                ->icon('heroicon-o-eye')
                ->color('info')
                ->action(function () {
                    // Salvăm mai întâi
                    $this->save(shouldRedirect: false);

                    $record   = $this->getRecord()->fresh();
                    $config   = $record->config ?? [];
                    $renderer = app(NodeImageRenderer::class);

                    if (! $renderer->isAvailable()) {
                        Notification::make()
                            ->title('Node renderer indisponibil')
                            ->danger()
                            ->send();
                        return;
                    }

                    // Pentru layout brand, găsim un logo demo local
                    $brandLogoPath = null;
                    if (($record->layout ?? 'product') === 'brand') {
                        $dir = Storage::disk('public')->path('brand-logos');
                        if (is_dir($dir)) {
                            foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
                                $brandLogoPath = $dir . '/' . $file;
                                break;
                            }
                        }
                    }

                    // Generăm preview cu date de test
                    $filename = $renderer->render(
                        postId:          'tpl_preview_' . $record->id,
                        productImageUrl: null,
                        brandLogoPath:   $brandLogoPath,
                        title:           'Izolație Termică Superioară',
                        subtitle:        'Parteneri de încredere pentru construcții solide',
                        label:           'BRAND PARTENER',
                        templateConfig:  $config,
                    );

                    if (! $filename) {
                        Notification::make()
                            ->title('Eroare la generarea preview-ului')
                            ->danger()
                            ->send();
                        return;
                    }

                    // Salvăm preview pe template
                    $record->update(['preview_image' => $filename]);

                    Notification::make()
                        ->title('Preview generat cu succes')
                        ->success()
                        ->send();
                }),

            Actions\Action::make('view_preview')
                ->label('Vezi Preview')
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->url(fn () => $this->getRecord()->preview_image
                    ? Storage::disk('public')->url($this->getRecord()->preview_image)
                    : null)
                ->openUrlInNewTab()
                ->visible(fn () => (bool) $this->getRecord()->preview_image),

            Actions\DeleteAction::make(),
        ];
    }
}

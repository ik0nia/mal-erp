<?php

namespace App\Filament\App\Resources\SocialPostResource\Pages;

use App\Filament\App\Resources\SocialPostResource;
use App\Jobs\GenerateSocialPostJob;
use App\Jobs\PublishSocialPostJob;
use App\Jobs\RegenerateImageJob;
use App\Models\GraphicTemplate;
use App\Models\SocialPost;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewSocialPost extends ViewRecord
{
    protected static string $resource = SocialPostResource::class;

    // Polling automat cât timp se generează
    protected function getPollingInterval(): ?string
    {
        return in_array($this->record->status, [
            SocialPost::STATUS_GENERATING,
            SocialPost::STATUS_PUBLISHING,
        ]) ? '3s' : null;
    }

    protected function getHeaderActions(): array
    {
        $record = $this->record;

        return [
            // Editează brief-ul (doar dacă e editabil)
            Action::make('edit_brief')
                ->label('Editează brief')
                ->icon('heroicon-o-pencil')
                ->color('gray')
                ->visible(fn () => $this->record->isEditable())
                ->form([
                    Textarea::make('brief_direction')
                        ->label('Direcție')
                        ->default(fn () => $this->record->brief_direction)
                        ->rows(4)
                        ->required(),
                ])
                ->action(function (array $data) {
                    $this->record->update(['brief_direction' => $data['brief_direction']]);
                    Notification::make()->title('Brief actualizat')->success()->send();
                }),

            // Regenerează
            Action::make('regenerate')
                ->label('Regenerează')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => in_array($this->record->status, [
                    SocialPost::STATUS_READY,
                    SocialPost::STATUS_FAILED,
                ]))
                ->modalHeading('Regenerare postare')
                ->modalDescription('Vor fi generate un caption și o imagine nouă. Conținutul actual va fi suprascris.')
                ->form([
                    Select::make('template')
                        ->label('Template grafic')
                        ->placeholder('— Auto (după tipul postării) —')
                        ->options(fn () => GraphicTemplate::where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'slug')
                            ->all())
                        ->default(fn () => $this->record->template ?: null)
                        ->nullable()
                        ->helperText('Lasă gol pentru selecție automată după tipul postării.'),
                ])
                ->action(function (array $data) {
                    $this->record->update([
                        'status'        => SocialPost::STATUS_GENERATING,
                        'error_message' => null,
                        'template'      => $data['template'] ?? null,
                    ]);
                    GenerateSocialPostJob::dispatch($this->record->id);
                    Notification::make()->title('Regenerare pornită')->info()->send();
                }),

            // Regenerează doar grafica
            Action::make('regenerate_image')
                ->label('Regenerează grafică')
                ->icon('heroicon-o-photo')
                ->color('info')
                ->visible(fn () => in_array($this->record->status, [
                    SocialPost::STATUS_READY,
                    SocialPost::STATUS_FAILED,
                ]))
                ->modalHeading('Regenerare grafică')
                ->modalDescription('Se generează o imagine nouă cu aceleași texte. Caption-ul nu se modifică.')
                ->form([
                    Select::make('template')
                        ->label('Template grafic')
                        ->placeholder('— Auto (după tipul postării) —')
                        ->options(fn () => GraphicTemplate::where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'slug')
                            ->all())
                        ->default(fn () => $this->record->template ?: null)
                        ->nullable()
                        ->helperText('Lasă gol pentru selecție automată după tipul postării.'),
                ])
                ->action(function (array $data) {
                    if (isset($data['template'])) {
                        $this->record->update(['template' => $data['template'] ?? null]);
                    }
                    RegenerateImageJob::dispatch($this->record->id);
                    Notification::make()->title('Regenerare imagine pornită')->info()->send();
                }),

            // Editează caption manual
            Action::make('edit_caption')
                ->label('Editează caption')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(fn () => $this->record->status === SocialPost::STATUS_READY)
                ->form([
                    Textarea::make('caption')
                        ->label('Caption')
                        ->default(fn () => $this->record->caption)
                        ->rows(5)
                        ->required(),
                    Textarea::make('hashtags')
                        ->label('Hashtag-uri')
                        ->default(fn () => $this->record->hashtags)
                        ->rows(2),
                ])
                ->action(function (array $data) {
                    $this->record->update([
                        'caption'  => $data['caption'],
                        'hashtags' => $data['hashtags'],
                    ]);
                    Notification::make()->title('Caption actualizat')->success()->send();
                }),

            // Programează publicarea
            Action::make('schedule')
                ->label('Programează')
                ->icon('heroicon-o-calendar')
                ->color('primary')
                ->visible(fn () => $this->record->status === SocialPost::STATUS_READY)
                ->form([
                    DateTimePicker::make('scheduled_at')
                        ->label('Data și ora publicării')
                        ->required()
                        ->minDate(now()->addMinutes(5))
                        ->default(now()->addHour()->startOfHour()),
                ])
                ->action(function (array $data) {
                    $this->record->update([
                        'status'       => SocialPost::STATUS_SCHEDULED,
                        'scheduled_at' => $data['scheduled_at'],
                    ]);
                    Notification::make()
                        ->title('Postare programată')
                        ->body('Va fi publicată pe ' . \Carbon\Carbon::parse($data['scheduled_at'])->format('d.m.Y H:i'))
                        ->success()
                        ->send();
                }),

            // Reprogramează
            Action::make('reschedule')
                ->label('Reprogramează')
                ->icon('heroicon-o-calendar')
                ->color('warning')
                ->visible(fn () => $this->record->status === SocialPost::STATUS_SCHEDULED)
                ->form([
                    DateTimePicker::make('scheduled_at')
                        ->label('Nouă dată și oră')
                        ->required()
                        ->minDate(now()->addMinutes(5))
                        ->default(fn () => $this->record->scheduled_at),
                ])
                ->action(function (array $data) {
                    $this->record->update(['scheduled_at' => $data['scheduled_at']]);
                    Notification::make()->title('Reprogramat cu succes')->success()->send();
                }),

            // Anulează programarea
            Action::make('unschedule')
                ->label('Anulează programarea')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->record->status === SocialPost::STATUS_SCHEDULED)
                ->requiresConfirmation()
                ->action(function () {
                    $this->record->update(['status' => SocialPost::STATUS_READY, 'scheduled_at' => null]);
                    Notification::make()->title('Programare anulată')->warning()->send();
                }),

            // Publică acum
            Action::make('publish_now')
                ->label('Publică acum')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn () => $this->record->status === SocialPost::STATUS_READY)
                ->requiresConfirmation()
                ->modalHeading('Publică imediat')
                ->modalDescription('Postarea va fi publicată pe Facebook în câteva secunde.')
                ->action(function () {
                    $this->record->update([
                        'status'       => SocialPost::STATUS_SCHEDULED,
                        'scheduled_at' => now(),
                    ]);
                    PublishSocialPostJob::dispatch($this->record->id);
                    Notification::make()->title('Se publică...')->info()->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        return [
            'post' => $this->record,
        ];
    }
}

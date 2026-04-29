<?php

namespace App\Filament\App\Resources\SmPostResource\Pages;

use App\Filament\App\Resources\SmPostResource;
use App\Jobs\Social\GenerateSmPostJob;
use App\Jobs\Social\PublishSmPostJob;
use App\Models\SmPost;
use Filament\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewSmPost extends ViewRecord
{
    protected static string $resource = SmPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('approve')
                ->label('Aprobă postarea')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->isApprovable())
                ->requiresConfirmation()
                ->modalHeading('Aprobă postarea')
                ->modalDescription('Postarea va fi marcată ca aprobată și poate fi programată pentru publicare.')
                ->action(function () {
                    $this->record->update([
                        'status'      => SmPost::STATUS_APPROVED,
                        'approved_by' => auth()->id(),
                        'approved_at' => now(),
                    ]);
                    Notification::make()->title('Postare aprobată!')->success()->send();
                    $this->refreshRecord();
                }),

            Actions\Action::make('schedule')
                ->label('Programează')
                ->icon('heroicon-o-calendar')
                ->color('primary')
                ->visible(fn () => $this->record->isSchedulable())
                ->form([
                    DateTimePicker::make('scheduled_at')
                        ->label('Data și ora publicării')
                        ->required()
                        ->minDate(now())
                        ->native(false),
                ])
                ->action(function (array $data) {
                    $this->record->update([
                        'status'       => SmPost::STATUS_SCHEDULED,
                        'scheduled_at' => $data['scheduled_at'],
                    ]);
                    Notification::make()->title('Postare programată!')->success()->send();
                    $this->refreshRecord();
                }),

            Actions\Action::make('publish_now')
                ->label('Publică acum')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn () => $this->record->isSchedulable())
                ->requiresConfirmation()
                ->modalHeading('Publică imediat?')
                ->modalDescription('Postarea va fi trimisă acum pe Facebook și Instagram.')
                ->action(function () {
                    $this->record->update(['status' => SmPost::STATUS_SCHEDULED]);
                    PublishSmPostJob::dispatch($this->record);
                    Notification::make()->title('Publicare în curs...')->success()->send();
                    $this->refreshRecord();
                }),

            Actions\Action::make('regenerate')
                ->label('Regenerează')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => in_array($this->record->status, [SmPost::STATUS_READY, SmPost::STATUS_FAILED]))
                ->requiresConfirmation()
                ->action(function () {
                    GenerateSmPostJob::dispatch($this->record);
                    Notification::make()->title('Se regenerează...')->success()->send();
                    $this->refreshRecord();
                }),

            Actions\Action::make('edit_caption')
                ->label('Editează textul')
                ->icon('heroicon-o-pencil')
                ->color('gray')
                ->visible(fn () => $this->record->isEditable())
                ->form([
                    Textarea::make('caption')
                        ->label('Caption')
                        ->default(fn () => $this->record->caption)
                        ->rows(5)
                        ->required(),
                ])
                ->action(function (array $data) {
                    $this->record->update(['caption' => $data['caption']]);
                    Notification::make()->title('Text actualizat!')->success()->send();
                    $this->refreshRecord();
                }),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Previzualizare')
                ->schema([
                    Grid::make(2)->schema([
                        ImageEntry::make('image_path')
                            ->label('Imagine generată')
                            ->disk('public')
                            ->height(400)
                            ->columnSpan(1),

                        Grid::make(1)->schema([
                            TextEntry::make('status')
                                ->label('Status')
                                ->badge()
                                ->formatStateUsing(fn ($state) => SmPost::statusLabels()[$state] ?? $state)
                                ->color(fn ($state) => SmPost::statusColors()[$state] ?? 'gray'),

                            TextEntry::make('type')
                                ->label('Tip')
                                ->badge()
                                ->formatStateUsing(fn ($state) => SmPost::typeLabels()[$state] ?? $state),

                            TextEntry::make('source_name')
                                ->label('Sursă'),

                            TextEntry::make('caption')
                                ->label('Caption')
                                ->columnSpanFull(),

                            TextEntry::make('hashtags')
                                ->label('Hashtag-uri')
                                ->formatStateUsing(fn ($state) => is_array($state)
                                    ? collect($state)->map(fn ($h) => str_starts_with($h, '#') ? $h : "#$h")->implode(' ')
                                    : $state
                                ),
                        ])->columnSpan(1),
                    ]),
                ]),

            Section::make('Texte grafică')
                ->collapsed()
                ->schema([
                    TextEntry::make('graphic_texts.title')
                        ->label('Titlu'),
                    TextEntry::make('graphic_texts.subtitle')
                        ->label('Subtitlu'),
                    TextEntry::make('graphic_texts.cta')
                        ->label('Call to action'),
                    TextEntry::make('graphic_texts.advantages')
                        ->label('Avantaje')
                        ->listWithLineBreaks(),
                ]),

            Section::make('Programare & publicare')
                ->collapsed()
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('scheduled_at')
                            ->label('Programat pentru')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('—'),

                        TextEntry::make('published_at')
                            ->label('Publicat la')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('—'),

                        TextEntry::make('approved_at')
                            ->label('Aprobat la')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('—'),
                    ]),

                    TextEntry::make('error_message')
                        ->label('Eroare')
                        ->color('danger')
                        ->visible(fn ($record) => filled($record->error_message)),
                ]),
        ]);
    }
}

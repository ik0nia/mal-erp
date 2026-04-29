<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\SmPostResource\Pages;
use App\Models\SmPost;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class SmPostResource extends Resource
{
    protected static ?string $model = SmPost::class;

    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-megaphone';
    protected static string|\UnitEnum|null $navigationGroup   = 'Social Media';
    protected static ?string $navigationLabel                 = 'Postări';
    protected static ?string $modelLabel                      = 'Postare';
    protected static ?string $pluralModelLabel                = 'Postări';
    protected static ?int $navigationSort                     = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')
                    ->label('')
                    ->disk('public')
                    ->width(64)
                    ->height(64)
                    ->defaultImageUrl(asset('images/placeholder.png')),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tip')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SmPost::typeLabels()[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'product'  => 'primary',
                        'category' => 'info',
                        'brand'    => 'warning',
                        default    => 'gray',
                    }),

                Tables\Columns\TextColumn::make('source_name')
                    ->label('Sursă')
                    ->searchable(false),

                Tables\Columns\TextColumn::make('caption')
                    ->label('Caption')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->caption),

                Tables\Columns\TextColumn::make('platforms')
                    ->label('Platforme')
                    ->badge()
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : $state),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => SmPost::statusLabels()[$state] ?? $state)
                    ->color(fn ($state) => SmPost::statusColors()[$state] ?? 'gray'),

                Tables\Columns\TextColumn::make('scheduled_at')
                    ->label('Programat')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('published_at')
                    ->label('Publicat')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(SmPost::statusLabels()),

                Tables\Filters\SelectFilter::make('type')
                    ->label('Tip')
                    ->options(SmPost::typeLabels()),
            ])
            ->actions([
                Action::make('view')
                    ->label('Vezi')
                    ->icon('heroicon-o-eye')
                    ->url(fn (SmPost $record) => Pages\ViewSmPost::getUrl(['record' => $record])),

                Action::make('approve')
                    ->label('Aprobă')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (SmPost $record) => $record->isApprovable())
                    ->requiresConfirmation()
                    ->action(function (SmPost $record) {
                        $record->update([
                            'status'      => SmPost::STATUS_APPROVED,
                            'approved_by' => auth()->id(),
                            'approved_at' => now(),
                        ]);
                    }),

                Action::make('regenerate')
                    ->label('Regenerează')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (SmPost $record) => in_array($record->status, [SmPost::STATUS_READY, SmPost::STATUS_FAILED]))
                    ->requiresConfirmation()
                    ->action(function (SmPost $record) {
                        \App\Jobs\Social\GenerateSmPostJob::dispatch($record);
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSmPosts::route('/'),
            'create' => Pages\CreateSmPost::route('/create'),
            'view'   => Pages\ViewSmPost::route('/{record}'),
        ];
    }
}

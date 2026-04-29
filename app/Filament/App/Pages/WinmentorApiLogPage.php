<?php

namespace App\Filament\App\Pages;

use App\Models\WinmentorApiLog;
use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class WinmentorApiLogPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-signal';
    protected static ?string $navigationLabel = 'Apeluri API Bridge';
    protected static ?string $title           = 'Log apeluri WinMentor Bridge';
    protected static string|\UnitEnum|null $navigationGroup = 'WinMentor';
    protected static ?int    $navigationSort  = 30;
    protected string $view = 'filament.app.pages.winmentor-api-log';

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user instanceof \App\Models\User) return false;
        return $user->isSuperAdmin() || $user->isAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(WinmentorApiLog::query()->latest())
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data/Ora')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable()
                    ->width('160px'),

                Tables\Columns\TextColumn::make('method')
                    ->label('Metodă')
                    ->badge()
                    ->color(fn ($state) => match($state) {
                        'GET'  => 'info',
                        'POST' => 'primary',
                        'PUT'  => 'warning',
                        default => 'gray',
                    })
                    ->width('70px'),

                Tables\Columns\TextColumn::make('endpoint')
                    ->label('Endpoint')
                    ->searchable()
                    ->fontFamily('mono')
                    ->wrap(),

                Tables\Columns\TextColumn::make('context')
                    ->label('Context')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('status_code')
                    ->label('HTTP')
                    ->badge()
                    ->color(fn ($state) => match(true) {
                        $state >= 200 && $state < 300 => 'success',
                        $state >= 400                 => 'danger',
                        default                       => 'warning',
                    })
                    ->placeholder('—')
                    ->width('60px'),

                Tables\Columns\IconColumn::make('success')
                    ->label('OK')
                    ->boolean()
                    ->width('50px'),

                Tables\Columns\TextColumn::make('duration_ms')
                    ->label('Durată')
                    ->suffix(' ms')
                    ->sortable()
                    ->placeholder('—')
                    ->width('80px'),

                Tables\Columns\TextColumn::make('request_body')
                    ->label('Request JSON')
                    ->limit(80)
                    ->fontFamily('mono')
                    ->wrap()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('response_body')
                    ->label('Response JSON')
                    ->limit(120)
                    ->fontFamily('mono')
                    ->wrap()
                    ->placeholder('—')
                    ->color(fn (WinmentorApiLog $record) => $record->success ? null : 'danger'),
            ])
            ->filters([
                TernaryFilter::make('success')
                    ->label('Status'),

                SelectFilter::make('method')
                    ->label('Metodă')
                    ->options(['GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT']),

                SelectFilter::make('context')
                    ->label('Context')
                    ->options(fn () => WinmentorApiLog::query()
                        ->whereNotNull('context')
                        ->distinct()
                        ->pluck('context', 'context')
                        ->toArray()
                    ),
            ])
            ->actions([
                Action::make('view_json')
                    ->label('JSON')
                    ->icon('heroicon-o-code-bracket')
                    ->color('gray')
                    ->modalHeading(fn (WinmentorApiLog $record) => $record->method . ' ' . $record->endpoint)
                    ->modalWidth('4xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Închide')
                    ->form(fn (WinmentorApiLog $record): array => [
                        \Filament\Forms\Components\Placeholder::make('request')
                            ->label('Request Body')
                            ->content(function () use ($record): \Illuminate\Support\HtmlString {
                                $json = $record->request_body;
                                $decoded = json_decode($json);
                                $formatted = $decoded !== null
                                    ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                                    : ($json ?: '—');
                                return new \Illuminate\Support\HtmlString(
                                    '<pre style="font-size:12px;line-height:1.5;overflow-x:auto;background:#f8f9fa;padding:12px;border-radius:6px;max-height:300px;overflow-y:auto">'
                                    . e($formatted) . '</pre>'
                                );
                            }),
                        \Filament\Forms\Components\Placeholder::make('response')
                            ->label('Response Body')
                            ->content(function () use ($record): \Illuminate\Support\HtmlString {
                                $json = $record->response_body;
                                $decoded = json_decode($json);
                                $formatted = $decoded !== null
                                    ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                                    : ($json ?: '—');
                                $color = $record->success ? '' : 'color:#dc2626;';
                                return new \Illuminate\Support\HtmlString(
                                    '<pre style="font-size:12px;line-height:1.5;overflow-x:auto;background:#f8f9fa;padding:12px;border-radius:6px;max-height:300px;overflow-y:auto;' . $color . '">'
                                    . e($formatted) . '</pre>'
                                );
                            }),
                    ])
                    ->action(fn () => null),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s')
            ->emptyStateHeading('Niciun apel înregistrat')
            ->emptyStateIcon('heroicon-o-signal-slash');
    }
}

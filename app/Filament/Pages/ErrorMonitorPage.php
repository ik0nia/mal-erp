<?php

namespace App\Filament\Pages;

use App\Models\ErrorEvent;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class ErrorMonitorPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-bug-ant';
    protected static ?string $navigationLabel = 'Erori aplicație';
    protected static ?string $title           = 'Erori aplicație (observabilitate)';
    protected static string|\UnitEnum|null $navigationGroup = 'Integrări';
    protected static ?int    $navigationSort  = 70;
    protected string $view = 'filament.pages.error-monitor';

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user instanceof \App\Models\User && $user->isSuperAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationBadge(): ?string
    {
        $n = ErrorEvent::where('status', 'open')->count();
        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ErrorEvent::query())
            ->defaultSort('last_seen_at', 'desc')
            ->poll('60s')
            ->columns([
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('Ultima')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->description(fn (ErrorEvent $r) => 'prima: ' . $r->first_seen_at->format('d.m.Y H:i')),

                Tables\Columns\TextColumn::make('short_class')
                    ->label('Excepție')
                    ->badge()
                    ->color('danger')
                    ->searchable(query: fn ($q, $s) => $q->where('exception_class', 'like', "%{$s}%")),

                Tables\Columns\TextColumn::make('message')
                    ->label('Mesaj')
                    ->wrap()
                    ->limit(120)
                    ->searchable(),

                Tables\Columns\TextColumn::make('context')
                    ->label('Context')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'web' => 'info', 'console' => 'gray', 'queue' => 'warning', default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('count')
                    ->label('Apariții')
                    ->badge()
                    ->color(fn ($state) => $state >= 20 ? 'danger' : ($state >= 5 ? 'warning' : 'gray'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'open' => 'DESCHIS', 'resolved' => 'REZOLVAT', 'ignored' => 'IGNORAT', default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'open' => 'danger', 'resolved' => 'success', 'ignored' => 'gray', default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('durata')
                    ->label('Durată')
                    ->badge()
                    ->getStateUsing(fn (ErrorEvent $r) => $r->duration_label)
                    ->color(fn (ErrorEvent $r) => match ($r->status) {
                        'resolved' => 'success', 'open' => 'warning', default => 'gray',
                    })
                    ->tooltip(fn (ErrorEvent $r) => $r->resolved_at
                        ? 'Rezolvat: ' . $r->resolved_at->format('d.m.Y H:i')
                        : ($r->opened_at ? 'Deschis din: ' . $r->opened_at->format('d.m.Y H:i') : null)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(['open' => 'Deschise', 'resolved' => 'Rezolvate', 'ignored' => 'Ignorate'])
                    ->default('open'),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Detalii')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (ErrorEvent $r) => $r->short_class)
                    ->modalWidth('5xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Închide')
                    ->schema(fn (ErrorEvent $r): array => [
                        Placeholder::make('meta')->label('')->content(new HtmlString(
                            '<div style="font-size:13px;line-height:1.7">'
                            . '<b>' . e($r->exception_class) . '</b><br>'
                            . e($r->message) . '<br><br>'
                            . '<code>' . e($r->file) . ':' . $r->line . '</code><br>'
                            . 'Context: ' . e($r->context) . ' · Apariții: ' . $r->count
                            . ($r->url ? '<br>URL: ' . e($r->url) . ' (' . e($r->method ?? '') . ')' : '')
                            . '</div>'
                        )),
                        Placeholder::make('trace')->label('Stack trace')->content(fn () => new HtmlString(
                            '<pre style="font-size:11px;line-height:1.5;max-height:420px;overflow:auto;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:6px;white-space:pre-wrap;word-break:break-word;">'
                            . e($r->trace) . '</pre>'
                        )),
                    ]),

                ActionGroup::make([
                    Action::make('resolve')
                        ->label('Marchează rezolvat')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (ErrorEvent $r) => $r->status !== 'resolved')
                        ->action(fn (ErrorEvent $r) => tap($r)->update(['status' => 'resolved', 'resolved_at' => now()])
                            && Notification::make()->title('Marcat rezolvat')->success()->send()),

                    Action::make('ignore')
                        ->label('Ignoră (fără alerte)')
                        ->icon('heroicon-o-bell-slash')
                        ->color('gray')
                        ->visible(fn (ErrorEvent $r) => $r->status !== 'ignored')
                        ->action(fn (ErrorEvent $r) => tap($r)->update(['status' => 'ignored'])
                            && Notification::make()->title('Ignorat')->send()),

                    Action::make('reopen')
                        ->label('Redeschide')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->visible(fn (ErrorEvent $r) => $r->status !== 'open')
                        ->action(fn (ErrorEvent $r) => tap($r)->update(['status' => 'open', 'opened_at' => now(), 'resolved_at' => null])
                            && Notification::make()->title('Redeschis')->send()),
                ]),
            ]);
    }
}

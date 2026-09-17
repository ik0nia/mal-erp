<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\Location;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Actions;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = 'Sistem & Setări';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Utilizatori';

    protected static ?string $modelLabel = 'Utilizator';

    protected static ?string $pluralModelLabel = 'Utilizatori';

    protected static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::currentUser()?->isAdmin() ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::currentUser()?->isAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return static::currentUser()?->isAdmin() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        $user = static::currentUser();

        if (! $user?->isAdmin()) {
            return false;
        }

        return $user->isSuperAdmin() || ! ($record instanceof User && $record->isAdmin());
    }

    public static function canDelete(Model $record): bool
    {
        $user = static::currentUser();

        if (! $user?->isAdmin()) {
            return false;
        }

        if ($record instanceof User && $record->id === $user->id) {
            return false;
        }

        return $user->isSuperAdmin() || ! ($record instanceof User && $record->isAdmin());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nume')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Forms\Components\Select::make('role')
                    ->label('Rol')
                    ->required()
                    ->options(User::roleOptions())
                    ->native(false),
                Forms\Components\Select::make('location_id')
                    ->label('Magazin')
                    ->required(fn (Get $get): bool => ! ((bool) $get('is_admin') || (bool) $get('is_super_admin')))
                    ->options(function (): array {
                        return Location::query()
                            ->where('is_active', true)
                            ->where('type', Location::TYPE_STORE)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->searchable()
                    ->preload()
                    ->helperText('Depozitele magazinului sunt accesibile automat utilizatorului.')
                    ->native(false),
                Forms\Components\Toggle::make('is_admin')
                    ->label('Admin')
                    ->live()
                    ->visible(fn (): bool => static::currentUser()?->isSuperAdmin() ?? false),
                Forms\Components\Toggle::make('is_super_admin')
                    ->label('Super admin')
                    ->helperText('Super admin poate accesa atât /admin cât și ERP-ul operațional.')
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?bool $state): void {
                        if ($state === true) {
                            $set('is_admin', true);
                        }
                    })
                    ->visible(fn (): bool => static::currentUser()?->isSuperAdmin() ?? false),
                Forms\Components\TextInput::make('password')
                    ->label('Parolă')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->afterStateHydrated(fn ($component) => $component->state(null))
                    ->minLength(8)
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nume')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('role')
                    ->label('Rol')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => User::roleOptions()[$state] ?? $state)
                    ->sortable(),
                Tables\Columns\TextColumn::make('location.name')
                    ->label('Magazin')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_admin')
                    ->label('Admin')
                    ->boolean()
                    ->visible(fn (): bool => static::currentUser()?->isSuperAdmin() ?? false),
                Tables\Columns\IconColumn::make('is_super_admin')
                    ->label('Super')
                    ->boolean()
                    ->visible(fn (): bool => static::currentUser()?->isSuperAdmin() ?? false),
                Tables\Columns\TextColumn::make('status_sesiune')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(fn (User $r): string => match ($r->activity_status) {
                        'online' => 'Online',
                        'active' => 'Sesiune activă',
                        default  => 'Delogat',
                    })
                    ->color(fn (User $r): string => match ($r->activity_status) {
                        'online' => 'success',
                        'active' => 'warning',
                        default  => 'gray',
                    })
                    ->icon(fn (User $r): string => match ($r->activity_status) {
                        'online' => 'heroicon-s-signal',
                        'active' => 'heroicon-o-clock',
                        default  => 'heroicon-o-signal-slash',
                    })
                    ->description(fn (User $r): ?string => $r->last_activity_at
                        ? 'activ ' . $r->last_activity_at->diffForHumans()
                        : null)
                    ->tooltip(fn (User $r): ?string => $r->last_activity_at
                        ? 'Ultima activitate: ' . $r->last_activity_at->format('d.m.Y H:i')
                        : 'Fără activitate înregistrată'),
                Tables\Columns\TextColumn::make('last_login_at')
                    ->label('Ultima logare')
                    ->dateTime('d.m.Y H:i')
                    ->description(fn (User $r): ?string => $r->last_login_at ? $r->last_login_at->diffForHumans() : null)
                    ->placeholder('niciodată')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creat la')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label('Rol')
                    ->options(User::roleOptions()),
                Tables\Filters\SelectFilter::make('location_id')
                    ->label('Magazin')
                    ->options(function (): array {
                        return Location::query()
                            ->where('type', Location::TYPE_STORE)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    }),
                Tables\Filters\TernaryFilter::make('is_admin')
                    ->label('Admin')
                    ->visible(fn (): bool => static::currentUser()?->isSuperAdmin() ?? false),
                Tables\Filters\TernaryFilter::make('is_super_admin')
                    ->label('Super admin')
                    ->visible(fn (): bool => static::currentUser()?->isSuperAdmin() ?? false),
            ])
            ->deferFilters(false)
            ->recordActions([
                Actions\Action::make('activitate')
                    ->label('Activitate')
                    ->icon('heroicon-o-chart-bar')
                    ->color('gray')
                    ->modalHeading(fn (User $r): string => 'Activitate — ' . $r->name)
                    ->modalWidth('3xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Închide')
                    ->schema(fn (User $r): array => [
                        Placeholder::make('daily')
                            ->hiddenLabel()
                            ->content(new HtmlString(static::activityDailyHtml($r))),
                        DatePicker::make('zi')
                            ->label('Activitate orară — alege o zi')
                            ->native(false)
                            ->closeOnDateSelection()
                            ->minDate(now()->subDays(29)->startOfDay())
                            ->maxDate(now()->endOfDay())
                            ->disabledDates(static::daysWithoutData($r))
                            ->live()
                            ->helperText('Se pot alege doar zile cu activitate. Gol = agregat pe 30 de zile.'),
                        Placeholder::make('hourly')
                            ->hiddenLabel()
                            ->content(fn (Get $get) => new HtmlString(static::activityHourlyHtml($r, $get('zi')))),
                    ]),
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function fmtSec(int $s): string
    {
        return $s >= 3600 ? round($s / 3600, 1) . 'h' : ($s >= 60 ? round($s / 60) . 'm' : $s . 's');
    }

    /** Statistici + grafic zilnic (bare CSS) pe ultimele 30 de zile. */
    public static function activityDailyHtml(User $u): string
    {
        $days = 30;
        $rows = \Illuminate\Support\Facades\DB::table('user_activity_daily')
            ->where('user_id', $u->id)
            ->where('day', '>=', now()->subDays($days - 1)->toDateString())
            ->pluck('active_seconds', 'day');

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now()->subDays($i);
            $series[] = ['d' => $d, 'sec' => (int) ($rows[$d->toDateString()] ?? 0)];
        }
        $total  = array_sum(array_column($series, 'sec'));
        $active = count(array_filter($series, fn ($s) => $s['sec'] > 0));
        $maxSec = max(1, max(array_column($series, 'sec')));

        $bars = '';
        foreach ($series as $s) {
            $h = max(2, (int) round(($s['sec'] / $maxSec) * 110));
            $c = $s['sec'] > 0 ? '#d42b2b' : '#e5e7eb';
            $bars .= '<div title="' . $s['d']->format('d.m.Y') . ': ' . self::fmtSec($s['sec']) . '" style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:3px;">'
                . '<div style="width:72%;height:' . $h . 'px;background:' . $c . ';border-radius:3px 3px 0 0;"></div>'
                . '<div style="font-size:8px;color:#9ca3af;">' . $s['d']->format('j') . '</div></div>';
        }
        $stat = fn ($label, $val, $color = '#111827') => '<div><div style="font-size:11px;color:#6b7280;">' . $label . '</div><div style="font-size:20px;font-weight:800;color:' . $color . ';">' . $val . '</div></div>';

        return '<div style="font-size:13px;color:#374151;">'
            . '<div style="display:flex;gap:24px;margin-bottom:10px;flex-wrap:wrap;">'
            . $stat('Total 30 zile', self::fmtSec($total), '#d42b2b')
            . $stat('Medie / zi activă', self::fmtSec($active ? (int) ($total / $active) : 0))
            . $stat('Zile active', $active . ' / 30')
            . '</div>'
            . '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#6b7280;margin:4px 0 8px;">Pe zile (ultimele 30)</div>'
            . '<div style="display:flex;align-items:flex-end;gap:2px;height:120px;border-bottom:1px solid #e5e7eb;padding-bottom:2px;">' . $bars . '</div>'
            . '<div style="font-size:11px;color:#9ca3af;margin-top:8px;">'
            . 'Ultima logare: ' . ($u->last_login_at ? $u->last_login_at->format('d.m.Y H:i') : '—')
            . ' &middot; Ultima activitate: ' . ($u->last_activity_at ? $u->last_activity_at->diffForHumans() : '—')
            . '</div></div>';
    }

    /** Grafic orar (24h) pentru o zi anume, sau agregat pe 30 de zile dacă $day e gol. */
    public static function activityHourlyHtml(User $u, ?string $day): string
    {
        $days   = 30;
        $dayKey = $day ? \Illuminate\Support\Carbon::parse($day)->toDateString() : null;

        $q = \Illuminate\Support\Facades\DB::table('user_activity_hourly')->where('user_id', $u->id);
        if ($dayKey) {
            $q->where('day', $dayKey);
        } else {
            $q->where('day', '>=', now()->subDays($days - 1)->toDateString());
        }
        $hourly = $q->selectRaw('hour, SUM(active_seconds) as sec')->groupBy('hour')->pluck('sec', 'hour');

        $vals = [];
        $totalDay = 0;
        for ($h = 0; $h < 24; $h++) {
            $vals[$h] = (int) ($hourly[$h] ?? 0);
            $totalDay += $vals[$h];
        }
        $hmax = max(1, max($vals));

        $bars = '';
        for ($h = 0; $h < 24; $h++) {
            $bh = max(2, (int) round($vals[$h] / $hmax * 96));
            $c  = $vals[$h] > 0 ? '#2563eb' : '#e5e7eb';
            $bars .= '<div title="Ora ' . sprintf('%02d', $h) . ':00 — ' . self::fmtSec($vals[$h]) . '" style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:3px;">'
                . '<div style="width:66%;height:' . $bh . 'px;background:' . $c . ';border-radius:3px 3px 0 0;"></div>'
                . '<div style="font-size:8px;color:#9ca3af;">' . ($h % 3 === 0 ? $h : '') . '</div></div>';
        }

        $caption = $dayKey
            ? 'Ora activă în ' . \Illuminate\Support\Carbon::parse($dayKey)->format('d.m.Y') . ' — total ' . self::fmtSec($totalDay)
            : 'Agregat pe ultimele 30 de zile — total ' . self::fmtSec($totalDay);

        return '<div style="font-size:13px;">'
            . '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#6b7280;margin:0 0 8px;">Activitate orară</div>'
            . '<div style="display:flex;align-items:flex-end;gap:2px;height:110px;border-bottom:1px solid #e5e7eb;padding-bottom:2px;">' . $bars . '</div>'
            . '<div style="font-size:11px;color:#6b7280;margin-top:6px;">' . $caption . '</div>'
            . '</div>';
    }

    /** Zilele din intervalul de 30 de zile FĂRĂ activitate — pt DatePicker->disabledDates. */
    public static function daysWithoutData(User $u): array
    {
        $days = 30;
        $withData = \Illuminate\Support\Facades\DB::table('user_activity_hourly')
            ->where('user_id', $u->id)
            ->where('day', '>=', now()->subDays($days - 1)->toDateString())
            ->distinct()->pluck('day')->map(fn ($d) => (string) $d)->all();
        $withData = array_flip($withData);

        $disabled = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $k = now()->subDays($i)->toDateString();
            if (! isset($withData[$k])) {
                $disabled[] = $k;
            }
        }
        return $disabled;
    }

    public static function getRelations(): array
    {
        return [
            // V1: fără relation managers.
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}

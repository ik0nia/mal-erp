<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\Location;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Grid;
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
                    ->modalWidth(fn (): string => (static::currentUser()?->isSuperAdmin() ?? false) ? '7xl' : '3xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Închide')
                    ->schema(function (User $r): array {
                        $isSuper = static::currentUser()?->isSuperAdmin() ?? false;

                        return array_values(array_filter([
                            // Sus, pe 2 coloane: „Ce a făcut" | „Pagini vizitate" (doar super_admin).
                            $isSuper
                                ? Grid::make(['default' => 1, 'lg' => 2])->schema([
                                    Placeholder::make('actiuni')
                                        ->hiddenLabel()
                                        ->content(new HtmlString(static::activityActionsHtml($r))),
                                    Placeholder::make('pagini')
                                        ->hiddenLabel()
                                        ->content(new HtmlString(static::activityPagesHtml($r))),
                                ])
                                : null,
                            // Jos, pe toată lățimea: graficele de timp.
                            Placeholder::make('daily')
                                ->hiddenLabel()
                                ->content(new HtmlString(static::activityDailyHtml($r)))
                                ->columnSpanFull(),
                            DatePicker::make('zi')
                                ->label('Activitate orară — alege o zi')
                                ->native(false)
                                ->closeOnDateSelection()
                                ->minDate(now()->subDays(29)->startOfDay())
                                ->maxDate(now()->endOfDay())
                                ->disabledDates(static::daysWithoutData($r))
                                ->live()
                                ->helperText('Se pot alege doar zile cu activitate. Gol = agregat pe 30 de zile.')
                                ->columnSpanFull(),
                            Placeholder::make('hourly')
                                ->hiddenLabel()
                                ->content(fn (Get $get) => new HtmlString(static::activityHourlyHtml($r, $get('zi'))))
                                ->columnSpanFull(),
                        ]));
                    }),
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

    /**
     * Grafic cu bare + axă Y (max / jumătate / 0) și linii de reper, ca înălțimea să aibă o unitate clară.
     * $items: listă de ['val' => secunde, 'x' => etichetă sub bară, 'tip' => tooltip, 'click' => expr Alpine opțional].
     */
    private static function barChart(array $items, int $height, string $barColor): string
    {
        $vals = array_map(fn ($i) => (int) $i['val'], $items);
        $max  = max(1, max($vals));
        $peak = 0;
        foreach ($vals as $k => $v) { if ($v > $vals[$peak]) $peak = $k; }
        $hasData = $max > 0 && array_sum($vals) > 0;
        $barMax  = $height - 12; // lasă loc deasupra pt. eticheta orei de vârf

        $bars = '';
        foreach ($items as $k => $i) {
            $v      = (int) $i['val'];
            $isPeak = $hasData && $k === $peak && $v > 0;
            $bh     = $v > 0 ? max(3, (int) round($v / $max * $barMax)) : 2;
            $c      = $v > 0 ? ($isPeak ? '#b91c1c' : $barColor) : '#e5e7eb';
            $top    = $isPeak
                ? '<div style="font-size:8px;font-weight:800;color:#b91c1c;line-height:1;margin-bottom:2px;white-space:nowrap;">' . self::fmtSec($v) . '</div>'
                : '';
            $click  = $i['click'] ?? null;
            $clk    = $click ? ' x-on:click="' . $click . '"' : '';
            $cur    = $click ? 'cursor:pointer;' : '';
            $bars .= '<div' . $clk . ' title="' . $i['tip'] . '" style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;' . $cur . '"'
                . ($click ? ' onmouseover="this.lastElementChild.style.filter=\'brightness(0.85)\'" onmouseout="this.lastElementChild.style.filter=\'\'"' : '') . '>'
                . $top
                . '<div style="width:70%;height:' . $bh . 'px;background:' . $c . ';border-radius:3px 3px 0 0;transition:filter .1s;"></div></div>';
        }

        $labels = '';
        foreach ($items as $k => $i) {
            $isPeak = $hasData && $k === $peak && (int) $i['val'] > 0;
            $labels .= '<div style="flex:1;text-align:center;font-size:8px;white-space:nowrap;color:' . ($isPeak ? '#b91c1c' : '#9ca3af')
                . ';font-weight:' . ($isPeak ? '700' : '400') . ';">' . $i['x'] . '</div>';
        }

        // Axa Y: max sus, jumătate la mijloc, 0 jos — aliniată la înălțimea graficului.
        $axis = '<div style="display:flex;flex-direction:column;justify-content:space-between;height:' . $height . 'px;'
            . 'font-size:9px;color:#9ca3af;text-align:right;min-width:32px;box-sizing:border-box;padding-bottom:1px;">'
            . '<div>' . self::fmtSec($max) . '</div>'
            . '<div>' . self::fmtSec((int) round($max / 2)) . '</div>'
            . '<div>0</div></div>';

        // Grafic + linii de reper (jos și la jumătate) via gradient.
        $chart = '<div style="flex:1;min-width:0;">'
            . '<div style="display:flex;align-items:flex-end;gap:2px;height:' . $height . 'px;'
            . 'background-image:linear-gradient(to top,#e5e7eb 1px,transparent 1px);background-size:100% 50%;'
            . 'border-bottom:1px solid #e5e7eb;">' . $bars . '</div>'
            . '<div style="display:flex;gap:2px;margin-top:3px;">' . $labels . '</div></div>';

        return '<div style="display:flex;gap:6px;align-items:flex-start;">' . $axis . $chart . '</div>';
    }

    /**
     * „Ce a făcut" utilizatorul — timeline unificat din activity_log (audit + login-uri)
     * + WooOrderEdit (modificări comenzi online), pe ultimele 30 de zile. Doar super_admin.
     */
    public static function activityActionsHtml(User $u): string
    {
        $since = now()->subDays(30);

        $logs = \Spatie\Activitylog\Models\Activity::query()
            ->where('causer_type', User::class)
            ->where('causer_id', $u->id)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->get(['log_name', 'event', 'description', 'subject_type', 'subject_id', 'properties', 'created_at']);

        $orderEdits = \App\Models\WooOrderEdit::with('order:id,number')
            ->where('user_email', $u->email)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->get(['woo_order_id', 'action', 'label', 'created_at']);

        // Statistici
        $created = $logs->where('event', 'created')->count();
        $updated = $logs->where('event', 'updated')->count();
        $deleted = $logs->where('event', 'deleted')->count();
        $logins  = $logs->where('log_name', 'auth')->count();
        $orders  = $orderEdits->count();
        $totalActions = $logs->where('log_name', '!=', 'auth')->count() + $orders;

        // Culori pe categorie
        $catStyle = [
            'created' => ['#065f46', '#d1fae5', 'Creare'],
            'updated' => ['#92400e', '#fef3c7', 'Modificare'],
            'deleted' => ['#991b1b', '#fee2e2', 'Ștergere'],
            'login'   => ['#1e40af', '#dbeafe', 'Login'],
            'order'   => ['#3730a3', '#e0e7ff', 'Comandă'],
            'other'   => ['#374151', '#f3f4f6', 'Acțiune'],
        ];

        // Timeline unificat
        $items = [];
        foreach ($logs as $l) {
            $isAuth = $l->log_name === 'auth';
            $cat    = $isAuth ? 'login' : (in_array($l->event, ['created', 'updated', 'deleted'], true) ? $l->event : 'other');
            $entity = $isAuth || ! $l->subject_type
                ? ''
                : class_basename($l->subject_type).' '.($l->properties['subject_label'] ?? ('#'.$l->subject_id));
            $items[] = [
                'when'   => $l->created_at,
                'cat'    => $cat,
                'text'   => $l->description ?: '—',
                'entity' => $entity,
            ];
        }
        foreach ($orderEdits as $e) {
            $items[] = [
                'when'   => $e->created_at,
                'cat'    => 'order',
                'text'   => $e->label ?: $e->action,
                'entity' => $e->order ? 'Comandă #'.$e->order->number : 'Comandă #'.$e->woo_order_id,
            ];
        }
        usort($items, fn ($a, $b) => $b['when'] <=> $a['when']);
        $shown = array_slice($items, 0, 40);

        $stat = fn ($label, $val, $color = '#111827') => '<div><div style="font-size:11px;color:#6b7280;">'.$label.'</div><div style="font-size:20px;font-weight:800;color:'.$color.';">'.$val.'</div></div>';

        $rows = '';
        foreach ($shown as $it) {
            [$fg, $bg, $lbl] = $catStyle[$it['cat']] ?? $catStyle['other'];
            $badge = '<span style="display:inline-block;padding:1px 7px;border-radius:9px;font-size:10px;font-weight:700;color:'.$fg.';background:'.$bg.';white-space:nowrap;">'.$lbl.'</span>';
            $ent   = $it['entity'] ? '<span style="color:#6b7280;"> · '.e($it['entity']).'</span>' : '';
            $rows .= '<tr>'
                .'<td style="padding:5px 8px;border-top:1px solid #f1f5f9;white-space:nowrap;color:#6b7280;font-size:11px;vertical-align:top;">'.$it['when']->format('d.m H:i').'</td>'
                .'<td style="padding:5px 8px;border-top:1px solid #f1f5f9;vertical-align:top;">'.$badge.'</td>'
                .'<td style="padding:5px 8px;border-top:1px solid #f1f5f9;">'.e($it['text']).$ent.'</td>'
                .'</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="3" style="padding:12px 8px;text-align:center;color:#9ca3af;">Nicio acțiune înregistrată în ultimele 30 de zile.</td></tr>';
        }

        $journalUrl = ActivityLogResource::getUrl('index', ['tableFilters' => ['causer_id' => ['value' => $u->id]]]);

        return '<div style="font-size:13px;color:#374151;">'
            .'<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#6b7280;margin:0 0 8px;">Ce a făcut — ultimele 30 de zile</div>'
            .'<div style="display:flex;gap:20px;margin-bottom:12px;flex-wrap:wrap;">'
            .$stat('Acțiuni total', $totalActions, '#d42b2b')
            .$stat('Creări', $created, '#065f46')
            .$stat('Modificări', $updated, '#92400e')
            .$stat('Ștergeri', $deleted, '#991b1b')
            .$stat('Comenzi online', $orders, '#3730a3')
            .$stat('Login-uri', $logins, '#1e40af')
            .'</div>'
            .'<div style="max-height:280px;overflow-y:auto;border:1px solid #f1f5f9;border-radius:8px;">'
            .'<table style="width:100%;border-collapse:collapse;">'
            .'<thead><tr style="font-size:10px;color:#9ca3af;text-transform:uppercase;position:sticky;top:0;background:#fff;">'
            .'<th style="text-align:left;padding:6px 8px;">Când</th>'
            .'<th style="text-align:left;padding:6px 8px;">Tip</th>'
            .'<th style="text-align:left;padding:6px 8px;">Acțiune</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table></div>'
            .'<div style="margin-top:8px;font-size:11px;color:#6b7280;">'
            .(count($items) > 40 ? 'Se afișează ultimele 40 din '.count($items).' acțiuni. ' : '')
            .'<a href="'.$journalUrl.'" style="color:#2563eb;font-weight:600;text-decoration:none;">Vezi tot în Jurnalul de audit →</a>'
            .'</div>'
            .'</div>';
    }

    /** „Pagini vizitate" — top pagini + total, pe ultimele 30 de zile. Doar super_admin. */
    public static function activityPagesHtml(User $u): string
    {
        $since = now()->subDays(30);

        $total = \Illuminate\Support\Facades\DB::table('user_page_visits')
            ->where('user_id', $u->id)->where('visited_at', '>=', $since)->count();

        $top = \Illuminate\Support\Facades\DB::table('user_page_visits')
            ->where('user_id', $u->id)->where('visited_at', '>=', $since)
            ->selectRaw('path, MAX(route_name) as route_name, COUNT(*) as c, MAX(visited_at) as last_at')
            ->groupBy('path')
            ->orderByDesc('c')
            ->limit(15)
            ->get();

        $rows = '';
        foreach ($top as $r) {
            $label = \App\Models\UserPageVisit::labelFor($r->route_name, ltrim($r->path, '/'));
            $rows .= '<tr>'
                .'<td style="padding:5px 8px;border-top:1px solid #f1f5f9;">'.e($label)
                .'<div style="font-size:10px;color:#9ca3af;">/'.e($r->path).'</div></td>'
                .'<td style="padding:5px 8px;border-top:1px solid #f1f5f9;text-align:right;font-weight:700;white-space:nowrap;">'.$r->c.'×</td>'
                .'<td style="padding:5px 8px;border-top:1px solid #f1f5f9;text-align:right;color:#6b7280;font-size:11px;white-space:nowrap;">'
                .\Illuminate\Support\Carbon::parse($r->last_at)->format('d.m H:i').'</td>'
                .'</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="3" style="padding:12px 8px;text-align:center;color:#9ca3af;">Nicio pagină înregistrată încă (jurnalul de navigare a pornit acum).</td></tr>';
        }

        return '<div style="font-size:13px;color:#374151;">'
            .'<div style="display:flex;align-items:baseline;gap:8px;margin:0 0 8px;">'
            .'<span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#6b7280;">Pagini vizitate — 30 zile</span>'
            .'<span style="font-size:11px;color:#9ca3af;">('.$total.' accesări total)</span>'
            .'</div>'
            .'<div style="max-height:240px;overflow-y:auto;border:1px solid #f1f5f9;border-radius:8px;">'
            .'<table style="width:100%;border-collapse:collapse;">'
            .'<thead><tr style="font-size:10px;color:#9ca3af;text-transform:uppercase;position:sticky;top:0;background:#fff;">'
            .'<th style="text-align:left;padding:6px 8px;">Pagină</th>'
            .'<th style="text-align:right;padding:6px 8px;">Accesări</th>'
            .'<th style="text-align:right;padding:6px 8px;">Ultima</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table></div>'
            .'<div style="margin-top:8px;font-size:11px;color:#6b7280;">'
            .'<a href="'.\App\Filament\Resources\PageVisitResource::getUrl('index', ['tableFilters' => ['user_id' => ['value' => $u->id]]])
            .'" style="color:#2563eb;font-weight:600;text-decoration:none;">Vezi cronologic tot parcursul →</a>'
            .'</div>'
            .'</div>';
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

        $items = array_map(fn ($s) => [
            'val'   => $s['sec'],
            'x'     => (int) $s['d']->format('j') % 3 === 0 ? $s['d']->format('j') : '',
            'tip'   => $s['d']->format('d.m.Y') . ': ' . self::fmtSec($s['sec']) . ($s['sec'] > 0 ? ' — click pt. detaliu orar' : ''),
            // click pe zi (doar cele cu activitate) → setează selectorul „zi" → graficul orar se reîncarcă pe acea zi
            'click' => $s['sec'] > 0 ? "\$wire.\$set('mountedActions.0.data.zi', '" . $s['d']->toDateString() . "')" : null,
        ], $series);
        $chart = self::barChart($items, 120, '#d42b2b');
        $stat  = fn ($label, $val, $color = '#111827') => '<div><div style="font-size:11px;color:#6b7280;">' . $label . '</div><div style="font-size:20px;font-weight:800;color:' . $color . ';">' . $val . '</div></div>';

        return '<div x-data style="font-size:13px;color:#374151;">'
            . '<div style="display:flex;gap:24px;margin-bottom:10px;flex-wrap:wrap;">'
            . $stat('Total 30 zile', self::fmtSec($total), '#d42b2b')
            . $stat('Medie / zi activă', self::fmtSec($active ? (int) ($total / $active) : 0))
            . $stat('Zile active', $active . ' / 30')
            . '</div>'
            . '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#6b7280;margin:4px 0 8px;">Timp activ pe zile (ultimele 30) — 👆 apasă pe o zi pt. detaliul orar</div>'
            . $chart
            . '<div style="font-size:11px;color:#9ca3af;margin-top:10px;">'
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

        $peakH = 0;
        for ($h = 1; $h < 24; $h++) { if ($vals[$h] > $vals[$peakH]) $peakH = $h; }

        $items = [];
        for ($h = 0; $h < 24; $h++) {
            // etichetă din 4 în 4 ore + ora de vârf întotdeauna vizibilă, în format ceas
            $show   = $h % 4 === 0 || ($totalDay > 0 && $h === $peakH);
            $items[] = [
                'val' => $vals[$h],
                'x'   => $show ? $h . ':00' : '',
                'tip' => sprintf('%02d:00–%02d:59 — ', $h, $h) . self::fmtSec($vals[$h]),
            ];
        }
        $chart = self::barChart($items, 110, '#2563eb');

        $peakTxt = $totalDay > 0
            ? ' &middot; cel mai activ la ora <b style="color:#b91c1c;">' . sprintf('%02d:00', $peakH) . '</b> (' . self::fmtSec($vals[$peakH]) . ')'
            : '';

        $scope   = $dayKey
            ? 'Ziua ' . \Illuminate\Support\Carbon::parse($dayKey)->format('d.m.Y')
            : 'Medie pe ultimele 30 de zile';
        $caption = $scope . ' — total activ ' . self::fmtSec($totalDay) . $peakTxt;

        return '<div style="font-size:13px;">'
            . '<div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#6b7280;margin:0 0 8px;">Timp activ pe ore (0–23) — înălțimea barei = cât a fost activ în acea oră</div>'
            . $chart
            . '<div style="font-size:11px;color:#6b7280;margin-top:10px;">' . $caption . '</div>'
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

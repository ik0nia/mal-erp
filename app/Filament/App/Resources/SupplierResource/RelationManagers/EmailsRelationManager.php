<?php

namespace App\Filament\App\Resources\SupplierResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class EmailsRelationManager extends RelationManager
{
    protected static string  $relationship       = 'emails';
    protected static ?string $title              = 'Emailuri';
    protected static ?string $modelLabel         = 'email';
    protected static ?string $pluralModelLabel   = 'emailuri';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sent_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Data')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->width('120px'),

                Tables\Columns\TextColumn::make('subject')
                    ->label('Subiect')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->subject)
                    ->description(fn ($record) => $record->from_name
                        ? "{$record->from_name} <{$record->from_email}>"
                        : $record->from_email),

                // Tip AI
                Tables\Columns\TextColumn::make('agent_actions')
                    ->label('Tip')
                    ->formatStateUsing(function ($state) {
                        $labels = [
                            'offer'                 => 'Ofertă',
                            'invoice'               => 'Factură',
                            'order_confirmation'    => 'Confirmare',
                            'delivery_notification' => 'Livrare',
                            'price_list'            => 'Prețuri',
                            'payment'               => 'Plată',
                            'complaint'             => 'Reclamație',
                            'inquiry'               => 'Informare',
                            'automated'             => 'Automat',
                            'general'               => 'General',
                        ];
                        $type = $state['type'] ?? null;
                        return $type ? ($labels[$type] ?? $type) : '—';
                    })
                    ->badge()
                    ->color(fn ($record) => match($record->agent_actions['type'] ?? null) {
                        'offer'                 => 'success',
                        'invoice'               => 'warning',
                        'order_confirmation'    => 'info',
                        'delivery_notification' => 'info',
                        'price_list'            => 'primary',
                        'complaint'             => 'danger',
                        default                 => 'gray',
                    })
                    ->width('100px'),

                // Urgență
                Tables\Columns\IconColumn::make('agent_actions.urgency')
                    ->label('Urg.')
                    ->icon(fn ($state) => match($state) {
                        'high'   => 'heroicon-s-exclamation-circle',
                        'medium' => 'heroicon-s-minus-circle',
                        default  => null,
                    })
                    ->color(fn ($state) => match($state) {
                        'high'   => 'danger',
                        'medium' => 'warning',
                        default  => 'gray',
                    })
                    ->width('50px'),

                // Needs reply
                Tables\Columns\IconColumn::make('agent_actions.needs_reply')
                    ->label('↩')
                    ->boolean()
                    ->trueIcon('heroicon-s-arrow-uturn-left')
                    ->trueColor('warning')
                    ->falseIcon(null)
                    ->width('40px'),

                Tables\Columns\TextColumn::make('imap_folder')
                    ->label('Folder')
                    ->formatStateUsing(fn ($state) => match($state) {
                        'INBOX'      => 'Inbox',
                        'INBOX.Sent' => 'Trimis',
                        default      => str_contains($state, 'Archive') ? 'Arhivă' : $state,
                    })
                    ->badge()
                    ->color(fn ($state) => $state === 'INBOX.Sent' ? 'gray' : 'primary')
                    ->width('80px'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tip')
                    ->options([
                        'offer'                 => 'Ofertă',
                        'invoice'               => 'Factură',
                        'order_confirmation'    => 'Confirmare',
                        'price_list'            => 'Listă prețuri',
                        'delivery_notification' => 'Livrare',
                        'complaint'             => 'Reclamație',
                    ])
                    ->query(fn ($query, $data) => $data['value']
                        ? $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(agent_actions, '$.type')) = ?", [$data['value']])
                        : $query),

                Tables\Filters\TernaryFilter::make('needs_reply')
                    ->label('Necesită răspuns')
                    ->trueLabel('Doar cu răspuns necesar')
                    ->falseLabel('Fără răspuns necesar')
                    ->queries(
                        true:  fn ($query) => $query->whereRaw("JSON_EXTRACT(agent_actions, '$.needs_reply') = true"),
                        false: fn ($query) => $query->whereRaw("JSON_EXTRACT(agent_actions, '$.needs_reply') != true OR agent_actions IS NULL"),
                    ),

                Tables\Filters\SelectFilter::make('imap_folder')
                    ->label('Folder')
                    ->options([
                        'INBOX'      => 'Inbox',
                        'INBOX.Sent' => 'Trimise',
                    ]),
            ])
            ->deferFilters(false)
            ->recordUrl(null)
            ->recordAction(null)
            ->paginated([15, 30, 50])
            ->defaultPaginationPageOption(15);
    }
}

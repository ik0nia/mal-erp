<?php

namespace App\Filament\App\Pages;

use App\Models\EmailMessage;
use App\Models\Supplier;
use App\Models\SupplierContact;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class EmailCommunicationStatsPage extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar';
    protected static ?string $navigationLabel = 'Statistici Comunicare';
    protected static ?string $navigationGroup = 'Comunicare';
    protected static ?int    $navigationSort  = 2;
    protected static string  $view            = 'filament.app.pages.email-communication-stats';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    // ── Carduri sumar ──────────────────────────────────────────────────────

    public function getTotalEmails(): int
    {
        return EmailMessage::count();
    }

    public function getProcessedEmails(): int
    {
        return EmailMessage::whereNotNull('agent_processed_at')->count();
    }

    public function getUnprocessedEmails(): int
    {
        return EmailMessage::whereNull('agent_processed_at')
            ->whereIn('imap_folder', ['INBOX', 'INBOX.Sent'])
            ->count();
    }

    public function getTotalContacts(): int
    {
        return SupplierContact::count();
    }

    public function getDiscoveredContacts(): int
    {
        return SupplierContact::where('source', '!=', 'manual')->count();
    }

    public function getUnknownSenders(): int
    {
        return EmailMessage::whereNull('supplier_id')
            ->whereNotNull('from_email')
            ->where('from_email', 'not like', '%noreply%')
            ->where('from_email', 'not like', '%no-reply%')
            ->distinct('from_email')
            ->count('from_email');
    }

    // ── Top furnizori după volum emailuri ─────────────────────────────────

    public function getTopSuppliersByVolume(int $limit = 15): \Illuminate\Support\Collection
    {
        return EmailMessage::whereNotNull('supplier_id')
            ->select('supplier_id',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN imap_folder = "INBOX" THEN 1 ELSE 0 END) as received'),
                DB::raw('SUM(CASE WHEN imap_folder = "INBOX.Sent" THEN 1 ELSE 0 END) as sent'),
                DB::raw('MAX(sent_at) as last_email'),
                DB::raw('MIN(sent_at) as first_email'),
                DB::raw('SUM(CASE WHEN attachments IS NOT NULL AND attachments != "[]" THEN 1 ELSE 0 END) as with_attachments')
            )
            ->groupBy('supplier_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->with('supplier:id,name,is_active')
            ->get()
            ->map(function ($row) {
                $daysSinceFirst = $row->first_email
                    ? now()->diffInDays(\Carbon\Carbon::parse($row->first_email))
                    : null;

                $freqPerMonth = ($daysSinceFirst && $daysSinceFirst > 0)
                    ? round($row->total / ($daysSinceFirst / 30), 1)
                    : null;

                return [
                    'supplier'         => $row->supplier,
                    'total'            => $row->total,
                    'received'         => $row->received,
                    'sent'             => $row->sent,
                    'with_attachments' => $row->with_attachments,
                    'last_email'       => $row->last_email ? \Carbon\Carbon::parse($row->last_email) : null,
                    'first_email'      => $row->first_email ? \Carbon\Carbon::parse($row->first_email) : null,
                    'freq_per_month'   => $freqPerMonth,
                    'days_since_last'  => $row->last_email ? now()->diffInDays(\Carbon\Carbon::parse($row->last_email)) : null,
                ];
            });
    }

    // ── Furnizori tăcuți (asociați dar fără emailuri recente) ─────────────

    public function getSilentSuppliers(int $dayThreshold = 60): \Illuminate\Support\Collection
    {
        $activeSupplierIds = Supplier::where('is_active', true)->pluck('id');

        $lastEmailBySupplier = EmailMessage::whereIn('supplier_id', $activeSupplierIds)
            ->groupBy('supplier_id')
            ->select('supplier_id', DB::raw('MAX(sent_at) as last_email'))
            ->pluck('last_email', 'supplier_id');

        return Supplier::where('is_active', true)
            ->whereIn('id', $activeSupplierIds)
            ->get()
            ->filter(function ($supplier) use ($lastEmailBySupplier, $dayThreshold) {
                $last = $lastEmailBySupplier->get($supplier->id);
                if (! $last) {
                    return true; // niciodată email
                }
                return now()->diffInDays(\Carbon\Carbon::parse($last)) > $dayThreshold;
            })
            ->map(function ($supplier) use ($lastEmailBySupplier) {
                $last = $lastEmailBySupplier->get($supplier->id);
                return [
                    'supplier'       => $supplier,
                    'last_email'     => $last ? \Carbon\Carbon::parse($last) : null,
                    'days_silent'    => $last ? now()->diffInDays(\Carbon\Carbon::parse($last)) : null,
                ];
            })
            ->sortByDesc('days_silent')
            ->values();
    }

    // ── Volum emailuri per lună (ultimele 15 luni) ────────────────────────

    public function getMonthlyVolume(): \Illuminate\Support\Collection
    {
        return EmailMessage::select(
            DB::raw('DATE_FORMAT(sent_at, "%Y-%m") as month'),
            DB::raw('COUNT(*) as total'),
            DB::raw('SUM(CASE WHEN imap_folder = "INBOX" THEN 1 ELSE 0 END) as received'),
            DB::raw('SUM(CASE WHEN imap_folder = "INBOX.Sent" THEN 1 ELSE 0 END) as sent')
        )
            ->where('sent_at', '>=', now()->subMonths(15))
            ->groupBy('month')
            ->orderBy('month')
            ->get();
    }

    // ── Distribuție pe tipuri AI (dacă sunt procesate) ────────────────────

    public function getEmailTypeDistribution(): \Illuminate\Support\Collection
    {
        return EmailMessage::whereNotNull('agent_actions')
            ->whereNotNull('agent_processed_at')
            ->select(
                DB::raw('JSON_UNQUOTE(JSON_EXTRACT(agent_actions, "$.type")) as email_type'),
                DB::raw('COUNT(*) as cnt')
            )
            ->groupBy('email_type')
            ->orderByDesc('cnt')
            ->get();
    }

    // ── Expeditori necunoscuți (candidați pentru asociere furnizor) ───────

    public function getUnknownSendersList(int $limit = 20): \Illuminate\Support\Collection
    {
        return EmailMessage::whereNull('supplier_id')
            ->whereNotNull('from_email')
            ->where('from_email', 'not like', '%noreply%')
            ->where('from_email', 'not like', '%no-reply%')
            ->where('from_email', 'not like', '%donotreply%')
            ->select('from_email',
                DB::raw('MAX(from_name) as from_name'),
                DB::raw('COUNT(*) as cnt'),
                DB::raw('MAX(sent_at) as last_seen'))
            ->groupBy('from_email')
            ->having('cnt', '>=', 2)
            ->orderByDesc('cnt')
            ->limit($limit)
            ->get();
    }
}

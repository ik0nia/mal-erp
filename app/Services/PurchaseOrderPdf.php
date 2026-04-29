<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Generează PDF-ul unui PO și îl salvează pe disk.
 * La download îl servim direct din cache fără a-l regenera.
 */
class PurchaseOrderPdf
{
    private const DISK = 'local'; // storage/app/private
    private const DIR  = 'po_pdfs';

    /**
     * Returnează conținutul PDF — din cache dacă există, altfel îl generează și îl salvează.
     */
    public static function get(PurchaseOrder $order): string
    {
        $path = self::path($order);

        if (Storage::disk(self::DISK)->exists($path)) {
            return Storage::disk(self::DISK)->get($path);
        }

        return self::generateAndStore($order);
    }

    /**
     * Generează, salvează pe disk și returnează conținutul.
     * Apelat explicit la trimiterea emailului.
     */
    public static function generateAndStore(PurchaseOrder $order): string
    {
        $order->loadMissing(['supplier', 'buyer', 'approvedBy', 'items']);

        $content = Pdf::loadView('pdf.purchase-order', ['order' => $order])
            ->setPaper('a4', 'portrait')
            ->output();

        Storage::disk(self::DISK)->put(self::path($order), $content);

        return $content;
    }

    /**
     * Șterge cache-ul PDF (util dacă comanda e modificată după trimitere).
     */
    public static function invalidate(PurchaseOrder $order): void
    {
        $path = self::path($order);
        if (Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public static function filename(PurchaseOrder $order): string
    {
        return str_replace('/', '-', $order->number) . '.pdf';
    }

    private static function path(PurchaseOrder $order): string
    {
        return self::DIR . '/' . self::filename($order);
    }
}

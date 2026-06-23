<?php

namespace App\Services\Offers;

use App\Models\Offer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Generează PDF-ul unei oferte comerciale (branded) și îl salvează pe disk.
 */
class OfferPdf
{
    private const DISK = 'local';
    private const DIR  = 'offer_pdfs';

    public static function get(Offer $offer): string
    {
        $path = self::path($offer);

        if (Storage::disk(self::DISK)->exists($path)) {
            return Storage::disk(self::DISK)->get($path);
        }

        return self::generateAndStore($offer);
    }

    public static function generateAndStore(Offer $offer): string
    {
        $offer->loadMissing(['items.product', 'location', 'user']);

        $content = Pdf::loadView('pdf.offer', [
            'offer' => $offer,
            'logo'  => self::logoBase64(),
        ])
            ->setPaper('a4', 'portrait')
            ->output();

        Storage::disk(self::DISK)->put(self::path($offer), $content);

        return $content;
    }

    public static function invalidate(Offer $offer): void
    {
        $path = self::path($offer);
        if (Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public static function filename(Offer $offer): string
    {
        return 'Oferta-' . str_replace('/', '-', $offer->number) . '.pdf';
    }

    /**
     * Defalcare TVA pe cote (cheie = cotă, valori = bază + TVA).
     *
     * @return array<string, array{rate: float, net: float, vat: float}>
     */
    public static function vatBreakdown(Offer $offer): array
    {
        $rows = [];

        foreach ($offer->items as $item) {
            $rate = (float) $item->vat_rate;
            $key  = number_format($rate, 2, '.', '');

            $rows[$key] ??= ['rate' => $rate, 'net' => 0.0, 'vat' => 0.0];
            $rows[$key]['net'] += $item->line_net;
            $rows[$key]['vat'] += $item->line_vat;
        }

        krsort($rows);

        return $rows;
    }

    private static function logoBase64(): ?string
    {
        foreach (['malinco-logo.png', 'malinco-logo.svg'] as $file) {
            $path = public_path($file);
            if (is_file($path)) {
                $mime = str_ends_with($file, '.svg') ? 'image/svg+xml' : 'image/png';

                return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
            }
        }

        return null;
    }

    private static function path(Offer $offer): string
    {
        return self::DIR . '/' . self::filename($offer);
    }
}

<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PurchaseOrderExcelExport
{
    public static function generate(PurchaseOrder $order): string
    {
        $order->loadMissing(['supplier', 'buyer', 'items']);

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Comandă furnizor');

        // Column widths
        $sheet->getColumnDimension('A')->setWidth(6);   // Nr.
        $sheet->getColumnDimension('B')->setWidth(45);  // Denumire produs
        $sheet->getColumnDimension('C')->setWidth(18);  // SKU
        $sheet->getColumnDimension('D')->setWidth(18);  // Cod furnizor
        $sheet->getColumnDimension('E')->setWidth(12);  // Cantitate

        $burgundy = '8B1A1A';
        $darkGray = '374151';
        $lightGray = 'F3F4F6';

        // --- HEADER: Company info ---
        $row = 1;
        $sheet->mergeCells("A{$row}:E{$row}");
        $sheet->setCellValue("A{$row}", 'SC MALINCO PRODEX SRL');
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => new Color($burgundy)],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);

        $row++;
        $sheet->mergeCells("A{$row}:E{$row}");
        $sheet->setCellValue("A{$row}", 'CUI: RO18aborting | Reg. com.: J05/1234/2006');
        $sheet->getStyle("A{$row}")->getFont()->setSize(9)->setColor(new Color('6B7280'));

        // --- HEADER: Order info ---
        $row += 2;
        $sheet->mergeCells("A{$row}:E{$row}");
        $sheet->setCellValue("A{$row}", "COMANDĂ FURNIZOR — {$order->number}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => new Color($burgundy)],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);

        $row++;
        $sheet->setCellValue("A{$row}", 'Data:');
        $sheet->setCellValue("B{$row}", $order->created_at?->format('d.m.Y') ?? now()->format('d.m.Y'));
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(10);

        $row++;
        $sheet->setCellValue("A{$row}", 'Emis de:');
        $sheet->setCellValue("B{$row}", $order->buyer?->name ?? '—');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(10);

        // --- HEADER: Supplier info ---
        $row += 2;
        $sheet->mergeCells("A{$row}:E{$row}");
        $sheet->setCellValue("A{$row}", "FURNIZOR: {$order->supplier?->name}");
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 11],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);

        if ($order->supplier?->email || $order->supplier?->phone) {
            $row++;
            $contactInfo = collect([
                $order->supplier?->email ? "Email: {$order->supplier->email}" : null,
                $order->supplier?->phone ? "Tel: {$order->supplier->phone}" : null,
            ])->filter()->implode('  |  ');
            $sheet->mergeCells("A{$row}:E{$row}");
            $sheet->setCellValue("A{$row}", $contactInfo);
            $sheet->getStyle("A{$row}")->getFont()->setSize(9)->setColor(new Color('6B7280'));
        }

        // --- TABLE HEADER ---
        $row += 2;
        $headerRow = $row;
        $headers   = ['Nr.', 'Denumire produs', 'SKU', 'Cod furnizor', 'Cantitate'];

        foreach ($headers as $col => $header) {
            $cell = chr(65 + $col) . $row;
            $sheet->setCellValue($cell, $header);
        }

        $sheet->getStyle("A{$headerRow}:E{$headerRow}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => new Color('FFFFFF')],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => new Color($burgundy)],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => new Color($burgundy)]],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(28);

        // --- TABLE ROWS ---
        $nr = 0;
        foreach ($order->items as $item) {
            $nr++;
            $row++;

            $sheet->setCellValue("A{$row}", $nr);
            $sheet->setCellValue("B{$row}", $item->product_name);
            $sheet->setCellValueExplicit("C{$row}", $item->sku ?? '—', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$row}", $item->supplier_sku ?? '—', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("E{$row}", $item->quantity);

            // Alignment
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("E{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Zebra striping
            if ($nr % 2 === 0) {
                $sheet->getStyle("A{$row}:E{$row}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => new Color($lightGray)],
                ]);
            }

            // Borders
            $sheet->getStyle("A{$row}:E{$row}")->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => new Color('D1D5DB')]],
            ]);

            $sheet->getRowDimension($row)->setRowHeight(22);
        }

        // --- TOTAL ROW ---
        $row++;
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->setCellValue("A{$row}", 'Total poziții: ' . $nr);
        $sheet->setCellValue("E{$row}", $order->items->sum('quantity'));
        $sheet->getStyle("A{$row}:E{$row}")->applyFromArray([
            'font'    => ['bold' => true, 'size' => 10],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => new Color($burgundy)]],
        ]);
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("E{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // --- NOTES ---
        if ($order->notes_supplier) {
            $row += 2;
            $sheet->mergeCells("A{$row}:E{$row}");
            $sheet->setCellValue("A{$row}", 'Observații: ' . $order->notes_supplier);
            $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(9);
            $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true);
        }

        // --- FOOTER ---
        $row += 2;
        $sheet->mergeCells("A{$row}:E{$row}");
        $sheet->setCellValue("A{$row}", 'Document generat automat — SC Malinco Prodex SRL');
        $sheet->getStyle("A{$row}")->getFont()->setSize(8)->setColor(new Color('9CA3AF'));

        // Print settings
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.5)->setRight(0.5);

        // Generate file
        $writer   = new Xlsx($spreadsheet);
        $tempPath = tempnam(sys_get_temp_dir(), 'po_') . '.xlsx';
        $writer->save($tempPath);

        return $tempPath;
    }
}

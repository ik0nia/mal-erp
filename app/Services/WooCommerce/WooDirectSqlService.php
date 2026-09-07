<?php

namespace App\Services\WooCommerce;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Push prețuri și stocuri direct în MySQL WooCommerce via SSH.
 *
 * Actualizează: wp_postmeta + wp_wc_product_meta_lookup (tot ce face API-ul WooCommerce).
 */
class WooDirectSqlService
{
    private string $sshHost = 'root@malinco.ro';

    private string $sshKey;

    public function __construct()
    {
        // Horizon rulează ca www-data, CLI ca erp
        $home = posix_getpwuid(posix_geteuid())['dir'] ?? '/home/erp';
        $this->sshKey = $home . '/.ssh/id_ed25519';
    }

    private string $wpPath = '/var/www/malinco';

    /**
     * Update prețuri pentru mai multe produse.
     *
     * @param  array<int, array{id: int, regular_price: string}>  $updates  woo_id + regular_price
     * @return array{updated: int, failed: int}
     */
    public function updatePrices(array $updates): array
    {
        if (empty($updates)) {
            return ['updated' => 0, 'failed' => 0];
        }

        $sql = '';
        foreach ($updates as $item) {
            $wooId = (int) $item['id'];
            $price = $this->sanitizePrice($item['regular_price']);
            if ($wooId <= 0 || $price === '') {
                continue;
            }

            $sql .= "UPDATE wp_postmeta SET meta_value = '{$price}' WHERE post_id = {$wooId} AND meta_key = '_regular_price';\n";
            $sql .= "UPDATE wp_postmeta SET meta_value = '{$price}' WHERE post_id = {$wooId} AND meta_key = '_price';\n";
            $sql .= "UPDATE wp_wc_product_meta_lookup SET min_price = {$price}, max_price = {$price} WHERE product_id = {$wooId};\n";
        }

        return $this->executeSql($sql, count($updates), 'prices');
    }

    /**
     * Update stocuri pentru mai multe produse.
     *
     * @param  array<int, array{id: int, stock_quantity: int|null, stock_status: string, manage_stock: bool, backorders: string}>  $updates
     * @return array{updated: int, failed: int}
     */
    public function updateStock(array $updates): array
    {
        if (empty($updates)) {
            return ['updated' => 0, 'failed' => 0];
        }

        $sql = '';
        foreach ($updates as $item) {
            $wooId = (int) $item['id'];
            if ($wooId <= 0) {
                continue;
            }

            $stock = $item['stock_quantity'] !== null ? (int) $item['stock_quantity'] : 'NULL';
            $stockStr = $stock === 'NULL' ? '' : (string) $stock;
            $status = $this->sanitizeString($item['stock_status'] ?? 'instock');
            $manage = ($item['manage_stock'] ?? false) ? 'yes' : 'no';
            $backorders = $this->sanitizeString($item['backorders'] ?? 'no');

            // wp_postmeta
            $sql .= "UPDATE wp_postmeta SET meta_value = '{$stockStr}' WHERE post_id = {$wooId} AND meta_key = '_stock';\n";
            $sql .= "UPDATE wp_postmeta SET meta_value = '{$status}' WHERE post_id = {$wooId} AND meta_key = '_stock_status';\n";
            $sql .= "UPDATE wp_postmeta SET meta_value = '{$manage}' WHERE post_id = {$wooId} AND meta_key = '_manage_stock';\n";
            $sql .= "UPDATE wp_postmeta SET meta_value = '{$backorders}' WHERE post_id = {$wooId} AND meta_key = '_backorders';\n";

            // wp_wc_product_meta_lookup
            $stockLookup = $stock === 'NULL' ? 'NULL' : $stock;
            $sql .= "UPDATE wp_wc_product_meta_lookup SET stock_quantity = {$stockLookup}, stock_status = '{$status}' WHERE product_id = {$wooId};\n";
        }

        return $this->executeSql($sql, count($updates), 'stock');
    }

    /**
     * Update prețuri + stocuri în același batch.
     *
     * @param  array<int, array{id: int, regular_price?: string, stock_quantity?: int|null, stock_status?: string, manage_stock?: bool, backorders?: string}>  $updates
     * @return array{updated: int, failed: int}
     */
    public function updatePricesAndStock(array $updates): array
    {
        if (empty($updates)) {
            return ['updated' => 0, 'failed' => 0];
        }

        $sql = '';
        foreach ($updates as $item) {
            $wooId = (int) $item['id'];
            if ($wooId <= 0) {
                continue;
            }

            $lookupSets = [];

            // Prețuri
            if (isset($item['regular_price'])) {
                $price = $this->sanitizePrice($item['regular_price']);
                if ($price !== '') {
                    $sql .= "UPDATE wp_postmeta SET meta_value = '{$price}' WHERE post_id = {$wooId} AND meta_key = '_regular_price';\n";
                    $sql .= "UPDATE wp_postmeta SET meta_value = '{$price}' WHERE post_id = {$wooId} AND meta_key = '_price';\n";
                    $lookupSets[] = "min_price = {$price}, max_price = {$price}";
                }
            }

            // Stoc
            if (isset($item['stock_status'])) {
                $stock = isset($item['stock_quantity']) && $item['stock_quantity'] !== null ? (int) $item['stock_quantity'] : null;
                $stockStr = $stock !== null ? (string) $stock : '';
                $status = $this->sanitizeString($item['stock_status']);
                $manage = ($item['manage_stock'] ?? false) ? 'yes' : 'no';
                $backorders = $this->sanitizeString($item['backorders'] ?? 'no');

                $sql .= "UPDATE wp_postmeta SET meta_value = '{$stockStr}' WHERE post_id = {$wooId} AND meta_key = '_stock';\n";
                $sql .= "UPDATE wp_postmeta SET meta_value = '{$status}' WHERE post_id = {$wooId} AND meta_key = '_stock_status';\n";
                $sql .= "UPDATE wp_postmeta SET meta_value = '{$manage}' WHERE post_id = {$wooId} AND meta_key = '_manage_stock';\n";
                $sql .= "UPDATE wp_postmeta SET meta_value = '{$backorders}' WHERE post_id = {$wooId} AND meta_key = '_backorders';\n";

                $stockLookup = $stock !== null ? $stock : 'NULL';
                $lookupSets[] = "stock_quantity = {$stockLookup}, stock_status = '{$status}'";
            }

            if (! empty($lookupSets)) {
                $sql .= 'UPDATE wp_wc_product_meta_lookup SET '.implode(', ', $lookupSets)." WHERE product_id = {$wooId};\n";
            }
        }

        return $this->executeSql($sql, count($updates), 'prices+stock');
    }

    /**
     * Flush WooCommerce cache (object cache + transients).
     */
    public function flushCache(): bool
    {
        // Pașii rulează independent (`;`, nu `&&`): dacă `wp cache flush` crapă
        // (ex. Redis read error intermitent), cache-ul nginx tot trebuie golit —
        // el servește efectiv paginile cu prețuri vechi.
        // Fără paranteze/escapări complexe în comanda remote: o eroare de sintaxă
        // bash blochează execuția ÎNTREGII linii, inclusiv purge-ul nginx.
        $result = Process::timeout(30)->run(
            "ssh -i {$this->sshKey} -o StrictHostKeyChecking=no {$this->sshHost} ".
            "'rm -rf /var/cache/nginx/malinco/* 2>/dev/null; ".
            "wp --path={$this->wpPath} cache flush --allow-root 2>/dev/null; ".
            "wp --path={$this->wpPath} transient delete --all --allow-root 2>/dev/null; ".
            "echo FLUSH_OK'"
        );

        if (! str_contains($result->output(), 'FLUSH_OK')) {
            Log::warning('[WooDirectSQL] cache flush failed', [
                'output' => $result->output(),
                'error' => $result->errorOutput(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Post-sync: flush cache + rebuild FiboSearch.
     * Apelează o singură dată la finalul unui batch de update-uri.
     */
    public function afterSync(): void
    {
        $this->flushCache();
        $this->rebuildFiboSearchIndex();
    }

    /**
     * Rebuild FiboSearch index (wp fibosearch index build).
     * Rulează în background — indexarea durează 1-3 minute.
     */
    public function rebuildFiboSearchIndex(): bool
    {
        $result = Process::timeout(10)->run(
            "ssh -i {$this->sshKey} -o StrictHostKeyChecking=no {$this->sshHost} ".
            "'nohup wp --path={$this->wpPath} fibosearch index build --hide-progress --allow-root > /dev/null 2>&1 &'"
        );

        if ($result->successful()) {
            Log::info('[WooDirectSQL] FiboSearch reindex triggered');
        }

        return $result->successful();
    }

    /**
     * Interogare READ-ONLY pe baza site-ului (wp db query), cu rândurile ca array-uri
     * asociative. Punct unic pentru host/cheie/cale — folosit de sync-urile Sameday etc.
     *
     * @return array<int, array<string, string|null>>
     */
    public function querySite(string $sql, int $timeout = 60): array
    {
        $result = Process::timeout($timeout)->run([
            'ssh', '-i', $this->sshKey, '-o', 'StrictHostKeyChecking=no', $this->sshHost,
            'wp --path='.$this->wpPath.' db query '.escapeshellarg($sql).' --allow-root',
        ]);

        if (! $result->successful()) {
            throw new \RuntimeException('Interogarea site-ului a eșuat: '.trim($result->errorOutput() ?: $result->output()));
        }

        $lines = array_values(array_filter(explode("\n", trim($result->output())), fn ($l) => $l !== ''));
        if (empty($lines)) {
            return [];
        }

        $headers = explode("\t", array_shift($lines));

        return array_map(function (string $line) use ($headers) {
            $values = explode("\t", $line);
            $row    = [];
            foreach ($headers as $i => $h) {
                $v = $values[$i] ?? null;
                $row[$h] = ($v === 'NULL') ? null : $v;
            }
            return $row;
        }, $lines);
    }

    /**
     * Execută statement-uri de SCRIERE pe baza site-ului. Public pentru integrarea
     * Sameday (oglindirea AWB-urilor create din ERP în tabelul pluginului).
     *
     * @return array{updated:int, failed:int}
     */
    public function executeSiteSql(string $sql, string $type = 'generic'): array
    {
        return $this->executeSql($sql, 1, $type);
    }

    private function executeSql(string $sql, int $productCount, string $type): array
    {
        if ($sql === '') {
            return ['updated' => 0, 'failed' => 0];
        }

        $tmpFile = storage_path('woo_direct_'.uniqid().'.sql');
        file_put_contents($tmpFile, $sql);

        try {
            // Upload + execute
            $remoteTmp = '/tmp/woo_update_'.uniqid().'.sql';
            $result = Process::timeout(120)->run(
                "scp -i {$this->sshKey} -o StrictHostKeyChecking=no {$tmpFile} {$this->sshHost}:{$remoteTmp} && ".
                "ssh -i {$this->sshKey} -o StrictHostKeyChecking=no {$this->sshHost} ".
                "'wp --path={$this->wpPath} db query < {$remoteTmp} --allow-root 2>/dev/null && rm {$remoteTmp}'"
            );

            if ($result->successful()) {
                Log::info("[WooDirectSQL] {$type} update completed", [
                    'products' => $productCount,
                ]);

                return ['updated' => $productCount, 'failed' => 0];
            }

            Log::error("[WooDirectSQL] {$type} update failed", [
                'products' => $productCount,
                'error' => $result->errorOutput(),
            ]);

            return ['updated' => 0, 'failed' => $productCount];
        } finally {
            @unlink($tmpFile);
        }
    }

    private function sanitizePrice(string $price): string
    {
        $clean = preg_replace('/[^0-9.]/', '', $price);

        return $clean !== '' && (float) $clean > 0 ? number_format((float) $clean, 2, '.', '') : '';
    }

    private function sanitizeString(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $value);
    }
}

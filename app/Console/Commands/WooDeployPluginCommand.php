<?php

namespace App\Console\Commands;

use App\Services\WooCommerce\WooPluginClient;
use Illuminate\Console\Command;
use ZipArchive;

class WooDeployPluginCommand extends Command
{
    protected $signature = 'woo:deploy-plugin
                            {--build-only : Construiește ZIP-ul fără a-l trimite}
                            {--check     : Verifică versiunea curentă a plugin-ului}';

    protected $description = 'Construiește și deployează plugin-ul malinco-erp-bridge pe WordPress';

    private const PLUGIN_SOURCE = __DIR__ . '/../../../wordpress-plugin/malinco-erp-bridge';
    private const ZIP_OUTPUT    = __DIR__ . '/../../../storage/app/malinco-erp-bridge.zip';

    public function handle(): int
    {
        if ($this->option('check')) {
            return $this->checkVersion();
        }

        $zipPath = $this->buildZip();

        if (! $zipPath) {
            $this->error('Build ZIP eșuat.');
            return self::FAILURE;
        }

        $this->info("ZIP creat: {$zipPath}");

        if ($this->option('build-only')) {
            $this->info('--build-only: nu se face deploy.');
            return self::SUCCESS;
        }

        $this->line('Se trimite plugin-ul pe WordPress...');
        $client  = new WooPluginClient();
        $success = $client->deployPlugin($zipPath);

        if ($success) {
            $this->info('Plugin deploiat cu succes!');
            $this->checkVersion();
            return self::SUCCESS;
        }

        $this->warn('Deploy eșuat — verifică că API key-ul e configurat în ERP (AppSetting: woo_plugin_api_key) și în WordPress (Settings → ERP Bridge).');
        $this->line('Poți instala manual descărcând ZIP-ul și uploadând în WP Admin → Plugins → Add New → Upload Plugin.');

        return self::FAILURE;
    }

    private function buildZip(): ?string
    {
        $sourceDir = realpath(self::PLUGIN_SOURCE);

        if (! $sourceDir || ! is_dir($sourceDir)) {
            $this->error("Director sursă plugin negăsit: " . self::PLUGIN_SOURCE);
            return null;
        }

        $zipPath = realpath(dirname(self::ZIP_OUTPUT)) . '/' . basename(self::ZIP_OUTPUT);

        if (! class_exists(ZipArchive::class)) {
            $this->error('Extensia PHP ZipArchive nu este disponibilă.');
            return null;
        }

        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error("Nu se poate crea ZIP: {$zipPath}");
            return null;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $filePath     = $file->getRealPath();
            $relativePath = 'malinco-erp-bridge/' . substr($filePath, strlen($sourceDir) + 1);
            $zip->addFile($filePath, $relativePath);
        }

        $zip->close();

        return $zipPath;
    }

    private function checkVersion(): int
    {
        $client  = new WooPluginClient();
        $version = $client->getVersion();

        if ($version === null) {
            $this->warn('Plugin indisponibil — nu răspunde sau API key incorect.');
            return self::FAILURE;
        }

        $this->info("Plugin activ: v{$version['version']} pe {$version['site']}");
        return self::SUCCESS;
    }
}

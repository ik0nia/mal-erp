<?php

namespace App\Services\SocialMedia;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class NodeImageRenderer
{
    private string $rendererPath;
    private string $malincoLogoPath;

    public function __construct()
    {
        $this->rendererPath    = base_path('node-renderer/render.js');
        $this->malincoLogoPath = Storage::disk('public')->path('malinco-logo.png');
    }

    /**
     * Renderează imaginea pentru o postare.
     * Returnează filename relativ (social/xxx.jpg) sau null dacă eșuează.
     *
     * @param string      $postId          ID postare (pentru filename unic)
     * @param string|null $productImageUrl URL sau cale locală imagine produs
     * @param string|null $brandLogoPath   Cale locală logo brand partener
     * @param string|null $title           Titlul principal (mare roșu)
     * @param string|null $subtitle        Textul mic de deasupra titlului
     * @param array       $extraElements   Elemente suplimentare (rect/text)
     * @param string|null $label           Eyebrow label (deasupra titlului)
     * @param array       $templateConfig  Override-uri de stil din GraphicTemplate::config
     */
    public function render(
        string $postId,
        ?string $productImageUrl = null,
        ?string $brandLogoPath = null,
        ?string $title = null,
        ?string $subtitle = null,
        array $extraElements = [],
        ?string $label = null,
        array $templateConfig = [],
    ): ?string {
        $filename = 'social/' . $postId . '_' . Str::random(8) . '.jpg';
        $outPath  = Storage::disk('public')->path($filename);

        $socialDir = Storage::disk('public')->path('social');
        if (! is_dir($socialDir)) {
            mkdir($socialDir, 0775, true);
        }
        // Asigurăm că www-data poate scrie (node rulează ca www-data)
        if ((fileperms($socialDir) & 0777) !== 0775) {
            chmod($socialDir, 0775);
        }

        $config = [
            'output'       => $outPath,
            'width'        => 1080,
            'height'       => 1080,
            'malinco_logo' => $this->malincoLogoPath,
        ];

        if ($productImageUrl) {
            $config['product_image'] = $productImageUrl;
        }

        if ($brandLogoPath && file_exists($brandLogoPath)) {
            $config['brand_logo'] = $brandLogoPath;
        }

        if (filled($title)) {
            $config['title'] = $title;
        }

        if (filled($subtitle)) {
            $config['subtitle'] = $subtitle;
        }

        if ($extraElements) {
            $config['elements'] = $extraElements;
        }

        if (filled($label)) {
            $config['label'] = $label;
        }

        // Merge style overrides din GraphicTemplate (culori, texte, proporții)
        if ($templateConfig) {
            $config = array_merge($config, $templateConfig);
            // output/width/height/malinco_logo nu trebuie suprascrise
            $config['output']       = $outPath;
            $config['width']        = 1080;
            $config['height']       = 1080;
            $config['malinco_logo'] = $this->malincoLogoPath;
        }

        $process = new Process(
            ['node', $this->rendererPath, json_encode($config)],
            null,  // cwd
            null,  // env
            null,  // input
            30     // timeout 30 secunde
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            Log::error('NodeImageRenderer: timeout după 30s', [
                'renderer' => $this->rendererPath,
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::error('NodeImageRenderer: eroare neașteptată', ['error' => $e->getMessage()]);
            return null;
        }

        if (! $process->isSuccessful()) {
            Log::error('NodeImageRenderer: eroare render', [
                'exit_code' => $process->getExitCode(),
                'stderr'    => $process->getErrorOutput(),
                'stdout'    => $process->getOutput(),
            ]);
            return null;
        }

        if (! file_exists($outPath)) {
            Log::error('NodeImageRenderer: fișierul output nu există după render');
            return null;
        }

        return $filename;
    }

    public function isAvailable(): bool
    {
        return file_exists($this->rendererPath) && file_exists($this->malincoLogoPath);
    }
}

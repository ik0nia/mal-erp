<?php

namespace App\Filament\App\Pages;

use App\Models\GraphicTemplate;
use App\Services\SocialMedia\NodeImageRenderer;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;

class GraphicTemplateVisualEditorPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-cursor-arrow-ripple';
    protected static ?string $navigationLabel = 'Editor Vizual';
    protected static string|\UnitEnum|null $navigationGroup = 'Social Media';
    protected static ?int    $navigationSort  = 12;
    protected string  $view            = 'filament.app.pages.graphic-template-visual-editor';

    public static function shouldRegisterNavigation(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    public static function canAccess(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    public ?int    $templateId    = null;
    public string  $positionsJson = '{}';
    public ?string $previewUrl    = null;

    public function mount(): void
    {
        $template = GraphicTemplate::find(request('template'))
            ?? GraphicTemplate::first();

        if ($template) {
            $this->loadTemplate($template);
        }
    }

    protected function loadTemplate(GraphicTemplate $template): void
    {
        $this->templateId = $template->id;

        $positions = $template->config['element_positions'] ?? [];
        $this->positionsJson = $positions ? json_encode($positions) : '{}';

        if ($template->preview_image) {
            $this->previewUrl = Storage::disk('public')->url($template->preview_image) . '?t=' . $template->updated_at->timestamp;
        }
    }

    public function switchTemplate(int $id): void
    {
        $template = GraphicTemplate::findOrFail($id);
        $this->loadTemplate($template);

        // Trimitem pozițiile și preview-ul la Alpine fără a re-randa canvas-ul
        $this->dispatch('template-loaded', [
            'positionsJson' => $this->positionsJson,
            'previewUrl'    => $this->previewUrl,
            'templateId'    => $this->templateId,
        ]);
    }

    public function savePositionsAndPreview(string $positionsJson): void
    {
        if (! $this->templateId) return;

        $template  = GraphicTemplate::findOrFail($this->templateId);
        $positions = json_decode($positionsJson, true) ?? [];

        $config = $template->config ?? [];
        $config['element_positions'] = $positions;
        $template->update(['config' => $config]);

        $this->positionsJson = $positionsJson;

        $renderer = app(NodeImageRenderer::class);
        if (! $renderer->isAvailable()) {
            Notification::make()->title('Node renderer indisponibil')->danger()->send();
            $this->previewUrl = '__error__';  // resetează saving în Alpine
            return;
        }

        $brandLogoPath = null;
        if (($template->layout ?? 'product') === 'brand') {
            $dir = Storage::disk('public')->path('brand-logos');
            if (is_dir($dir)) {
                foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
                    $brandLogoPath = $dir . '/' . $file;
                    break;
                }
            }
        }

        $filename = $renderer->render(
            postId:         'tpl_ve_' . $template->id,
            brandLogoPath:  $brandLogoPath,
            title:          'Izolație Termică Superioară',
            subtitle:       'Parteneri de încredere pentru construcții solide',
            label:          $template->layout === 'brand' ? 'BRAND PARTENER' : 'PROMO',
            templateConfig: $template->fresh()->config,
        );

        if (! $filename) {
            Notification::make()->title('Eroare la generare preview')->danger()->send();
            $this->previewUrl = '__error__';  // resetează saving în Alpine
            return;
        }

        $template->update(['preview_image' => $filename]);
        $this->previewUrl = Storage::disk('public')->url($filename) . '?t=' . time();

        Notification::make()->title('Preview actualizat')->success()->send();
    }
}

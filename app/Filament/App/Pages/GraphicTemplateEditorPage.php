<?php

namespace App\Filament\App\Pages;

use App\Models\GraphicTemplate;
use App\Services\SocialMedia\NodeImageRenderer;
use Filament\Forms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;

class GraphicTemplateEditorPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-paint-brush';
    protected static ?string $navigationLabel = 'Editor Template';
    protected static string|\UnitEnum|null $navigationGroup = 'Social Media';
    protected static ?int    $navigationSort  = 11;
    protected string  $view            = 'filament.app.pages.graphic-template-editor';

    public static function shouldRegisterNavigation(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    public static function canAccess(): bool
    {
        return \App\Models\RolePermission::check(static::class, 'can_access');
    }

    // URL slug-ul templateului curent
    public ?int $templateId = null;

    // Câmpuri formular
    public string  $name               = '';
    public string  $templateLayout             = 'product';
    public string  $primary_color      = '#a52a3f';
    public string  $bottom_text        = '';
    public string  $bottom_subtext     = '';
    public string  $cta_text           = '';
    public bool    $show_rainbow_bar   = true;
    public bool    $show_truck         = true;
    public float   $logo_scale         = 0.36;
    public float   $title_size_pct     = 0.075;
    public float   $subtitle_size_pct  = 0.029;

    // Preview
    public ?string $previewUrl   = null;
    public bool    $isRendering  = false;
    public string  $previewTitle = 'Izolație Termică Superioară';
    public string  $previewSub   = 'Parteneri de încredere pentru construcții solide';
    public string  $previewLabel = 'PROMO';

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
        $this->templateId        = $template->id;
        $this->name              = $template->name;
        $this->templateLayout            = $template->layout ?? 'product';

        $c = $template->config ?? [];
        $this->primary_color     = $c['primary_color']     ?? '#a52a3f';
        $this->bottom_text       = $c['bottom_text']       ?? '';
        $this->bottom_subtext    = $c['bottom_subtext']    ?? '';
        $this->cta_text          = $c['cta_text']          ?? 'malinco.ro  →';
        $this->show_rainbow_bar  = (bool) ($c['show_rainbow_bar']  ?? true);
        $this->show_truck        = (bool) ($c['show_truck']        ?? true);
        $this->logo_scale        = (float) ($c['logo_scale']       ?? 0.36);
        $this->title_size_pct    = (float) ($c['title_size_pct']   ?? 0.075);
        $this->subtitle_size_pct = (float) ($c['subtitle_size_pct'] ?? 0.029);

        if ($template->preview_image) {
            $this->previewUrl = Storage::disk('public')->url($template->preview_image);
        }
    }

    public function getTemplates(): \Illuminate\Support\Collection
    {
        return GraphicTemplate::orderBy('layout')->orderBy('name')->get();
    }

    public function switchTemplate(int $id): void
    {
        $template = GraphicTemplate::findOrFail($id);
        $this->loadTemplate($template);
    }

    public function save(): void
    {
        if (! $this->templateId) return;

        $template = GraphicTemplate::findOrFail($this->templateId);
        $template->update([
            'name'   => $this->name,
            'layout' => $this->templateLayout,
            'config' => [
                'layout'             => $this->templateLayout,
                'primary_color'      => $this->primary_color,
                'bottom_text'        => $this->bottom_text,
                'bottom_subtext'     => $this->bottom_subtext,
                'cta_text'           => $this->cta_text,
                'show_rainbow_bar'   => $this->show_rainbow_bar,
                'show_truck'         => $this->show_truck,
                'logo_scale'         => $this->logo_scale,
                'title_size_pct'     => $this->title_size_pct,
                'subtitle_size_pct'  => $this->subtitle_size_pct,
            ],
        ]);

        Notification::make()->title('Salvat')->success()->send();
    }

    public function generatePreview(): void
    {
        $this->save();

        $template = GraphicTemplate::findOrFail($this->templateId);
        $renderer = app(NodeImageRenderer::class);

        if (! $renderer->isAvailable()) {
            Notification::make()->title('Node renderer indisponibil')->danger()->send();
            return;
        }

        // Pentru brand layout, găsim un logo brand disponibil ca demo
        $brandLogoPath = null;
        if ($this->templateLayout === 'brand') {
            $brandLogoPath = $this->findDemoBrandLogo();
        }

        $filename = $renderer->render(
            postId:         'tpl_live_' . $template->id,
            brandLogoPath:  $brandLogoPath,
            title:          $this->previewTitle,
            subtitle:       $this->previewSub,
            label:          $this->previewLabel,
            templateConfig: $template->fresh()->config,
        );

        if (! $filename) {
            Notification::make()->title('Eroare la generare preview')->danger()->send();
            return;
        }

        $template->update(['preview_image' => $filename]);
        $this->previewUrl = Storage::disk('public')->url($filename) . '?t=' . time();

        Notification::make()->title('Preview actualizat')->success()->send();
    }

    protected function findDemoBrandLogo(): ?string
    {
        // Caută primul logo brand disponibil local
        $path = Storage::disk('public')->path('brand-logos');
        if (is_dir($path)) {
            foreach (scandir($path) as $file) {
                if (! in_array($file, ['.', '..'])) {
                    return $path . '/' . $file;
                }
            }
        }
        return null;
    }

    public static function getNavigationUrl(): string
    {
        return static::getUrl();
    }
}

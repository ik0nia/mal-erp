<?php

namespace App\Http\Controllers;

use App\Models\GraphicTemplate;
use App\Services\SocialMedia\NodeImageRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GraphicTemplateController extends Controller
{
    public function show(GraphicTemplate $template)
    {
        $templates = GraphicTemplate::orderBy('layout')->orderBy('name')->get();
        return view('template-editor', compact('template', 'templates'));
    }

    public function save(Request $request, GraphicTemplate $template)
    {
        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'layout'             => 'required|in:product,brand',
            'primary_color'      => 'required|string|max:20',
            'bottom_text'        => 'nullable|string|max:200',
            'bottom_subtext'     => 'nullable|string|max:300',
            'cta_text'           => 'nullable|string|max:60',
            'show_rainbow_bar'   => 'nullable',
            'show_truck'         => 'nullable',
            'logo_scale'         => 'numeric|min:0.1|max:0.8',
            'title_size_pct'     => 'numeric|min:0.02|max:0.15',
            'subtitle_size_pct'  => 'numeric|min:0.01|max:0.08',
            'element_positions'  => 'nullable|array',
            'canvas_json'        => 'nullable|string',
            'canvas'             => 'nullable|array',
            'canvas.width'       => 'nullable|integer|min:100|max:4096',
            'canvas.height'      => 'nullable|integer|min:100|max:4096',
            'canvas.preset'      => 'nullable|string|max:50',
        ]);

        $config = $template->config ?? [];
        foreach (['primary_color','bottom_text','bottom_subtext','cta_text','logo_scale','title_size_pct','subtitle_size_pct'] as $key) {
            if (array_key_exists($key, $data)) $config[$key] = $data[$key];
        }
        $config['show_rainbow_bar'] = (bool) ($data['show_rainbow_bar'] ?? true);
        $config['show_truck']       = (bool) ($data['show_truck'] ?? true);
        $config['layout'] = $data['layout'];
        if (isset($data['element_positions'])) {
            $config['element_positions'] = $data['element_positions'];
        }
        if (array_key_exists('canvas_json', $data)) {
            $config['canvas_json'] = $data['canvas_json'];
        }
        if (!empty($data['canvas'])) {
            $config['canvas'] = $data['canvas'];
        }

        $template->update([
            'name'   => $data['name'],
            'layout' => $data['layout'],
            'config' => $config,
        ]);

        return response()->json(['ok' => true]);
    }

    public function preview(Request $request, GraphicTemplate $template)
    {
        $renderer = app(NodeImageRenderer::class);

        $brandLogoPath = null;
        if ($template->layout === 'brand') {
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
            title:          $request->input('preview_title', 'Izolație Termică Superioară'),
            subtitle:       $request->input('preview_subtitle', 'Parteneri de încredere pentru construcții solide'),
            label:          $request->input('preview_label', $template->layout === 'brand' ? 'BRAND PARTENER' : 'PROMO'),
            templateConfig: $template->fresh()->config,
        );

        if (! $filename) {
            return response()->json(['error' => 'Eroare la generare imagine'], 500);
        }

        $template->update(['preview_image' => $filename]);

        return response()->json([
            'url' => Storage::disk('public')->url($filename) . '?t=' . time(),
        ]);
    }
}

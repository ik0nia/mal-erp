<!DOCTYPE html>
<html lang="ro">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Editor Template — {{ $template->name }}</title>
  <link rel="stylesheet" href="/editor/editor.css">
  <!-- Google Fonts for the font list used in properties panel -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700&family=Montserrat:wght@400;700&family=Open+Sans:wght@400;700&family=Oswald:wght@400;700&family=Playfair+Display:wght@400;700&family=Raleway:wght@400;700&family=Roboto:wght@400;700&display=swap" rel="stylesheet">
  <!-- Fabric.js — must load synchronously, before any editor scripts -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js"></script>
</head>
<body style="margin:0;overflow:hidden">

<div id="editor-root">
  <!-- Toolbar — rendered and wired by Toolbar class -->
  <div id="toolbar"></div>

  <div id="editor-body">

    <!-- Layers panel — rendered by LayersPanel class -->
    <aside id="layers-panel"></aside>

    <!-- Canvas area -->
    <main id="canvas-area">
      <div id="canvas-wrap">
        <canvas id="c"></canvas>
      </div>
      <div id="zoom-controls">
        <span style="font-size:11px;color:#94a3b8">
          Drag = mută &nbsp;·&nbsp; Colțuri = resize &nbsp;·&nbsp;
          Click dreapta = aduce în față &nbsp;·&nbsp; Shift+Click dreapta = trimite în spate
        </span>
      </div>
    </main>

    <!-- Properties panel + preview section -->
    <aside id="props-panel">
      {{-- PropertiesPanel renders the top portion dynamically --}}

      <!-- Preview section — always at bottom of props panel -->
      <div id="preview-section"
           style="padding:14px;border-top:1px solid #f1f5f9;margin-top:auto;flex-shrink:0">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin-bottom:8px">
          Preview generat
        </div>
        <div id="preview-empty"
             style="aspect-ratio:1;background:#f8fafc;border:2px dashed #e2e8f0;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:11px;color:#94a3b8">
          Apasă Preview
        </div>
        <div id="preview-result" style="display:none">
          <img id="preview-img" src="" alt="Preview"
               style="width:100%;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.1)">
          <a id="preview-link" href="#" target="_blank"
             style="font-size:11px;display:block;margin-top:4px;color:#6366f1;text-decoration:none">
            Deschide complet →
          </a>
        </div>
      </div>
    </aside>

  </div>{{-- /#editor-body --}}
</div>{{-- /#editor-root --}}

<!-- Status toast -->
<div id="status-msg"
     style="display:none;position:fixed;bottom:20px;right:20px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:500;z-index:9999">
</div>

{{-- ── Bootstrap data passed from PHP ──────────────────────────────────── --}}
<script>
  window.CSRF          = document.querySelector('meta[name="csrf-token"]').content;
  window.TEMPLATE_DATA = @json($template);
  window.ALL_TEMPLATES = @json($templates);
</script>

{{-- ── Editor modules in dependency order ──────────────────────────────── --}}
@php $v = filemtime(public_path('editor/editor-core.js')); @endphp
<script src="/editor/utils.js?v={{ $v }}"></script>
<script src="/editor/state-manager.js?v={{ $v }}"></script>
<script src="/editor/canvas-presets.js?v={{ $v }}"></script>
<script src="/editor/canvas-manager.js?v={{ $v }}"></script>
<script src="/editor/element-factory.js?v={{ $v }}"></script>
<script src="/editor/zoom-manager.js?v={{ $v }}"></script>
<script src="/editor/layers-panel.js?v={{ $v }}"></script>
<script src="/editor/properties-panel.js?v={{ $v }}"></script>
<script src="/editor/alignment-tools.js?v={{ $v }}"></script>
<script src="/editor/toolbar.js?v={{ $v }}"></script>
<script src="/editor/editor-core.js?v={{ $v }}"></script>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    window.EDITOR = new EditorCore(TEMPLATE_DATA, ALL_TEMPLATES);

  });
</script>
</body>
</html>

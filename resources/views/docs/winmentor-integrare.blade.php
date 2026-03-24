<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WinMentor — Plan Integrare ERP</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 15px;
            line-height: 1.7;
            color: #1a1a2e;
            background: #f5f6fa;
        }

        .sidebar {
            position: fixed;
            top: 0; left: 0;
            width: 248px;
            height: 100vh;
            background: #0f172a;
            overflow-y: auto;
            padding: 24px 0;
            z-index: 100;
        }

        .sidebar-logo {
            padding: 0 20px 20px;
            border-bottom: 1px solid #1e293b;
            margin-bottom: 16px;
        }

        .sidebar-logo span {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #475569;
            margin-bottom: 4px;
        }

        .sidebar-logo strong {
            color: #f1f5f9;
            font-size: 14px;
        }

        .sidebar-logo .badge {
            display: inline-block;
            margin-top: 6px;
            background: #16a34a;
            color: #fff;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 99px;
            letter-spacing: 0.5px;
        }

        .sidebar nav a {
            display: block;
            padding: 5px 20px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 13px;
            transition: color 0.15s, background 0.15s;
            border-left: 3px solid transparent;
        }

        .sidebar nav a:hover { color: #f1f5f9; background: #1e293b; border-left-color: #6366f1; }
        .sidebar nav a.active { color: #f1f5f9; border-left-color: #6366f1; background: #1e293b; }

        .sidebar nav .section-label {
            padding: 14px 20px 4px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #334155;
        }

        .doc-links {
            margin: 16px 12px 0;
            border-top: 1px solid #1e293b;
            padding-top: 16px;
        }

        .doc-links a {
            display: block;
            padding: 6px 8px;
            color: #6366f1;
            text-decoration: none;
            font-size: 12px;
            border-radius: 6px;
        }

        .doc-links a:hover { background: #1e293b; }

        .content {
            margin-left: 248px;
            padding: 48px 64px;
            max-width: 1080px;
        }

        /* Prose */
        .prose h1 {
            font-size: 1.9rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 8px;
            padding-bottom: 16px;
            border-bottom: 2px solid #e2e8f0;
        }

        .prose h2 {
            font-size: 1.3rem;
            font-weight: 700;
            color: #0f172a;
            margin-top: 52px;
            margin-bottom: 16px;
            padding: 14px 18px;
            background: #f8fafc;
            border-left: 4px solid #6366f1;
            border-radius: 0 8px 8px 0;
        }

        .prose h3 {
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
            margin-top: 28px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .prose p { margin-bottom: 14px; color: #475569; }

        .prose blockquote {
            border-left: 4px solid #f59e0b;
            background: #fffbeb;
            padding: 12px 20px;
            margin: 20px 0;
            border-radius: 0 8px 8px 0;
            color: #92400e;
            font-size: 14px;
        }

        .prose blockquote p { margin: 0; color: inherit; }

        .prose table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 13.5px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.07);
            border-radius: 8px;
            overflow: hidden;
        }

        .prose thead th {
            background: #0f172a;
            color: #e2e8f0;
            padding: 10px 14px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .prose tbody tr:nth-child(even) { background: #f8fafc; }
        .prose tbody tr:hover { background: #f0f9ff; }

        .prose td {
            padding: 9px 14px;
            border-bottom: 1px solid #e2e8f0;
            color: #374151;
            vertical-align: top;
        }

        /* Priority colors in table */
        .prose td:last-child { font-weight: 500; }

        .prose code {
            background: #f1f5f9;
            color: #7c3aed;
            padding: 2px 7px;
            border-radius: 4px;
            font-family: 'JetBrains Mono', 'Fira Code', Consolas, monospace;
            font-size: 0.87em;
        }

        .prose pre {
            background: #0f172a;
            color: #e2e8f0;
            padding: 20px 24px;
            border-radius: 10px;
            overflow-x: auto;
            margin: 20px 0;
            font-size: 13px;
            line-height: 1.6;
            border: 1px solid #1e293b;
        }

        .prose pre code {
            background: none;
            color: #7dd3fc;
            padding: 0;
            font-size: inherit;
        }

        .prose ul, .prose ol {
            padding-left: 24px;
            margin-bottom: 14px;
            color: #475569;
        }

        .prose li { margin-bottom: 5px; }
        .prose strong { color: #0f172a; font-weight: 600; }

        .prose hr {
            border: none;
            border-top: 2px solid #e2e8f0;
            margin: 44px 0;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #6366f1;
            text-decoration: none;
            font-size: 13px;
            margin-bottom: 32px;
            padding: 6px 12px;
            background: #eef2ff;
            border-radius: 6px;
        }
        .back-link:hover { background: #e0e7ff; }

        @media (max-width: 900px) {
            .sidebar { display: none; }
            .content { margin-left: 0; padding: 24px 20px; }
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-logo">
        <span>ERP Malinco</span>
        <strong>WinMentor — Plan Integrare</strong>
        <span class="badge">2026-03-23</span>
    </div>
    <nav id="sidebar-nav">
        <!-- generat dinamic -->
    </nav>
    <div class="doc-links">
        <a href="/docs/winmentor">← API Reference Bridge</a>
        <a href="/app">← Înapoi la ERP</a>
    </div>
</aside>

<main class="content">
    <a href="/docs/winmentor" class="back-link">← API Reference Bridge</a>
    <article class="prose">
        {!! $content !!}
    </article>
</main>

<script>
    function slugify(text) {
        return text.toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s-]/g, '')
            .trim().replace(/\s+/g, '-').replace(/-+/g, '-');
    }

    const headings = document.querySelectorAll('.prose h2, .prose h3');
    const usedIds = {};
    headings.forEach(h => {
        let base = slugify(h.textContent);
        if (!base) base = 'section';
        let id = base;
        if (usedIds[id]) { usedIds[id]++; id = base + '-' + usedIds[base]; }
        else usedIds[id] = 1;
        h.id = id;
    });

    // Sidebar dinamic
    const nav = document.getElementById('sidebar-nav');
    headings.forEach(h => {
        const a = document.createElement('a');
        a.href = '#' + h.id;
        a.textContent = h.textContent;
        if (h.tagName === 'H3') {
            a.style.paddingLeft = '32px';
            a.style.fontSize = '12px';
            a.style.color = '#475569';
        }
        nav.appendChild(a);
    });

    // Highlight activ
    const allLinks = nav.querySelectorAll('a');
    const observer = new IntersectionObserver(entries => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                allLinks.forEach(l => l.classList.remove('active'));
                const match = nav.querySelector(`a[href="#${e.target.id}"]`);
                if (match) match.classList.add('active');
            }
        });
    }, { rootMargin: '-10% 0px -80% 0px' });

    headings.forEach(h => observer.observe(h));
</script>
</body>
</html>

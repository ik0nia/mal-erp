<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WinMentor Bridge — Documentație</title>
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
            width: 240px;
            height: 100vh;
            background: #1a1a2e;
            color: #a0aec0;
            overflow-y: auto;
            padding: 24px 0;
            z-index: 100;
        }

        .sidebar-logo {
            padding: 0 20px 20px;
            border-bottom: 1px solid #2d3748;
            margin-bottom: 16px;
        }

        .sidebar-logo span {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #4a5568;
            margin-bottom: 4px;
        }

        .sidebar-logo strong {
            color: #fff;
            font-size: 15px;
        }

        .sidebar nav a {
            display: block;
            padding: 6px 20px;
            color: #a0aec0;
            text-decoration: none;
            font-size: 13px;
            transition: color 0.15s, background 0.15s;
            border-left: 3px solid transparent;
        }

        .sidebar nav a:hover {
            color: #fff;
            background: #2d3748;
            border-left-color: #6366f1;
        }

        .sidebar nav a.active {
            color: #fff;
            border-left-color: #6366f1;
        }

        .sidebar nav .section-label {
            padding: 16px 20px 4px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #4a5568;
        }

        .content {
            margin-left: 240px;
            padding: 48px 64px;
            max-width: 1100px;
        }

        /* Markdown styles */
        .prose h1 {
            font-size: 2rem;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 8px;
            padding-bottom: 16px;
            border-bottom: 2px solid #e2e8f0;
        }

        .prose h2 {
            font-size: 1.35rem;
            font-weight: 700;
            color: #1a1a2e;
            margin-top: 48px;
            margin-bottom: 16px;
            padding-top: 16px;
            border-top: 1px solid #e2e8f0;
        }

        .prose h3 {
            font-size: 1.05rem;
            font-weight: 600;
            color: #2d3748;
            margin-top: 28px;
            margin-bottom: 10px;
        }

        .prose p {
            margin-bottom: 14px;
            color: #4a5568;
        }

        .prose blockquote {
            border-left: 4px solid #6366f1;
            background: #eef2ff;
            padding: 12px 20px;
            margin: 20px 0;
            border-radius: 0 8px 8px 0;
            color: #4338ca;
            font-size: 14px;
        }

        .prose blockquote p { margin: 0; color: inherit; }

        .prose table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 13.5px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            border-radius: 8px;
            overflow: hidden;
        }

        .prose thead th {
            background: #1a1a2e;
            color: #e2e8f0;
            padding: 10px 14px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .prose tbody tr:nth-child(even) { background: #f8fafc; }
        .prose tbody tr:hover { background: #eef2ff; }

        .prose td {
            padding: 9px 14px;
            border-bottom: 1px solid #e2e8f0;
            color: #374151;
            vertical-align: top;
        }

        .prose code {
            background: #eef2ff;
            color: #4338ca;
            padding: 2px 7px;
            border-radius: 4px;
            font-family: 'JetBrains Mono', 'Fira Code', Consolas, monospace;
            font-size: 0.88em;
        }

        .prose pre {
            background: #1a1a2e;
            color: #e2e8f0;
            padding: 20px 24px;
            border-radius: 10px;
            overflow-x: auto;
            margin: 20px 0;
            font-size: 13px;
            line-height: 1.6;
        }

        .prose pre code {
            background: none;
            color: inherit;
            padding: 0;
            font-size: inherit;
        }

        .prose ul, .prose ol {
            padding-left: 24px;
            margin-bottom: 14px;
            color: #4a5568;
        }

        .prose li { margin-bottom: 5px; }

        .prose strong { color: #1a1a2e; font-weight: 600; }

        .prose hr {
            border: none;
            border-top: 2px solid #e2e8f0;
            margin: 40px 0;
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
        <strong>WinMentor Bridge</strong>
    </div>
    <nav id="sidebar-nav">
        <!-- generat dinamic din headings -->
    </nav>
</aside>

<main class="content">
    <a href="/app" class="back-link">← Înapoi la ERP</a>
    <article class="prose">
        {!! $content !!}
    </article>
</main>

<script>
    function slugify(text) {
        return text.toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // diacritice unicode
            .replace(/[^a-z0-9\s-]/g, '')
            .trim().replace(/\s+/g, '-').replace(/-+/g, '-');
    }

    // 1. Atribuie ID fiecărui heading h2/h3
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

    // 2. Construiește sidebar-ul dinamic
    const nav = document.getElementById('sidebar-nav');
    headings.forEach(h => {
        if (h.tagName === 'H2') {
            const a = document.createElement('a');
            a.href = '#' + h.id;
            a.textContent = h.textContent;
            nav.appendChild(a);
        }
        // H3 — indentat, mai mic
        if (h.tagName === 'H3') {
            const a = document.createElement('a');
            a.href = '#' + h.id;
            a.textContent = h.textContent;
            a.style.paddingLeft = '32px';
            a.style.fontSize = '12px';
            a.style.color = '#718096';
            nav.appendChild(a);
        }
    });

    // 3. Highlight activ pe scroll
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

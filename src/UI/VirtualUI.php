<?php

declare(strict_types=1);

namespace App\UI;

/**
 * Shared chrome for every virtual-provider dashboard page.
 *
 * Each provider's controller calls `VirtualUI::renderPage(...)` with its own
 * accent color, title, and body HTML. The helper emits header + "VIRTUAL SERVICE"
 * badge + container + standard CSS so provider pages look consistent but distinct.
 *
 * Design system: Material Design 3 (https://m3.material.io/)
 *   - Material Web Components via CDN (@material/web)
 *   - MD3 color tokens for surface, on-surface, primary, etc.
 *   - Roboto font (MD3 default typeface)
 *
 * Pages are test-ops tools, not pixel-perfect bank clones. Branding is:
 *   - provider name + subtitle in colored header
 *   - scenario buttons with clear action labels
 *   - state tables with status badges
 * Developers should immediately know: which provider, what state, what actions.
 */
final class VirtualUI
{
    /**
     * @param string $providerName   Short title shown in header (e.g. "Transfero")
     * @param string $subtitle       One-liner below title (e.g. "openbanking.bit.one: PIX payouts")
     * @param string $headerGradient CSS linear-gradient expression (e.g. "135deg, #047857 0%, #10b981 100%")
     * @param string $accentColor    Primary button / badge color (used as MD3 --md-sys-color-primary override)
     * @param string $body           HTML body content (everything between header and footer)
     */
    public static function renderPage(
        string $providerName,
        string $subtitle,
        string $headerGradient,
        string $accentColor,
        string $body,
    ): never {
        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(200);

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$providerName} Virtual | AlfredPay Service Virtualization</title>

    <!-- MD3: Roboto typeface -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&family=Roboto+Mono:wght@400;500&display=swap" rel="stylesheet">

    <!-- Material Symbols (MD3 icon font) -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">

    <!-- Material Web Components (MD3) -->
    <script type="importmap">
      {
        "imports": {
          "@material/web/": "https://esm.run/@material/web/"
        }
      }
    </script>
    <script type="module">
      import '@material/web/all.js';
      import {styles as typescaleStyles} from '@material/web/typography/md-typescale-styles.js';
      document.adoptedStyleSheets.push(typescaleStyles.styleSheet);
    </script>

    <style>
        /* ── MD3 system color token overrides (provider accent) ── */
        :root {
            --md-sys-color-primary:             {$accentColor};
            --md-sys-color-on-primary:          #ffffff;
            --md-sys-color-primary-container:   color-mix(in srgb, {$accentColor} 15%, #ffffff);
            --md-sys-color-on-primary-container:{$accentColor};
            --md-sys-color-surface:             #f4f6f8;
            --md-sys-color-on-surface:          #1a1c1e;
            --md-sys-color-surface-container:   #ffffff;
            --md-sys-color-outline:             #c4c7c5;
            --md-sys-color-outline-variant:     #e2e8f0;
            --md-ref-typeface-brand:            'Roboto', sans-serif;
            --md-ref-typeface-plain:            'Roboto', sans-serif;
        }

        /* ── Base ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: var(--md-ref-typeface-plain);
            background: var(--md-sys-color-surface);
            color: var(--md-sys-color-on-surface);
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
        }

        /* ── Virtual-service badge (MD3 Assist Chip style) ── */
        .vs-badge {
            position: fixed; top: 12px; right: 12px; z-index: 200;
            display: inline-flex; align-items: center; gap: 4px;
            background: #fff8e1; color: #7c5700;
            padding: 4px 12px; border-radius: 8px;
            font-size: 11px; font-weight: 700; letter-spacing: 0.5px;
            border: 1px solid #f9a825;
            box-shadow: 0 1px 2px rgba(0,0,0,0.12);
        }
        .vs-badge .material-symbols-outlined { font-size: 14px; }

        /* ── App bar / header (MD3 Top App Bar, large) ── */
        header.vpage {
            background: linear-gradient({$headerGradient});
            color: #ffffff;
            padding: 1.5rem 2rem 1.75rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.18);
        }
        header.vpage h1 {
            font-size: 1.75rem; font-weight: 700; letter-spacing: -0.01em;
            line-height: 1.2;
        }
        header.vpage .subtitle {
            font-size: 0.875rem; opacity: 0.88; margin-top: 0.3rem;
            font-weight: 400;
        }

        /* ── Layout ── */
        .container { max-width: 1200px; margin: 0 auto; padding: 1.5rem; }

        /* ── MD3 Card (Filled card) ── */
        .card {
            background: var(--md-sys-color-surface-container);
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 1px 2px rgba(0,0,0,0.06), 0 1px 3px rgba(0,0,0,0.08);
            margin-bottom: 1.25rem;
        }
        .card h2 {
            font-size: 0.9375rem; font-weight: 600; margin-bottom: 0.875rem;
            display: flex; align-items: center; gap: 0.5rem;
            color: var(--md-sys-color-on-surface);
        }
        /* MD3 left-border accent indicator */
        .card h2::before {
            content: ''; display: block; width: 4px; height: 20px;
            background: var(--md-sys-color-primary); border-radius: 2px;
            flex-shrink: 0;
        }
        .empty-hint {
            color: var(--md-sys-color-outline);
            font-size: 0.875rem; padding: 1rem 0;
        }

        /* ── MD3 Data table ── */
        table {
            width: 100%; border-collapse: collapse; font-size: 0.85rem;
        }
        th {
            text-align: left; padding: 0.5rem 0.75rem; font-weight: 500;
            color: var(--md-sys-color-outline);
            border-bottom: 1px solid var(--md-sys-color-outline-variant);
            font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;
        }
        td {
            padding: 0.75rem 0.75rem;
            border-bottom: 1px solid var(--md-sys-color-outline-variant);
            vertical-align: middle;
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: color-mix(in srgb, var(--md-sys-color-primary) 6%, transparent); }
        .mono { font-family: 'Roboto Mono', monospace; font-size: 0.8rem; }

        /* ── MD3 Status badges (Tonal chips) ── */
        .badge {
            display: inline-flex; align-items: center;
            padding: 3px 10px; border-radius: 8px;
            font-size: 0.7rem; font-weight: 600; letter-spacing: 0.04em;
        }
        .b-success { background: #d1fae5; color: #065f46; }
        .b-pending  { background: #fef3c7; color: #92400e; }
        .b-failed   { background: #fee2e2; color: #991b1b; }
        .b-neutral  { background: #e2e8f0; color: #475569; }

        /* ── MD3 Action buttons (Text / Outlined / Filled) ── */
        .actions { display: flex; gap: 0.375rem; flex-wrap: wrap; align-items: center; }

        /* Scenario buttons: MD3 Outlined Button style */
        button.scenario {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 0.3rem 0.75rem;
            border: 1px solid var(--md-sys-color-outline);
            background: transparent;
            border-radius: 20px;                /* MD3 full-pill shape */
            font-family: var(--md-ref-typeface-plain);
            font-size: 0.75rem; font-weight: 500; cursor: pointer;
            transition: background 0.15s, border-color 0.15s;
            color: var(--md-sys-color-on-surface);
            letter-spacing: 0.01em;
        }
        button.scenario:hover {
            background: color-mix(in srgb, var(--md-sys-color-on-surface) 8%, transparent);
        }
        button.scenario.approve {
            color: #065f46; border-color: #6ee7b7;
        }
        button.scenario.approve:hover { background: #d1fae5; }
        button.scenario.decline {
            color: #991b1b; border-color: #fca5a5;
        }
        button.scenario.decline:hover { background: #fee2e2; }

        /* Primary action: MD3 Filled Button */
        .btn-primary {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 0.625rem 1.25rem;
            background: var(--md-sys-color-primary);
            color: var(--md-sys-color-on-primary);
            border: none; border-radius: 20px;
            font-family: var(--md-ref-typeface-plain);
            font-size: 0.875rem; font-weight: 500; cursor: pointer;
            box-shadow: 0 1px 2px rgba(0,0,0,0.2);
            transition: box-shadow 0.15s, filter 0.15s;
            letter-spacing: 0.01em;
        }
        .btn-primary:hover {
            filter: brightness(1.08);
            box-shadow: 0 2px 6px rgba(0,0,0,0.22);
        }

        /* ── MD3 Snackbar (toast) ── */
        .toast {
            position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
            background: #1a1c1e; color: #e2e8f0;
            padding: 0.875rem 1.5rem;
            border-radius: 4px;
            font-size: 0.875rem; font-weight: 500;
            box-shadow: 0 4px 12px rgba(0,0,0,0.25);
            opacity: 0; transition: opacity 0.2s;
            z-index: 1000; pointer-events: none;
            min-width: 240px; text-align: center;
        }
        .toast.show { opacity: 1; }
        .toast.err { background: #b3261e; color: #ffffff; }

        /* ── Utilities ── */
        .refresh-hint { color: var(--md-sys-color-outline); font-size: 0.75rem; margin-left: auto; }
        .row-flex { display: flex; align-items: center; gap: 0.5rem; }
    </style>
</head>
<body>
    <div class="vs-badge">
        <span class="material-symbols-outlined">science</span>
        VIRTUAL SERVICE
    </div>

    <header class="vpage">
        <h1>{$providerName}</h1>
        <div class="subtitle">{$subtitle}</div>
    </header>

    <main class="container">
        {$body}
    </main>

    <div id="toast" class="toast"></div>

    <script>
        function toast(msg, isErr) {
            const el = document.getElementById('toast');
            el.textContent = msg;
            el.classList.toggle('err', !!isErr);
            el.classList.add('show');
            setTimeout(() => el.classList.remove('show'), 2400);
        }
        async function action(url, body, label) {
            try {
                const r = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: body ? JSON.stringify(body) : null,
                });
                const j = await r.json().catch(() => ({}));
                if (r.ok) {
                    toast(label + ': ok');
                    setTimeout(() => location.reload(), 700);
                } else {
                    toast((j.error || r.statusText) + '', true);
                }
            } catch (e) {
                toast(e.message, true);
            }
        }
    </script>
</body>
</html>
HTML;
        exit;
    }
}

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin Panel') - WC-Info API</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script>
        (function() {
            try {
                const savedTheme = localStorage.getItem('admin_theme');
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                const theme = savedTheme ? savedTheme : (prefersDark ? 'dark' : 'light');
                document.documentElement.setAttribute('data-theme', theme);
            } catch (e) {}
        })();
    </script>
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-light: #eff6ff;
            --success: #16a34a;
            --success-light: #f0fdf4;
            --warning: #d97706;
            --warning-light: #fffbeb;
            --danger: #dc2626;
            --danger-light: #fef2f2;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --radius-sm: 4px;
            --radius-md: 8px;
            --radius-lg: 12px;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);

            /* Semantic Theme Variables */
            --bg-body: #f1f5f9;
            --bg-card: #ffffff;
            --bg-card-header: #ffffff;
            --bg-subtle: #f8fafc;
            --bg-input: #ffffff;
            --text-main: #1f2937;
            --text-heading: #111827;
            --text-muted: #6b7280;
            --border-main: #e2e8f0;
            --border-subtle: #f1f5f9;
            --border-input: #d1d5db;
            --navbar-bg: #ffffff;
            --table-row-hover: #f8fafc;
            --btn-sec-bg: #ffffff;
            --btn-sec-border: #d1d5db;
            --btn-sec-color: #374151;
            --btn-sec-hover-bg: #f9fafb;
            --btn-sec-hover-border: #9ca3af;
            --btn-sec-hover-color: #111827;
            --badge-hidden-bg: #f3f4f6;
            --badge-hidden-color: #4b5563;
            --badge-unqual-bg: #f1f5f9;
            --badge-unqual-color: #64748b;
        }

        [data-theme="dark"] {
            --primary: #3b82f6;
            --primary-hover: #60a5fa;
            --primary-light: rgba(59, 130, 246, 0.18);
            --success: #22c55e;
            --success-light: rgba(34, 197, 94, 0.18);
            --warning: #f59e0b;
            --warning-light: rgba(245, 158, 11, 0.18);
            --danger: #ef4444;
            --danger-light: rgba(239, 68, 68, 0.18);
            --gray-50: #1e293b;
            --gray-100: #1e293b;
            --gray-200: #334155;
            --gray-300: #475569;
            --gray-400: #94a3b8;
            --gray-500: #94a3b8;
            --gray-600: #cbd5e1;
            --gray-700: #e2e8f0;
            --gray-800: #f1f5f9;
            --gray-900: #f8fafc;

            /* Semantic Theme Variables */
            --bg-body: #0b0f19;
            --bg-card: #131b2e;
            --bg-card-header: #131b2e;
            --bg-subtle: #1e293b;
            --bg-input: #0b0f19;
            --text-main: #e2e8f0;
            --text-heading: #f8fafc;
            --text-muted: #94a3b8;
            --border-main: #243049;
            --border-subtle: #1e293b;
            --border-input: #334155;
            --navbar-bg: #131b2e;
            --table-row-hover: #1a233a;
            --btn-sec-bg: #1e293b;
            --btn-sec-border: #334155;
            --btn-sec-color: #e2e8f0;
            --btn-sec-hover-bg: #334155;
            --btn-sec-hover-border: #475569;
            --btn-sec-hover-color: #ffffff;
            --badge-hidden-bg: #1e293b;
            --badge-hidden-color: #cbd5e1;
            --badge-unqual-bg: #1e293b;
            --badge-unqual-color: #94a3b8;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.5);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.5);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.6);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        a {
            color: var(--primary);
            text-decoration: none;
            transition: color 0.15s ease;
        }

        a:hover {
            color: var(--primary-hover);
        }

        /* Top Navigation Bar */
        .navbar {
            background-color: var(--navbar-bg);
            border-bottom: 1px solid var(--border-main);
            padding: 0.75rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 50;
            box-shadow: var(--shadow-sm);
            transition: background-color 0.2s ease, border-color 0.2s ease;
        }

        .navbar-inner {
            max-width: 1600px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 700;
            font-size: 1.125rem;
            color: var(--text-heading);
        }

        .navbar-brand span.badge-admin {
            background-color: var(--primary-light);
            color: var(--primary);
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.125rem 0.5rem;
            border-radius: var(--radius-sm);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .navbar-search {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex: 1;
            max-width: 360px;
        }

        .navbar-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        /* Container */
        .container {
            max-width: 1600px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        /* Alerts & Flash Messages */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            font-size: 0.9375rem;
        }

        .alert-success {
            background-color: var(--success-light);
            border: 1px solid #bbf7d0;
            color: #15803d;
        }

        .alert-error {
            background-color: var(--danger-light);
            border: 1px solid #fecaca;
            color: #b91c1c;
        }

        .alert-info {
            background-color: var(--primary-light);
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
        }

        [data-theme="dark"] .alert-success {
            background-color: rgba(34, 197, 94, 0.15);
            border-color: rgba(34, 197, 94, 0.35);
            color: #86efac;
        }

        [data-theme="dark"] .alert-error {
            background-color: rgba(239, 68, 68, 0.15);
            border-color: rgba(239, 68, 68, 0.35);
            color: #fca5a5;
        }

        [data-theme="dark"] .alert-info {
            background-color: rgba(59, 130, 246, 0.15);
            border-color: rgba(59, 130, 246, 0.35);
            color: #93c5fd;
        }

        /* Cards */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-main);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            overflow: hidden;
            transition: background-color 0.2s ease, border-color 0.2s ease;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-main);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background-color: var(--bg-card-header);
            transition: background-color 0.2s ease, border-color 0.2s ease;
        }

        .card-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: var(--radius-md);
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.15s ease;
            text-decoration: none;
            line-height: 1.25rem;
        }

        .btn-primary {
            background-color: var(--primary);
            color: #ffffff;
        }
        .btn-primary:hover {
            background-color: var(--primary-hover);
            color: #ffffff;
        }

        .btn-secondary {
            background-color: var(--btn-sec-bg);
            border-color: var(--btn-sec-border);
            color: var(--btn-sec-color);
        }
        .btn-secondary:hover {
            background-color: var(--btn-sec-hover-bg);
            border-color: var(--btn-sec-hover-border);
            color: var(--btn-sec-hover-color);
        }

        .btn-success {
            background-color: var(--success);
            color: #ffffff;
        }
        .btn-success:hover {
            background-color: #15803d;
            color: #ffffff;
        }

        .btn-danger {
            background-color: var(--danger);
            color: #ffffff;
        }
        .btn-danger:hover {
            background-color: #b91c1c;
            color: #ffffff;
        }

        .btn-danger-outline {
            background-color: var(--btn-sec-bg);
            border-color: #fca5a5;
            color: var(--danger);
        }
        .btn-danger-outline:hover {
            background-color: var(--danger-light);
            border-color: var(--danger);
        }

        .btn-sm {
            padding: 0.25rem 0.625rem;
            font-size: 0.8125rem;
        }

        .btn-lg {
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
        }

        /* Forms */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 0.375rem;
        }

        .form-control, select.form-control, textarea.form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            color: var(--text-heading);
            background-color: var(--bg-input);
            border: 1px solid var(--border-input);
            border-radius: var(--radius-md);
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.2s ease, color 0.2s ease;
            font-family: inherit;
        }

        .form-control:focus, select.form-control:focus, textarea.form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        textarea.form-control {
            min-height: 80px;
            resize: vertical;
        }

        .form-hint {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }

        /* Switch / Checkbox */
        .checkbox-label {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-main);
            user-select: none;
        }

        .checkbox-label input[type="checkbox"] {
            width: 1.125rem;
            height: 1.125rem;
            accent-color: var(--primary);
            cursor: pointer;
        }

        /* Grid */
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1.25rem;
        }

        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1.25rem;
        }

        .grid-4 {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
        }

        @media (max-width: 768px) {
            .grid-2, .grid-3, .grid-4 {
                grid-template-columns: 1fr;
            }
            .navbar-inner {
                flex-direction: column;
                align-items: stretch;
            }
            .navbar-search {
                max-width: 100%;
            }
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.2rem 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            line-height: 1;
        }

        .badge-active {
            background-color: #dcfce7;
            color: #15803d;
        }

        .badge-hidden {
            background-color: var(--badge-hidden-bg);
            color: var(--badge-hidden-color);
        }

        .badge-deleted {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .badge-qualified {
            background-color: #e0e7ff;
            color: #4338ca;
        }

        .badge-unqualified {
            background-color: var(--badge-unqual-bg);
            color: var(--badge-unqual-color);
        }

        .badge-distance-near {
            background-color: #e0e7ff;
            color: #3730a3;
            font-weight: 600;
        }

        .badge-distance-far {
            background-color: #fee2e2;
            color: #dc2626;
            border: 1px solid #f87171;
            font-weight: 700;
        }

        [data-theme="dark"] .badge-distance-near {
            background-color: rgba(99, 102, 241, 0.25);
            color: #c7d2fe;
        }

        [data-theme="dark"] .badge-distance-far {
            background-color: rgba(220, 38, 38, 0.25);
            color: #fca5a5;
            border-color: #ef4444;
        }

        /* Status Selects */
        .status-select-active {
            background-color: #f0fdf4 !important;
            color: #166534 !important;
            border-color: #bbf7d0 !important;
        }
        .status-select-hidden {
            background-color: #f8fafc !important;
            color: #475569 !important;
            border-color: #cbd5e1 !important;
        }
        .status-select-deleted {
            background-color: #fef2f2 !important;
            color: #991b1b !important;
            border-color: #fecaca !important;
        }

        [data-theme="dark"] .status-select-active {
            background-color: rgba(34, 197, 94, 0.15) !important;
            color: #4ade80 !important;
            border-color: rgba(34, 197, 94, 0.35) !important;
        }
        [data-theme="dark"] .status-select-hidden {
            background-color: #1e293b !important;
            color: #94a3b8 !important;
            border-color: #334155 !important;
        }
        [data-theme="dark"] .status-select-deleted {
            background-color: rgba(239, 68, 68, 0.15) !important;
            color: #f87171 !important;
            border-color: rgba(239, 68, 68, 0.35) !important;
        }

        /* Flagged Header */
        .card-header-flagged {
            background: #fffdf5;
            border-bottom: 1px solid #fef3c7;
        }
        .card-header-flagged .card-title {
            color: #92400e;
        }
        .card-header-flagged .card-subtitle {
            font-size: 0.8125rem;
            color: #b45309;
        }

        [data-theme="dark"] .card-header-flagged {
            background: #241a0d !important;
            border-bottom: 1px solid #78350f !important;
        }
        [data-theme="dark"] .card-header-flagged .card-title {
            color: #fcd34d !important;
        }
        [data-theme="dark"] .card-header-flagged .card-subtitle {
            color: #fbbf24 !important;
        }

        /* AI Modal Styles */
        .ai-place-card {
            border: 2px solid #818cf8;
            background: #f5f3ff;
            border-radius: var(--radius-md);
            padding: 1.25rem;
            margin-bottom: 1.25rem;
        }
        .ai-place-card-title {
            font-size: 0.75rem;
            font-weight: 700;
            color: #4338ca;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .ai-reasoning-box {
            background: #ffffff;
            border-radius: var(--radius-sm);
            padding: 0.75rem;
            border: 1px solid #e0e7ff;
            font-size: 0.8125rem;
            color: #374151;
        }
        .ai-public-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: var(--radius-md);
            padding: 0.875rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }
        .ai-public-box label {
            font-size: 0.875rem;
            color: #166534;
            cursor: pointer;
            line-height: 1.4;
        }
        .ai-public-desc {
            font-size: 0.75rem;
            color: #15803d;
            margin-top: 0.125rem;
        }

        [data-theme="dark"] .ai-place-card {
            border-color: #6366f1;
            background: #181c3a;
        }
        [data-theme="dark"] .ai-place-card-title {
            color: #a5b4fc;
        }
        [data-theme="dark"] #ai-modal-place-name {
            color: #f8fafc !important;
        }
        [data-theme="dark"] .ai-reasoning-box {
            background: #0f172a;
            border-color: #312e81;
            color: #cbd5e1;
        }
        [data-theme="dark"] .ai-public-box {
            background: rgba(34, 197, 94, 0.12);
            border-color: rgba(34, 197, 94, 0.3);
        }
        [data-theme="dark"] .ai-public-box label {
            color: #86efac;
        }
        [data-theme="dark"] .ai-public-desc {
            color: #4ade80;
        }

        /* Tables */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        table.data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            text-align: left;
        }

        table.data-table th {
            background-color: var(--bg-subtle);
            padding: 0.75rem 1rem;
            font-weight: 600;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-main);
            text-transform: uppercase;
            font-size: 0.6875rem;
            letter-spacing: 0.05em;
        }

        table.data-table td {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid var(--border-main);
            vertical-align: middle;
            color: var(--text-main);
        }

        table.data-table tr:last-child td {
            border-bottom: none;
        }

        table.data-table tr:hover td {
            background-color: var(--table-row-hover);
        }

        /* Diff Display */
        .diff-container {
            background: #0b0f19;
            color: #e2e8f0;
            border: 1px solid var(--border-main);
            border-radius: var(--radius-md);
            padding: 1rem;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.8125rem;
            overflow-x: auto;
        }

        .diff-row {
            display: flex;
            align-items: baseline;
            gap: 1rem;
            padding: 0.25rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .diff-field {
            color: #38bdf8;
            font-weight: 600;
            min-width: 160px;
        }

        .diff-old {
            color: #f87171;
            text-decoration: line-through;
            opacity: 0.85;
        }

        .diff-new {
            color: #4ade80;
            font-weight: 500;
        }

        /* Photo Gallery */
        .photo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 1rem;
        }

        .photo-card {
            border: 1px solid var(--border-main);
            border-radius: var(--radius-md);
            overflow: hidden;
            background: var(--bg-card);
            display: flex;
            flex-direction: column;
        }

        .photo-card img {
            width: 100%;
            height: 160px;
            object-fit: cover;
            background-color: var(--bg-subtle);
        }

        .photo-card-info {
            padding: 0.75rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            flex: 1;
        }

        .photo-card-meta {
            font-size: 0.75rem;
            color: var(--text-muted);
            word-break: break-all;
        }

        /* Pagination */
        .pagination-container {
            padding: 0.75rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-top: 1px solid var(--border-main);
            background: var(--bg-card);
            color: var(--text-main);
            flex-wrap: wrap;
            gap: 0.75rem;
            font-size: 0.875rem;
        }
        nav[role="navigation"] svg {
            width: 1.25rem;
            height: 1.25rem;
            vertical-align: middle;
        }
    </style>
    @stack('styles')
</head>
<body>

    <nav class="navbar">
        <div class="navbar-inner">
            <a href="{{ route('admin.index') }}" class="navbar-brand">
                <span>🚽 WC-Info</span>
                <span class="badge-admin">Admin</span>
            </a>

            <form action="{{ route('admin.toilets.find') }}" method="POST" class="navbar-search">
                @csrf
                <input
                    type="number"
                    name="id"
                    class="form-control"
                    placeholder="Open Toilet by ID (e.g. 1234)..."
                    min="1"
                    required
                    value="{{ isset($toilet) ? $toilet->id : '' }}"
                >
                <button type="submit" class="btn btn-primary btn-sm">Open</button>
            </form>

            <div class="navbar-actions">
                <button type="button" id="theme-toggle-btn" class="btn btn-secondary btn-sm" onclick="toggleAdminTheme()" title="Toggle Dark / Light mode" style="display: flex; align-items: center; gap: 0.35rem;">
                    <span id="theme-toggle-icon">🌙</span>
                    <span id="theme-toggle-text">Dark</span>
                </button>
                <a href="{{ route('admin.costs') }}" class="btn btn-secondary btn-sm" style="display: flex; align-items: center; gap: 0.35rem;">
                    <span>📊</span>
                    <span>Costs & Budget</span>
                </a>
                <a href="{{ route('admin.index') }}" class="btn btn-secondary btn-sm">Dashboard</a>
                <form action="{{ route('admin.logout') }}" method="POST" style="display: inline;">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm">Logout</button>
                </form>
            </div>
        </div>
    </nav>

    <main class="container">
        @if (session('success'))
            <div class="alert alert-success">
                <span>✓</span>
                <div>{{ session('success') }}</div>
            </div>
        @endif

        @if (session('error'))
            <div class="alert alert-error">
                <span>⚠</span>
                <div>{{ session('error') }}</div>
            </div>
        @endif

        @if (session('info'))
            <div class="alert alert-info">
                <span>ℹ</span>
                <div>{{ session('info') }}</div>
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error">
                <span>⚠</span>
                <div>
                    <strong>Please check the form for errors:</strong>
                    <ul style="margin-left: 1.25rem; margin-top: 0.25rem;">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        @yield('content')
    </main>

    <script>
        function toggleAdminTheme() {
            const current = document.documentElement.getAttribute('data-theme') || 'light';
            const next = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('admin_theme', next);
            updateThemeToggleUI(next);
        }

        function updateThemeToggleUI(theme) {
            const icon = document.getElementById('theme-toggle-icon');
            const text = document.getElementById('theme-toggle-text');
            if (icon && text) {
                if (theme === 'dark') {
                    icon.textContent = '☀️';
                    text.textContent = 'Light';
                } else {
                    icon.textContent = '🌙';
                    text.textContent = 'Dark';
                }
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const current = document.documentElement.getAttribute('data-theme') || 'light';
            updateThemeToggleUI(current);
        });
    </script>
    @stack('scripts')
</body>
</html>

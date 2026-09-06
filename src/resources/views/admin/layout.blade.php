<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Admin Panel') - WC-Info API</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
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
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f1f5f9;
            color: var(--gray-800);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
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
            background-color: #ffffff;
            border-bottom: 1px solid var(--gray-200);
            padding: 0.75rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 50;
            box-shadow: var(--shadow-sm);
        }

        .navbar-inner {
            max-width: 1280px;
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
            color: var(--gray-900);
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
            gap: 1rem;
        }

        /* Container */
        .container {
            max-width: 1280px;
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

        /* Cards */
        .card {
            background: #ffffff;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background-color: #ffffff;
        }

        .card-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--gray-900);
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
            background-color: #ffffff;
            border-color: var(--gray-300);
            color: var(--gray-700);
        }
        .btn-secondary:hover {
            background-color: var(--gray-50);
            border-color: var(--gray-400);
            color: var(--gray-900);
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
            background-color: #ffffff;
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
            color: var(--gray-700);
            margin-bottom: 0.375rem;
        }

        .form-control, select.form-control, textarea.form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            color: var(--gray-900);
            background-color: #ffffff;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-md);
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
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
            color: var(--gray-500);
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
            color: var(--gray-800);
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
            background-color: #f3f4f6;
            color: #4b5563;
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
            background-color: #f1f5f9;
            color: #64748b;
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
            background-color: var(--gray-50);
            padding: 0.75rem 1rem;
            font-weight: 600;
            color: var(--gray-600);
            border-bottom: 1px solid var(--gray-200);
            text-transform: uppercase;
            font-size: 0.6875rem;
            letter-spacing: 0.05em;
        }

        table.data-table td {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid var(--gray-200);
            vertical-align: middle;
        }

        table.data-table tr:last-child td {
            border-bottom: none;
        }

        table.data-table tr:hover td {
            background-color: #f8fafc;
        }

        /* Diff Display */
        .diff-container {
            background: #0f172a;
            color: #e2e8f0;
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
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-md);
            overflow: hidden;
            background: #ffffff;
            display: flex;
            flex-direction: column;
        }

        .photo-card img {
            width: 100%;
            height: 160px;
            object-fit: cover;
            background-color: var(--gray-100);
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
            color: var(--gray-500);
            word-break: break-all;
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

    @stack('scripts')
</body>
</html>

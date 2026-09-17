<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login - WC-Info API</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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
            --bg-body: #f1f5f9;
            --bg-card: #ffffff;
            --border-card: #e2e8f0;
            --border-header: #f1f5f9;
            --text-heading: #111827;
            --text-main: #1f2937;
            --text-muted: #64748b;
            --bg-input: #ffffff;
            --border-input: #d1d5db;
            --danger-light: #fef2f2;
            --danger: #dc2626;
        }

        [data-theme="dark"] {
            --primary: #3b82f6;
            --primary-hover: #60a5fa;
            --bg-body: #0b0f19;
            --bg-card: #131b2e;
            --border-card: #243049;
            --border-header: #1e293b;
            --text-heading: #f8fafc;
            --text-main: #e2e8f0;
            --text-muted: #94a3b8;
            --bg-input: #0b0f19;
            --border-input: #334155;
            --danger-light: rgba(239, 68, 68, 0.18);
            --danger: #ef4444;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        .login-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 420px;
            overflow: hidden;
            transition: background-color 0.2s ease, border-color 0.2s ease;
        }

        .login-header {
            padding: 2rem 2rem 1.5rem;
            text-align: center;
            border-bottom: 1px solid var(--border-header);
        }

        .login-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-heading);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .login-header p {
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }

        .login-body {
            padding: 2rem;
        }

        .alert-error {
            background-color: var(--danger-light);
            border: 1px solid rgba(239, 68, 68, 0.35);
            color: var(--danger);
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-main);
            margin-bottom: 0.375rem;
        }

        .form-control {
            width: 100%;
            padding: 0.625rem 0.875rem;
            font-size: 0.9375rem;
            color: var(--text-heading);
            background-color: var(--bg-input);
            border: 1px solid var(--border-input);
            border-radius: 6px;
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.2s ease, color 0.2s ease;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .btn-submit {
            width: 100%;
            padding: 0.75rem;
            font-size: 0.9375rem;
            font-weight: 600;
            color: #ffffff;
            background-color: var(--primary);
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.15s ease;
            margin-top: 0.5rem;
        }

        .btn-submit:hover {
            background-color: var(--primary-hover);
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-header">
        <h1>🚽 WC-Info API</h1>
        <p>Admin Panel Authentication</p>
    </div>

    <div class="login-body">
        @if ($errors->any())
            <div class="alert-error">
                {{ $errors->first() }}
            </div>
        @endif

        @if (session('info'))
            <div style="background-color: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1.5rem; font-size: 0.875rem;">
                {{ session('info') }}
            </div>
        @endif

        <form action="{{ route('admin.login') }}" method="POST">
            @csrf
            <div class="form-group">
                <label class="form-label" for="username">Username</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    class="form-control"
                    required
                    autofocus
                    value="{{ old('username') }}"
                    placeholder="Enter admin username"
                >
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-control"
                    required
                    placeholder="Enter admin password"
                >
            </div>

            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label style="display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.875rem; color: var(--gray-700); user-select: none;">
                    <input
                        type="checkbox"
                        name="remember"
                        value="1"
                        style="width: 1rem; height: 1rem; accent-color: var(--primary); cursor: pointer;"
                        {{ old('remember') ? 'checked' : '' }}
                    >
                    <span>Stay logged in (Remember me)</span>
                </label>
            </div>

            <button type="submit" class="btn-submit">Sign In to Admin</button>
        </form>
    </div>
</div>

</body>
</html>

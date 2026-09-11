<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login - WC-Info API</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-700: #374151;
            --gray-900: #111827;
            --danger-light: #fef2f2;
            --danger: #dc2626;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #f1f5f9;
            color: var(--gray-900);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .login-card {
            background: #ffffff;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
            width: 100%;
            max-width: 420px;
            overflow: hidden;
        }

        .login-header {
            padding: 2rem 2rem 1.5rem;
            text-align: center;
            border-bottom: 1px solid var(--gray-100);
        }

        .login-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .login-header p {
            color: #64748b;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }

        .login-body {
            padding: 2rem;
        }

        .alert-error {
            background-color: var(--danger-light);
            border: 1px solid #fecaca;
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
            color: var(--gray-700);
            margin-bottom: 0.375rem;
        }

        .form-control {
            width: 100%;
            padding: 0.625rem 0.875rem;
            font-size: 0.9375rem;
            color: var(--gray-900);
            background-color: #ffffff;
            border: 1px solid var(--gray-300);
            border-radius: 6px;
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
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

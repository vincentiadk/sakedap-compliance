<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SAKEDAP Compliance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="/">
                <strong>📚 SAKEDAP Compliance</strong>
            </a>
            <div class="navbar-nav flex-row gap-3">
                <a class="nav-link {{ request()->is('/') || request()->is('dashboard') ? 'active fw-semibold' : '' }}" href="{{ route('dashboard') }}">Dashboard</a>
                <a class="nav-link {{ request()->is('compliance*') && !request()->is('compliance-v2*') ? 'active fw-semibold' : '' }}" href="{{ route('compliance.index') }}">Compliance</a>
                <a class="nav-link {{ request()->is('compliance-v2*') ? 'active fw-semibold' : '' }}" href="{{ route('compliance_v2.index') }}">
                    Compliance 2026+ <span class="badge bg-warning text-dark" style="font-size:.6rem">BARU</span>
                </a>
            </div>
        </div>
    </nav>

    <main class="py-4">
        @yield('content')
    </main>

    <footer class="bg-light py-4 mt-5">
        <div class="container text-center text-muted">
            <p>&copy; 2025 Perpustakaan Nasional RI - SAKEDAP System</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
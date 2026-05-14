{{--
    Optional Blade fallback that auto-submits a POST form to Cecabank.
    Use it from a host controller when you don't want to build your own
    Inertia / Vue / React redirect page:

        return view('cecabank::redirect', [
            'fields'  => $payload->fields,
            'action'  => $payload->gatewayUrl,
            'title'   => 'Redirigiendo a la pasarela…',
        ]);

    Publish with `php artisan vendor:publish --tag=cecabank-views` to override.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Redirecting…' }}</title>
    <style>
        body { font-family: system-ui, sans-serif; display: grid; place-items: center; min-height: 100vh; margin: 0; background: #f7f7f8; color: #1f2937; }
        .card { text-align: center; padding: 2rem; }
        .spinner { width: 48px; height: 48px; border: 4px solid #e5e7eb; border-top-color: #4f46e5; border-radius: 50%; margin: 0 auto 1rem; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .hint { color: #6b7280; font-size: .9rem; margin-top: .5rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="spinner" role="status" aria-hidden="true"></div>
        <p>{{ $title ?? 'Redirecting to the payment gateway…' }}</p>
        <p class="hint">{{ $hint ?? 'Please don\'t close this window.' }}</p>
    </div>

    <form id="cecabank-form" action="{{ $action }}" method="POST" style="display:none">
        @foreach ($fields as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
    </form>

    <script>
        document.getElementById('cecabank-form').submit();
    </script>
</body>
</html>

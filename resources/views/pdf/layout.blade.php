<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        @page { margin: 24px 28px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #1f2933; }
        h1 { font-size: 15px; margin: 0; }
        h2 { font-size: 12px; margin: 16px 0 6px; }
        .header { border-bottom: 2px solid #1f2933; padding-bottom: 6px; margin-bottom: 10px; }
        .header .bank { font-size: 11px; font-weight: bold; }
        .header .meta { color: #52606d; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #9aa5b1; padding: 3px 5px; vertical-align: top; }
        th { background: #e4e7eb; text-align: left; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tr.total td { font-weight: bold; background: #f5f7fa; }
        table.plain td { border: none; padding: 1px 4px 1px 0; }
        .muted { color: #52606d; }
        .note { margin-top: 8px; padding: 6px; border: 1px solid #f0b429; background: #fffbea; }
        .ok { color: #1e7b34; font-weight: bold; }
        .bad { color: #b42318; font-weight: bold; }
        .signatures { margin-top: 28px; width: 100%; }
        .signatures td { border: none; text-align: center; width: 50%; padding-top: 48px; }
        .footer { margin-top: 12px; color: #7b8794; font-size: 8px; }
    </style>
    @stack('styles')
</head>
<body>
    <div class="header">
        <div class="bank">{{ config('bank.name') }} &middot; {{ config('bank.school') }}</div>
        <h1>@yield('title')</h1>
        <div class="meta">@yield('subtitle')</div>
    </div>

    @yield('content')

    <div class="footer">Dicetak {{ now()->format('d/m/Y H:i') }} WIB oleh sistem E-Teller {{ config('bank.name') }}.</div>
</body>
</html>

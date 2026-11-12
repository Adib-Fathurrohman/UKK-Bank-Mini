<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Bukti Penarikan Kelulusan {{ $graduation->label() }}</title>
    <style>
        @page { margin: 22px 26px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #1f2933; }
        .slip { page-break-after: always; }
        .slip:last-child { page-break-after: auto; }
        .header { border-bottom: 2px solid #1f2933; padding-bottom: 6px; margin-bottom: 10px; }
        .bank { font-size: 11px; font-weight: bold; }
        h1 { font-size: 13px; margin: 4px 0 0; }
        .meta { color: #52606d; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; }
        table.data td { padding: 3px 0; vertical-align: top; }
        table.data td.label { width: 38%; color: #52606d; }
        .amount { margin: 12px 0 4px; padding: 8px; border: 1px solid #1f2933; }
        .amount .figure { font-size: 16px; font-weight: bold; }
        .words { font-style: italic; }
        .closed { margin-top: 8px; padding: 6px; border: 1px solid #f0b429; background: #fffbea; }
        .signatures { margin-top: 26px; }
        .signatures td { text-align: center; width: 50%; padding-top: 4px; }
        .signatures .space { height: 52px; }
        .footer { margin-top: 14px; color: #7b8794; font-size: 8px; }
    </style>
</head>
<body>
@foreach ($payouts as $payout)
    @php($customer = $payout->customer)
    <div class="slip">
        <div class="header">
            <div class="bank">{{ config('bank.name') }} &middot; {{ config('bank.school') }}</div>
            <h1>BUKTI PENARIKAN TABUNGAN KELULUSAN</h1>
            <div class="meta">Kelulusan {{ $graduation->label() }} &middot; {{ $graduation->created_at->format('d/m/Y H:i') }} WIB &middot; No. {{ $payout->id }}</div>
        </div>

        <table class="data">
            <tr><td class="label">Nama</td><td>{{ $customer->full_name }}</td></tr>
            <tr><td class="label">NIS</td><td>{{ $customer->nis }}</td></tr>
            <tr><td class="label">No. Rekening</td><td>{{ $customer->account_number }}</td></tr>
            <tr><td class="label">Kelas</td><td>{{ $customer->class }} {{ $customer->major?->code }} {{ $customer->group }}</td></tr>
            <tr><td class="label">Saldo sebelum penarikan</td><td>{{ \App\Support\Rupiah::format($payout->balance_before) }}</td></tr>
        </table>

        <div class="amount">
            <div>Diterima tunai</div>
            <div class="figure">{{ \App\Support\Rupiah::format($payout->amount) }}</div>
            <div class="words">Terbilang: {{ \App\Support\Terbilang::rupiah($payout->amount) }}</div>
        </div>

        <table class="data">
            <tr><td class="label">Saldo akhir</td><td>{{ \App\Support\Rupiah::format($payout->balance_after) }}</td></tr>
        </table>

        <div class="closed">Seluruh saldo, termasuk saldo minimal, telah dibayarkan dan rekening ditutup karena nasabah lulus.</div>

        <table class="signatures">
            <tr><td>Penerima (nasabah)</td><td>Petugas</td></tr>
            <tr><td class="space"></td><td class="space"></td></tr>
            <tr><td>{{ $customer->full_name }}</td><td>{{ $graduation->executor->full_name }}</td></tr>
        </table>

        <div class="footer">Dicetak {{ now()->format('d/m/Y H:i') }} WIB oleh sistem E-Teller {{ config('bank.name') }}. Simpan bukti ini sebagai tanda terima yang sah.</div>
    </div>
@endforeach
</body>
</html>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Struk {{ $transaction->type->label() }}</title>
    <style>
        @page { margin: 10px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9px; color: #000; }
        .center { text-align: center; }
        .title { font-size: 11px; font-weight: bold; }
        hr { border: none; border-top: 1px dashed #000; margin: 6px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1px 0; vertical-align: top; }
        td.right { text-align: right; }
        .amount { font-size: 12px; font-weight: bold; }
        .sign { margin-top: 26px; }
        .sign td { text-align: center; width: 50%; padding-top: 30px; }
    </style>
</head>
<body>
    <div class="center title">{{ config('bank.name') }}</div>
    <div class="center">{{ config('bank.school') }}</div>
    <div class="center title" style="margin-top: 6px;">BUKTI {{ strtoupper($transaction->type->label()) }}</div>
    <hr>
    <table>
        <tr><td>No. Transaksi</td><td class="right">{{ $transaction->id }}</td></tr>
        <tr><td>Tanggal</td><td class="right">{{ $transaction->created_at->format('d/m/Y H:i') }} WIB</td></tr>
        <tr><td>No. Rekening</td><td class="right">{{ $transaction->customer->account_number }}</td></tr>
        <tr><td>Nama</td><td class="right">{{ $transaction->customer->full_name }}</td></tr>
        <tr><td>Kelas</td><td class="right">{{ $transaction->customer->class }} {{ $transaction->customer->major?->code }} {{ $transaction->customer->group }}</td></tr>
        @if ($transaction->corrects_transaction_id)
            <tr><td>Koreksi atas</td><td class="right">No. {{ $transaction->corrects_transaction_id }}</td></tr>
            <tr><td>Alasan</td><td class="right">{{ $transaction->note }}</td></tr>
            <tr><td>Disetujui</td><td class="right">{{ $transaction->authorizer?->full_name }}</td></tr>
        @endif
    </table>
    <hr>
    <table>
        <tr><td>Nominal</td><td class="right amount">{{ \App\Support\Rupiah::format($transaction->amount) }}</td></tr>
    </table>
    <div>Terbilang: <em>{{ \App\Support\Terbilang::rupiah($transaction->amount) }}</em></div>
    <table style="margin-top: 4px;">
        <tr><td>Saldo akhir</td><td class="right">{{ \App\Support\Rupiah::format($transaction->balance_after) }}</td></tr>
    </table>
    <hr>
    <div>Teller: {{ $transaction->teller->full_name }}</div>
    <table class="sign">
        <tr><td>Nasabah</td><td>Teller</td></tr>
    </table>
    <div class="center" style="margin-top: 8px;">Simpan struk ini sebagai bukti transaksi yang sah.</div>
</body>
</html>

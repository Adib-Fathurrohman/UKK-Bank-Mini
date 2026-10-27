@extends('pdf.layout')

@section('title', 'Laporan Harian Teller (Penutupan Kas)')
@section('subtitle')
    {{ $report->report_date->locale('id')->translatedFormat('l, d F Y') }} &middot; Teller: {{ $report->teller->full_name }}{{ $report->teller->class ? ' ('.$report->teller->class.')' : '' }}
    &middot; Status: {{ $report->status->label() }}
@endsection

@section('content')
    <h2>Rekapitulasi</h2>
    <table>
        <tr><td>Saldo awal</td><td class="num">{{ \App\Support\Rupiah::format($report->opening_balance) }}</td></tr>
        <tr><td>Jumlah transaksi</td><td class="num">{{ $report->transaction_count }}</td></tr>
        <tr><td>Total setoran</td><td class="num">{{ \App\Support\Rupiah::format($report->total_deposit) }}</td></tr>
        <tr><td>Total penarikan</td><td class="num">{{ \App\Support\Rupiah::format($report->total_withdrawal) }}</td></tr>
        <tr class="total"><td>Saldo akhir sistem (saldo awal + setoran - penarikan)</td><td class="num">{{ \App\Support\Rupiah::format($report->system_balance) }}</td></tr>
        <tr class="total"><td>Uang fisik di laci</td><td class="num">{{ \App\Support\Rupiah::format($report->physical_balance) }}</td></tr>
        <tr><td>Selisih</td><td class="num {{ $report->difference() === 0 ? 'ok' : 'bad' }}">{{ \App\Support\Rupiah::format($report->difference()) }}</td></tr>
    </table>
    <p class="muted">Terbilang: {{ \App\Support\Terbilang::rupiah($report->physical_balance) }}</p>

    @foreach (['Saldo awal' => $report->openingCounts, 'Uang fisik' => $report->closingCounts] as $heading => $counts)
        <h2>Rincian lembaran: {{ $heading }}</h2>
        <table>
            <thead><tr><th>Nominal</th><th class="num">Jumlah</th><th class="num">Total</th></tr></thead>
            <tbody>
                @forelse ($counts as $count)
                    <tr>
                        <td>{{ \App\Support\Rupiah::format($count->denomination) }}</td>
                        <td class="num">{{ $count->quantity }} {{ $count->denomination >= 1000 ? 'lembar' : 'keping' }}</td>
                        <td class="num">{{ \App\Support\Rupiah::format($count->denomination * $count->quantity) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">Kosong.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endforeach

    <h2>Transaksi</h2>
    <table>
        <thead>
            <tr><th>Waktu</th><th>No. Trx</th><th>Jenis</th><th>No. Rekening</th><th>Nasabah</th><th class="num">Nominal</th></tr>
        </thead>
        <tbody>
            @forelse ($transactions as $transaction)
                <tr>
                    <td>{{ $transaction->created_at->format('H:i') }}</td>
                    <td>{{ $transaction->id }}</td>
                    <td>{{ $transaction->type->label() }}</td>
                    <td>{{ $transaction->customer->account_number }}</td>
                    <td>{{ $transaction->customer->full_name }}</td>
                    <td class="num">{{ \App\Support\Rupiah::format($transaction->amount) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Tidak ada transaksi.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($report->supervisor)
        <p>Diverifikasi oleh {{ $report->supervisor->full_name }} pada {{ $report->reviewed_at?->format('d/m/Y H:i') }}.
            @if ($report->supervisor_note) Catatan: {{ $report->supervisor_note }} @endif
        </p>
    @endif

    <table class="signatures">
        <tr>
            <td>Teller<br><br><br>{{ $report->teller->full_name }}</td>
            <td>Supervisor<br><br><br>{{ $report->supervisor?->full_name ?? '(..............................)' }}</td>
        </tr>
    </table>
@endsection

@extends('pdf.layout')

@section('title', 'Kartu Simpanan / Mutasi Rekening')
@section('subtitle')
    Periode: {{ $from?->format('d/m/Y') ?? 'awal' }} s.d. {{ $to?->format('d/m/Y') ?? now()->format('d/m/Y') }}
@endsection

@section('content')
    <table class="plain">
        <tr><td>No. Rekening</td><td>: {{ $customer->account_number }}</td></tr>
        <tr><td>Nama</td><td>: {{ $customer->full_name }}</td></tr>
        <tr><td>NIS</td><td>: {{ $customer->nis }}</td></tr>
        <tr><td>Kelas</td><td>: {{ $customer->class }} {{ $customer->major?->code }} {{ $customer->group }}</td></tr>
    </table>

    <h2>Mutasi</h2>
    <table>
        <thead>
            <tr>
                <th>Tanggal</th><th>Keterangan</th><th>Teller</th>
                <th class="num">Debet (keluar)</th><th class="num">Kredit (masuk)</th><th class="num">Saldo</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td></td><td>Saldo awal</td><td></td><td></td><td></td>
                <td class="num">{{ \App\Support\Rupiah::format($opening_balance) }}</td>
            </tr>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line->created_at->format('d/m/Y H:i') }}</td>
                    <td>{{ $line->type->label() }}</td>
                    <td>{{ $line->teller->full_name }}</td>
                    <td class="num">{{ $line->type->increasesBalance() ? '' : \App\Support\Rupiah::format($line->amount) }}</td>
                    <td class="num">{{ $line->type->increasesBalance() ? \App\Support\Rupiah::format($line->amount) : '' }}</td>
                    <td class="num">{{ \App\Support\Rupiah::format($line->balance_after) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="3">Jumlah / saldo akhir</td>
                <td class="num">{{ \App\Support\Rupiah::format($total_out) }}</td>
                <td class="num">{{ \App\Support\Rupiah::format($total_in) }}</td>
                <td class="num">{{ \App\Support\Rupiah::format($closing_balance) }}</td>
            </tr>
        </tbody>
    </table>
@endsection

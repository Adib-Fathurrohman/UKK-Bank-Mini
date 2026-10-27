@extends('pdf.layout')

@section('title', 'Jejak Audit Transaksi')
@section('subtitle')
    @include('pdf._period', ['filters' => $filters])
@endsection

@section('content')
    <table>
        <thead>
            <tr>
                <th>Tanggal</th><th>No. Trx</th><th>Jenis</th><th>Teller</th><th>Nasabah</th><th>No. Rekening</th>
                <th class="num">Nominal</th><th class="num">Saldo Sebelum</th><th class="num">Saldo Sesudah</th><th>Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($transactions as $transaction)
                <tr>
                    <td>{{ $transaction->created_at->format('d/m/Y H:i') }}</td>
                    <td>{{ $transaction->id }}</td>
                    <td>{{ $transaction->type->label() }}</td>
                    <td>{{ $transaction->teller->full_name }}</td>
                    <td>{{ $transaction->customer->full_name }}</td>
                    <td>{{ $transaction->customer->account_number }}</td>
                    <td class="num">{{ \App\Support\Rupiah::format($transaction->amount) }}</td>
                    <td class="num">{{ \App\Support\Rupiah::format($transaction->balance_before) }}</td>
                    <td class="num">{{ \App\Support\Rupiah::format($transaction->balance_after) }}</td>
                    <td>
                        @if ($transaction->corrects_transaction_id)
                            Koreksi trx #{{ $transaction->corrects_transaction_id }}: {{ $transaction->note }}
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="muted">Tidak ada transaksi pada periode ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($truncated)
        <div class="note">Hanya {{ number_format(count($transactions), 0, ',', '.') }} baris pertama yang dicetak. Persempit periode untuk mencetak sisanya.</div>
    @endif
@endsection

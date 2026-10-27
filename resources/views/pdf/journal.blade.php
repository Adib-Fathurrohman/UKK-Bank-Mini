@extends('pdf.layout')

@section('title', 'Laporan Jurnal Akuntansi')
@section('subtitle')
    @include('pdf._period', ['filters' => $filters])
@endsection

@section('content')
    <h2>Ringkasan per akun</h2>
    <table>
        <thead>
            <tr><th>Kode</th><th>Akun</th><th class="num">Debet</th><th class="num">Kredit</th></tr>
        </thead>
        <tbody>
            @foreach ($summary['accounts'] as $account)
                <tr>
                    <td>{{ $account['account_code']->value }}</td>
                    <td>{{ $account['account_name'] }}</td>
                    <td class="num">{{ \App\Support\Rupiah::format($account['total_debit']) }}</td>
                    <td class="num">{{ \App\Support\Rupiah::format($account['total_credit']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="2">Total</td>
                <td class="num">{{ \App\Support\Rupiah::format($summary['total_debit']) }}</td>
                <td class="num">{{ \App\Support\Rupiah::format($summary['total_credit']) }}</td>
            </tr>
        </tbody>
    </table>
    <p>Status: <span class="{{ $summary['is_balanced'] ? 'ok' : 'bad' }}">{{ $summary['is_balanced'] ? 'Seimbang (debet = kredit)' : 'TIDAK SEIMBANG' }}</span></p>

    <h2>Rincian jurnal</h2>
    <table>
        <thead>
            <tr>
                <th>Tanggal</th><th>No. Trx</th><th>Teller</th><th>Nasabah</th><th>No. Rekening</th>
                <th>Akun</th><th class="num">Debet</th><th class="num">Kredit</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($journals as $journal)
                <tr>
                    <td>{{ $journal->created_at->format('d/m/Y H:i') }}</td>
                    <td>{{ $journal->transaction_id }}</td>
                    <td>{{ $journal->transaction->teller->full_name }}</td>
                    <td>{{ $journal->transaction->customer->full_name }}</td>
                    <td>{{ $journal->transaction->customer->account_number }}</td>
                    <td>{{ $journal->account_code->value }} {{ $journal->account_code->label() }}</td>
                    <td class="num">{{ $journal->position->value === 'debit' ? \App\Support\Rupiah::format($journal->amount) : '' }}</td>
                    <td class="num">{{ $journal->position->value === 'credit' ? \App\Support\Rupiah::format($journal->amount) : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">Tidak ada jurnal pada periode ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($truncated)
        <div class="note">Hanya {{ number_format(count($journals), 0, ',', '.') }} baris pertama yang dicetak. Persempit periode untuk mencetak sisanya.</div>
    @endif
@endsection

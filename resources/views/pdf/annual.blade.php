@extends('pdf.layout')

@section('title', 'Laporan Tahunan Tabungan')
@section('subtitle')
    Tahun ajaran {{ $label }} ({{ $from->format('d/m/Y') }} s.d. {{ $to->format('d/m/Y') }})
@endsection

@section('content')
    <h2>Saldo simpanan per produk</h2>
    <table>
        <thead>
            <tr>
                <th>Kode</th><th>Produk</th><th class="num">Rekening</th><th class="num">Saldo awal</th>
                <th class="num">Setoran</th><th class="num">Penarikan</th><th class="num">Saldo akhir</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($products as $product)
                <tr>
                    <td>{{ $product['account_code'] }}</td>
                    <td>{{ $product['name'] }}</td>
                    <td class="num">{{ $product['accounts'] }}</td>
                    <td class="num">{{ \App\Support\Rupiah::format($product['opening_balance']) }}</td>
                    <td class="num">{{ \App\Support\Rupiah::format($product['deposits']) }}</td>
                    <td class="num">{{ \App\Support\Rupiah::format($product['withdrawals']) }}</td>
                    <td class="num">{{ \App\Support\Rupiah::format($product['closing_balance']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Kartu simpanan per rekening</h2>
    <table>
        <thead>
            <tr>
                <th>No. Rekening</th><th>Nama</th><th>Kelas</th><th>Status</th>
                <th class="num">Saldo awal</th><th class="num">Setoran</th><th class="num">Penarikan</th><th class="num">Saldo akhir</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['customer']->account_number }}</td>
                    <td>{{ $row['customer']->full_name }}</td>
                    <td>{{ $row['customer']->class }} {{ $row['customer']->major?->code }} {{ $row['customer']->group }}</td>
                    <td>{{ $row['customer']->account_status->label() }}</td>
                    <td class="num">{{ \App\Support\Rupiah::format($row['opening_balance']) }}</td>
                    <td class="num">{{ \App\Support\Rupiah::format($row['deposits']) }}</td>
                    <td class="num">{{ \App\Support\Rupiah::format($row['withdrawals']) }}</td>
                    <td class="num">{{ \App\Support\Rupiah::format($row['closing_balance']) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">Tidak ada rekening dengan saldo atau transaksi pada tahun ajaran ini.</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="4">Total</td>
                <td class="num">{{ \App\Support\Rupiah::format($totals['opening_balance']) }}</td>
                <td class="num">{{ \App\Support\Rupiah::format($totals['deposits']) }}</td>
                <td class="num">{{ \App\Support\Rupiah::format($totals['withdrawals']) }}</td>
                <td class="num">{{ \App\Support\Rupiah::format($totals['closing_balance']) }}</td>
            </tr>
        </tbody>
    </table>
@endsection

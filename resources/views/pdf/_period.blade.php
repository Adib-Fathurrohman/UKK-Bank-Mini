@php
    $from = $filters['from'] ?? null;
    $to = $filters['to'] ?? null;
@endphp
Periode: {{ $from ?: 'awal' }} s.d. {{ $to ?: 'sekarang' }}
@if (! empty($filters['teller'])) &middot; Teller: {{ $filters['teller'] }} @endif
@if (! empty($filters['customer'])) &middot; Nasabah: {{ $filters['customer'] }} @endif
@if (! empty($filters['account_code'])) &middot; Akun: {{ $filters['account_code'] }} @endif
@if (! empty($filters['type'])) &middot; Jenis: {{ $filters['type'] }} @endif

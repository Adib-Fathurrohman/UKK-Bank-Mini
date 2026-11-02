{{ $isResend ? 'Tautan aktivasi baru' : 'Aktifkan rekening Anda' }} — {{ config('bank.name') }}

Halo {{ $customer->full_name }},

@if ($isResend)
Berikut tautan aktivasi baru untuk rekening tabungan Anda. Tautan yang dikirim sebelumnya sudah tidak berlaku.
@else
Rekening tabungan Anda di {{ config('bank.name') }} sudah dibuat. Aktifkan akun aplikasi Anda agar dapat melihat saldo dan menyetujui penarikan.
@endif

Nomor rekening: {{ $customer->account_number }}
Email terdaftar: {{ $customer->email }}

Aktifkan rekening:
{{ $activationUrl }}

Masuk dengan akun Google atau buat password untuk {{ $customer->email }}. Email lain tidak dapat dipakai.
Tautan berlaku sampai {{ $expiresAt->locale('id')->translatedFormat('j F Y, H:i') }} dan hanya dapat dipakai sekali.

Bukan Anda yang membuka rekening ini? Abaikan email ini dan beri tahu petugas bank mini.
Petugas bank mini tidak pernah meminta PIN atau password Anda.

Rekening Anda sudah aktif — {{ config('bank.name') }}

Halo {{ $customer->full_name }},

Rekening {{ $customer->account_number }} diaktifkan pada {{ $customer->activated_at->locale('id')->translatedFormat('j F Y, H:i') }} dengan {{ $method }} ({{ $customer->email }}). Mulai sekarang, masuk ke aplikasi dengan cara yang sama.

PIN 6 digit tetap dipakai untuk membuka aplikasi dan menyetujui penarikan. Jangan beri tahu PIN atau password Anda kepada siapa pun.

Bukan Anda? Segera hubungi petugas bank mini agar rekening dapat diamankan.

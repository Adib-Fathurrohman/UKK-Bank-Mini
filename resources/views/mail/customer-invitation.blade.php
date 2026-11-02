<x-mail-layout
    :title="$isResend ? 'Tautan aktivasi baru' : 'Aktifkan rekening Anda'"
    :preheader="'Aktifkan rekening '.$customer->account_number.' sebelum '.$expiresAt->locale('id')->translatedFormat('j F Y H:i').'.'"
>
    <h1 style="margin:0 0 16px; font-size:22px; line-height:1.3; color:#12181c;">
        {{ $isResend ? 'Tautan aktivasi baru' : 'Aktifkan rekening Anda' }}
    </h1>

    <p style="margin:0 0 16px; font-size:15px; line-height:1.6;">Halo {{ $customer->full_name }},</p>

    <p style="margin:0 0 16px; font-size:15px; line-height:1.6;">
        @if ($isResend)
            Berikut tautan aktivasi baru untuk rekening tabungan Anda. Tautan yang dikirim sebelumnya sudah tidak berlaku.
        @else
            Rekening tabungan Anda di {{ config('bank.name') }} sudah dibuat. Aktifkan akun aplikasi Anda agar dapat melihat saldo dan menyetujui penarikan.
        @endif
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px; background-color:#f7f8f9; border:1px solid #e1e6e9; border-radius:8px;">
        <tr>
            <td style="padding:12px 16px; font-size:14px; line-height:1.7;">
                <span style="color:#5c6870;">Nomor rekening</span><br>
                <strong style="font-family:Menlo, Consolas, monospace;">{{ $customer->account_number }}</strong><br>
                <span style="color:#5c6870;">Email terdaftar</span><br>
                <strong>{{ $customer->email }}</strong>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" class="button" style="margin:0 0 24px;">
        <tr>
            <td style="border-radius:8px; background-color:#0b6b5d;">
                <a href="{{ $activationUrl }}" style="display:inline-block; padding:12px 24px; font-size:15px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:8px;">Aktifkan rekening</a>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 16px; font-size:14px; line-height:1.6; color:#39434a;">
        Masuk dengan akun Google atau buat password untuk <strong>{{ $customer->email }}</strong>. Email lain tidak dapat dipakai.
        Tautan berlaku sampai <strong>{{ $expiresAt->locale('id')->translatedFormat('j F Y, H:i') }}</strong> dan hanya dapat dipakai sekali.
    </p>

    <p style="margin:0 0 8px; font-size:13px; line-height:1.6; color:#5c6870;">Jika tombol tidak berfungsi, salin tautan ini ke browser:</p>
    <p style="margin:0 0 16px; font-size:13px; line-height:1.5; word-break:break-all;">
        <a href="{{ $activationUrl }}" style="color:#0b6b5d;">{{ $activationUrl }}</a>
    </p>

    <p style="margin:0; font-size:13px; line-height:1.6; color:#5c6870;">
        Bukan Anda yang membuka rekening ini? Abaikan email ini dan beri tahu petugas bank mini.
    </p>
</x-mail-layout>

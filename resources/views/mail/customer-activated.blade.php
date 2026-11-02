<x-mail-layout
    title="Rekening Anda sudah aktif"
    :preheader="'Rekening '.$customer->account_number.' diaktifkan dengan '.$method.'.'"
>
    <h1 style="margin:0 0 16px; font-size:22px; line-height:1.3; color:#12181c;">Rekening Anda sudah aktif</h1>

    <p style="margin:0 0 16px; font-size:15px; line-height:1.6;">Halo {{ $customer->full_name }},</p>

    <p style="margin:0 0 16px; font-size:15px; line-height:1.6;">
        Rekening <strong style="font-family:Menlo, Consolas, monospace;">{{ $customer->account_number }}</strong>
        diaktifkan pada <strong>{{ $customer->activated_at->locale('id')->translatedFormat('j F Y, H:i') }}</strong> dengan {{ $method }}
        (<strong>{{ $customer->email }}</strong>). Mulai sekarang, masuk ke aplikasi dengan cara yang sama.
    </p>

    <p style="margin:0 0 16px; font-size:15px; line-height:1.6;">
        PIN 6 digit tetap dipakai untuk membuka aplikasi dan menyetujui penarikan. Jangan beri tahu PIN atau password Anda kepada siapa pun.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#fdf4e7; border:1px solid #f0d9b5; border-radius:8px;">
        <tr>
            <td style="padding:12px 16px; font-size:14px; line-height:1.6; color:#5a3d06;">
                <strong>Bukan Anda?</strong> Segera hubungi petugas bank mini agar rekening dapat diamankan.
            </td>
        </tr>
    </table>
</x-mail-layout>

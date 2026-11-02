{{-- Shared email layout. Table-based with inline styles, because many email clients ignore <style> blocks and flexbox. --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $title }}</title>
    <style>
        @media (max-width: 600px) {
            .card { padding: 24px 20px !important; }
            .button a { display: block !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#eef1f3; font-family:-apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#12181c;">
    {{-- Preview text shown next to the subject in the inbox. --}}
    <div style="display:none; max-height:0; overflow:hidden;">{{ $preheader }}</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef1f3;">
        <tr>
            <td align="center" style="padding:32px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
                    <tr>
                        <td style="padding:0 4px 16px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="width:36px; height:36px; background-color:#0b6b5d; border-radius:8px; color:#ffffff; font-size:18px; font-weight:700; text-align:center; vertical-align:middle;">B</td>
                                    <td style="padding-left:10px; font-size:16px; font-weight:700; color:#12181c;">{{ config('bank.name') }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td class="card" style="background-color:#ffffff; border:1px solid #e1e6e9; border-radius:12px; padding:32px;">
                            {{ $slot }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 4px 0; font-size:12px; line-height:1.6; color:#5c6870;">
                            Email ini dikirim otomatis oleh {{ config('bank.name') }} {{ config('bank.school') }}. Jangan membalas email ini.
                            Petugas bank mini tidak pernah meminta PIN atau password Anda.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

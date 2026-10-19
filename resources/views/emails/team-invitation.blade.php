<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden;">
                    <tr>
                        <td style="padding:28px 32px 8px;">
                            <img src="{{ asset('images/logo.png') }}" alt="SocialDesk" height="40" style="height:40px;width:auto;">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 32px 0;">
                            <h1 style="margin:16px 0 0;font-size:20px;line-height:1.3;">You have been invited to {{ $workspace->name }}</h1>
                            <p style="margin:12px 0 0;font-size:14px;line-height:1.6;color:#475569;">
                                <strong>{{ $inviter->name }}</strong> invited you to join the
                                <strong>{{ $workspace->name }}</strong> workspace on SocialDesk
                                as {{ $roleLabel === 'Admin' ? 'an' : 'a' }} <strong>{{ $roleLabel }}</strong>.
                            </p>
                            <p style="margin:12px 0 0;font-size:14px;line-height:1.6;color:#475569;">
                                Accept the invitation to start handling WhatsApp, Instagram and Messenger
                                conversations with your team.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 32px 4px;">
                            <a href="{{ $acceptUrl }}"
                               style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:12px 22px;border-radius:10px;">
                                Accept invitation
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px 32px;">
                            <p style="margin:0;font-size:12px;line-height:1.6;color:#94a3b8;">
                                This link expires on {{ $invitation->expires_at->toFormattedDateString() }}.
                                If the button does not work, copy this address into your browser:
                            </p>
                            <p style="margin:8px 0 0;font-size:12px;line-height:1.6;color:#4f46e5;word-break:break-all;">
                                {{ $acceptUrl }}
                            </p>
                            <p style="margin:16px 0 0;font-size:12px;line-height:1.6;color:#94a3b8;">
                                If you were not expecting this invitation you can safely ignore this email.
                            </p>
                        </td>
                    </tr>
                </table>
                <p style="margin:16px 0 0;font-size:11px;color:#94a3b8;">SocialDesk — one inbox for WhatsApp, Instagram &amp; Messenger</p>
            </td>
        </tr>
    </table>
</body>
</html>

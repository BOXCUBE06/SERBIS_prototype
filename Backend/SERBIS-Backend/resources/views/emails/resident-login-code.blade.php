<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your SERBIS login code</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f5;font-family:Arial,Helvetica,sans-serif;color:#1f2421;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f5;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border-radius:8px;padding:32px;">
                    <tr>
                        <td style="font-size:18px;font-weight:bold;padding-bottom:8px;">SERBIS</td>
                    </tr>
                    <tr>
                        <td style="font-size:14px;line-height:22px;padding-bottom:24px;color:#4a5350;">
                            Echague MDRRMO
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:15px;line-height:24px;padding-bottom:16px;">
                            Hello {{ $firstName }},
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:15px;line-height:24px;padding-bottom:24px;">
                            Enter this code in the SERBIS app to finish signing in.
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding-bottom:24px;">
                            <div style="font-size:32px;font-weight:bold;letter-spacing:8px;background:#eef4f2;border-radius:6px;padding:16px 0;color:#1b5b4b;">
                                {{ $code }}
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:14px;line-height:22px;color:#4a5350;padding-bottom:16px;">
                            The code expires in 5 minutes. If it lapses, ask for a new one from the same screen.
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:14px;line-height:22px;color:#4a5350;">
                            If you did not try to log in to SERBIS, you can ignore this email — nobody can sign in without this code.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Equipment due back tomorrow</title>
</head>
{{-- Table layout and inline styles on purpose: mail clients are not browsers,
     and this has to stay readable in the ones that strip <style> blocks. --}}
<body style="margin:0;padding:0;background:#f4f6f5;font-family:Arial,Helvetica,sans-serif;color:#1f2421;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f5;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;padding:32px;">
                    <tr>
                        <td style="font-size:18px;font-weight:bold;padding-bottom:8px;">SERBIS</td>
                    </tr>
                    <tr>
                        <td style="font-size:14px;line-height:22px;padding-bottom:24px;color:#4a5350;">
                            Echague MDRRMO
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:15px;line-height:24px;padding-bottom:20px;">
                            {{ count($rows) === 1 ? 'This item is' : count($rows) . ' items are' }} due back tomorrow:
                        </td>
                    </tr>
                    <tr>
                        <td style="padding-bottom:24px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                <tr>
                                    <td style="font-size:12px;font-weight:bold;text-transform:uppercase;color:#4a5350;padding:6px 8px;border-bottom:2px solid #eef4f2;">Item</td>
                                    <td style="font-size:12px;font-weight:bold;text-transform:uppercase;color:#4a5350;padding:6px 8px;border-bottom:2px solid #eef4f2;">Borrower</td>
                                    <td style="font-size:12px;font-weight:bold;text-transform:uppercase;color:#4a5350;padding:6px 8px;border-bottom:2px solid #eef4f2;">Due</td>
                                </tr>
                                @foreach ($rows as $row)
                                <tr>
                                    <td style="font-size:14px;padding:8px;border-bottom:1px solid #eef4f2;">{{ $row['item'] }}</td>
                                    <td style="font-size:14px;padding:8px;border-bottom:1px solid #eef4f2;">{{ $row['borrower'] }}</td>
                                    <td style="font-size:14px;padding:8px;border-bottom:1px solid #eef4f2;">{{ $row['due_date'] }}</td>
                                </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:14px;line-height:22px;color:#4a5350;">
                            The borrower has already been texted and pushed the same reminder. This is the same list, for the office.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

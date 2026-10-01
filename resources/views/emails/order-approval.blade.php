<!DOCTYPE html>
<html lang="uk" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>Погодження {{ $order->order_number }}</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style type="text/css">
        :root { color-scheme: light; supported-color-schemes: light; }
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        body { margin: 0; padding: 0; width: 100% !important; height: 100% !important; background-color: #f1f5f9 !important; }
        a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }
        @media only screen and (max-width: 600px) {
            .container { width: 100% !important; max-width: 100% !important; }
            .mobile-padding { padding-left: 20px !important; padding-right: 20px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9 !important; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;">

    <!-- Preheader -->
    <div style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">
        Замовлення {{ $order->order_number }} очікує вашого погодження — {{ $itemsSummary }}
        &zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;
    </div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f1f5f9 !important;">
        <tr>
            <td align="center" style="padding: 40px 16px 32px;">

                <table role="presentation" width="520" cellspacing="0" cellpadding="0" border="0" class="container" style="max-width: 520px; width: 100%;">

                    <!-- Header -->
                    <tr>
                        <td style="background-color: #1D4289; padding: 32px 36px 28px; text-align: center; border-radius: 16px 16px 0 0;" class="mobile-padding">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td style="font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: #93b4e8; font-weight: 600; padding-bottom: 10px;">
                                        Департамент поліграфії
                                    </td>
                                </tr>
                                <tr>
                                    <td style="font-size: 22px; font-weight: 700; color: #ffffff; line-height: 1.3; padding-bottom: 4px;">
                                        Погодження замовлення
                                    </td>
                                </tr>
                                <tr>
                                    <td style="font-size: 11px; color: #7da0d4; letter-spacing: 0.5px;">
                                        Університет
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="background-color: #ffffff !important; padding: 32px 36px 28px; border-left: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0;" class="mobile-padding">

                            <!-- Greeting -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td style="font-size: 16px; font-weight: 600; color: #1e293b; padding-bottom: 6px;">
                                        Шановний(а) {{ $signatoryName }},
                                    </td>
                                </tr>
                                <tr>
                                    <td style="font-size: 14px; color: #64748b; line-height: 1.6; padding-bottom: 28px;">
                                        На ваше ім'я створено внутрішнє замовлення в поліграфії.
                                    </td>
                                </tr>
                            </table>

                            <!-- Order details -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f8fafc !important; border-radius: 12px; border: 1px solid #e2e8f0;">
                                <tr>
                                    <td style="padding: 20px 24px 16px; text-align: center; border-bottom: 1px solid #e2e8f0;">
                                        <span style="font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px; color: #94a3b8; font-weight: 600;">Номер замовлення</span><br>
                                        <span style="font-size: 22px; font-weight: 800; color: #1D4289; font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; letter-spacing: 1px;">{{ $order->order_number }}</span>
                                    </td>
                                </tr>
                                <!-- Items, grouped by service category -->
                                @foreach($itemsByCategory as $group)
                                <tr>
                                    <td style="padding: 14px 24px 8px; background-color: #f1f5f9 !important; border-bottom: 1px solid #e2e8f0;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                            <tr>
                                                <td style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #1D4289; font-weight: 700;">
                                                    {{ $group['category'] }}
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @foreach($group['items'] as $item)
                                <tr>
                                    <td style="padding: 16px 24px; border-bottom: 1px solid #e2e8f0;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                            <tr>
                                                <td style="font-size: 14px; color: #1e293b; font-weight: 600;">
                                                    {{ $item['name'] }}
                                                </td>
                                                <td align="right" style="font-size: 13px; color: #64748b; white-space: nowrap;">
                                                    &times; {{ $item['quantity'] }}
                                                </td>
                                            </tr>
                                            @if($item['details'])
                                            <tr>
                                                <td colspan="2" style="padding-top: 4px; font-size: 12px; color: #64748b; line-height: 1.4;">
                                                    {{ $item['details'] }}
                                                </td>
                                            </tr>
                                            @endif
                                            @if($item['material'])
                                            <tr>
                                                <td colspan="2" style="padding-top: 3px; font-size: 12px; color: #6366f1; font-style: italic;">
                                                    {{ $item['material'] }}
                                                </td>
                                            </tr>
                                            @endif
                                        </table>
                                    </td>
                                </tr>
                                @endforeach
                                @endforeach
                                <!-- Summary row -->
                                <tr>
                                    <td style="padding: 16px 24px; border-top: 2px solid #e2e8f0; background-color: #ffffff !important; border-radius: 0 0 12px 12px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                            @if($order->cost_center)
                                            <tr>
                                                <td style="padding: 3px 0; font-size: 12px; color: #94a3b8;">Центр витрат</td>
                                                <td align="right" style="padding: 3px 0; font-size: 13px; color: #334155; font-weight: 500;">{{ $order->cost_center }}</td>
                                            </tr>
                                            @endif
                                            @if($order->initiator)
                                            <tr>
                                                <td style="padding: 3px 0; font-size: 12px; color: #94a3b8;">Ініціатор</td>
                                                <td align="right" style="padding: 3px 0; font-size: 13px; color: #334155; font-weight: 500;">{{ $order->initiator }}</td>
                                            </tr>
                                            @endif
                                            <tr>
                                                <td style="padding: 3px 0; font-size: 12px; color: #94a3b8;">Дата</td>
                                                <td align="right" style="padding: 3px 0; font-size: 13px; color: #64748b;">{{ $order->created_at->timezone('Europe/Kyiv')->format('d.m.Y H:i') }}</td>
                                            </tr>
                                            {{-- No money here, by the owner's decision of 2026-08-20: what a job
                                                 costs is CRM and accounting information. The signatory approves the
                                                 composition and the print run, and is not answerable for the price. --}}
                                        </table>
                                    </td>
                                </tr>
                            </table>

                        </td>
                    </tr>

                    <!-- CTA -->
                    <tr>
                        <td style="background-color: #ffffff !important; padding: 0 36px 32px; border-left: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0;" class="mobile-padding">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-bottom: 16px;">
                                <tr>
                                    <td align="center" style="border-radius: 10px; background-color: #059669 !important;">
                                        <!--[if mso]>
                                        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" href="{{ $approveUrl }}" style="height:52px;v-text-anchor:middle;width:440px;" arcsize="20%" strokecolor="#059669" fillcolor="#059669">
                                        <center style="color:#ffffff;font-family:sans-serif;font-size:16px;font-weight:bold;">&#10003; Погоджую</center>
                                        </v:roundrect>
                                        <![endif]-->
                                        <!--[if !mso]><!-->
                                        <a href="{{ $approveUrl }}" target="_blank"
                                           style="display: block; padding: 16px 32px; font-size: 16px; font-weight: 700; color: #ffffff !important; text-decoration: none; text-align: center; border-radius: 10px; background-color: #059669 !important; letter-spacing: 0.3px;">
                                            &#10003;&ensp;Погоджую
                                        </a>
                                        <!--<![endif]-->
                                    </td>
                                </tr>
                            </table>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td align="center">
                                        <a href="{{ $rejectUrl }}" target="_blank"
                                           style="font-size: 13px; color: #ef4444 !important; text-decoration: none; font-weight: 600; letter-spacing: 0.2px;">
                                            &#10005;&ensp;Відхиляю
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8fafc !important; padding: 24px 36px; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 16px 16px;" class="mobile-padding">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td style="font-size: 12px; color: #94a3b8; line-height: 1.7; text-align: center;">
                                        Посилання дійсне протягом {{ $ttlHours }} годин.<br>
                                        Натискаючи &laquo;Погоджую&raquo;, ви замінюєте паперову заявку.
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding-top: 16px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-top: 1px solid #e2e8f0;">
                                            <tr>
                                                <td style="font-size: 11px; color: #cbd5e1; text-align: center; padding-top: 14px;">
                                                    З повагою,<br>
                                                    <span style="color: #94a3b8; font-weight: 600;">Департамент поліграфії університету</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                </table>

                <!-- Fallback URL -->
                <table role="presentation" width="520" cellspacing="0" cellpadding="0" border="0" class="container" style="max-width: 520px; width: 100%;">
                    <tr>
                        <td style="padding: 20px 8px 0; font-size: 10px; color: #94a3b8; line-height: 1.5;">
                            Якщо кнопка не працює, скопіюйте це посилання у браузер:<br>
                            <a href="{{ $approveUrl }}" style="color: #64748b; word-break: break-all; text-decoration: underline;">{{ $approveUrl }}</a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 0 0; text-align: center; font-size: 10px; color: #cbd5e1;">
                            &copy; {{ date('Y') }} CRM Print
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>

</body>
</html>

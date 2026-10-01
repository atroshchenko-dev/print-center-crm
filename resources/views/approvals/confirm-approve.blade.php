<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#059669">
    <link rel="icon" type="image/png" href="/favicon.png">
    <title>Погодження {{ $order->order_number }} | Центр поліграфії</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f0f2f5 0%, #e2e6ed 100%);
            color: #333;
            padding: 1rem;
        }
        .card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            max-width: 440px;
            width: 100%;
            overflow: hidden;
        }
        .header {
            background: #059669;
            color: white;
            padding: 1.5rem;
            text-align: center;
        }
        .header .icon { margin-bottom: 0.5rem; display: flex; align-items: center; justify-content: center; }
        .header h1 { font-size: 1.1rem; font-weight: 600; }
        .header .brand { font-size: 0.7rem; opacity: 0.8; margin-top: 0.25rem; }
        .body { padding: 1.5rem; }
        .order-info {
            background: #f9fafb;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1.25rem;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.3rem 0;
            font-size: 0.9rem;
        }
        .info-label { color: #6b7280; }
        .info-value { font-weight: 500; color: #374151; }
        /* A category heading reuses .info-row for layout, but must not read
           as just another item. */
        .category-row { padding-top: 0.75rem; }
        .category-row .info-label,
        .category-row .info-value {
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.75rem;
            color: #1D4289;
        }
        .actions { padding: 0 1.5rem 1.5rem; }
        .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            padding: 1rem;
            border: none;
            border-radius: 0.5rem;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
        }
        .btn-approve {
            background: #059669;
            color: white;
            margin-bottom: 0.75rem;
        }
        .btn-approve:hover { background: #047857; }
        .btn-approve:disabled { background: #9ca3af; cursor: wait; }
        .btn-back {
            background: transparent;
            color: #6b7280;
            font-size: 0.85rem;
            font-weight: 400;
            border: 1px solid #e5e7eb;
        }
        .btn-back:hover { background: #f9fafb; }
        .note {
            text-align: center;
            font-size: 0.8rem;
            color: #9ca3af;
            margin-top: 1rem;
            padding: 0 1.5rem;
            padding-bottom: 1.5rem;
        }
        .alt-link {
            display: block;
            text-align: center;
            margin-top: 0.75rem;
            font-size: 0.8rem;
            color: #dc2626;
            text-decoration: none;
        }
        .alt-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div class="icon">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
            </div>
            <h1>Підтвердити погодження</h1>
            <div class="brand">Департамент поліграфії університету</div>
        </div>

        <div class="body">
            <div class="order-info">
                <div class="info-row">
                    <span class="info-label">Замовлення</span>
                    <span class="info-value" style="font-family: 'SFMono-Regular', Consolas, monospace;">{{ $order->order_number }}</span>
                </div>
                @foreach($itemsByCategory as $group)
                <div class="info-row category-row">
                    <span class="info-label">{{ $group['category'] }}</span>
                </div>
                    @foreach($group['items'] as $item)
                <div class="info-row">
                    <span class="info-label" style="padding-left: 12px;">{{ $item['name'] }}</span>
                    <span class="info-value">&times; {{ $item['quantity'] }}</span>
                </div>
                    @endforeach
                @endforeach
                @if($order->initiator)
                <div class="info-row">
                    <span class="info-label">Ініціатор</span>
                    <span class="info-value">{{ $order->initiator }}</span>
                </div>
                @endif
            </div>
        </div>

        <div class="actions">
            <form method="POST" action="{{ URL::temporarySignedRoute('approvals.respond', $approval->linkValidUntil(), ['token' => $approval->token]) }}" id="approve-form">
                @csrf
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="btn btn-approve" id="btn-approve">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                    Погоджую замовлення
                </button>
            </form>

            <a href="{{ URL::temporarySignedRoute('approvals.show', $approval->linkValidUntil(), ['token' => $approval->token, 'action' => 'reject']) }}" class="alt-link">
                Або відхилити замовлення
            </a>
        </div>

        <div class="note">
            Натиснувши кнопку, ви підтверджуєте замовлення.<br>
            Паперова заявка не потрібна.
        </div>
    </div>

    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        document.getElementById('approve-form').addEventListener('submit', function() {
            var btn = document.getElementById('btn-approve');
            btn.disabled = true;
            btn.textContent = 'Зачекайте...';
        });
    </script>
</body>
</html>

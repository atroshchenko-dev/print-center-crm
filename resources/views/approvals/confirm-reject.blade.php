<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#dc2626">
    <link rel="icon" type="image/png" href="/favicon.png">
    <title>Відхилення {{ $order->order_number }} | Центр поліграфії</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#f0f2f5 0%,#e2e6ed 100%);color:#333;padding:1rem}
        .card{background:#fff;border-radius:1rem;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:440px;width:100%;overflow:hidden}
        .header{background:#dc2626;color:#fff;padding:1.5rem;text-align:center}
        .header .icon{margin-bottom:.5rem;display:flex;align-items:center;justify-content:center}
        .header h1{font-size:1.1rem;font-weight:600}
        .header .brand{font-size:.7rem;opacity:.8;margin-top:.25rem}
        .body{padding:1.5rem}
        .order-info{background:#f9fafb;border-radius:.5rem;padding:1rem;margin-bottom:1.25rem}
        .info-row{display:flex;justify-content:space-between;padding:.3rem 0;font-size:.9rem}
        .info-label{color:#6b7280}
        .info-value{font-weight:500;color:#374151}
        /* A category heading reuses .info-row for layout, but must not read
           as just another item. */
        .category-row{padding-top:.75rem}
        .category-row .info-label,.category-row .info-value{font-weight:700;text-transform:uppercase;font-size:.75rem;color:#1D4289}
        .actions{padding:0 1.5rem 1.5rem}
        textarea{width:100%;min-height:80px;padding:.75rem;border:1.5px solid #d1d5db;border-radius:.5rem;font-family:inherit;font-size:.9rem;resize:vertical;margin-bottom:.75rem}
        textarea:focus{outline:none;border-color:#dc2626}
        .btn{display:flex;align-items:center;justify-content:center;gap:.5rem;width:100%;padding:1rem;border:none;border-radius:.5rem;font-size:1rem;font-weight:700;cursor:pointer;text-align:center;transition:all .2s}
        .btn-reject{background:#dc2626;color:#fff}
        .btn-reject:hover{background:#b91c1c}
        .btn-reject:disabled{background:#9ca3af;cursor:wait}
        .alt-link{display:block;text-align:center;margin-top:.75rem;font-size:.8rem;color:#059669;text-decoration:none}
        .alt-link:hover{text-decoration:underline}
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div class="icon">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </div>
            <h1>Відхилення замовлення</h1>
            <div class="brand">Департамент поліграфії університету</div>
        </div>
        <div class="body">
            <div class="order-info">
                <div class="info-row">
                    <span class="info-label">Замовлення</span>
                    <span class="info-value" style="font-family:Consolas,monospace">{{ $order->order_number }}</span>
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
            <form method="POST" action="{{ URL::temporarySignedRoute('approvals.respond', $approval->linkValidUntil(), ['token' => $approval->token]) }}" id="reject-form">
                @csrf
                <input type="hidden" name="action" value="reject">
                <textarea name="rejection_reason" maxlength="500" placeholder="Причина відхилення (необов'язково)"></textarea>
                <button type="submit" class="btn btn-reject" id="btn-reject">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    Підтвердити відхилення
                </button>
            </form>
            <a href="{{ URL::temporarySignedRoute('approvals.show', $approval->linkValidUntil(), ['token' => $approval->token, 'action' => 'approve']) }}" class="alt-link">Або погодити замовлення</a>
        </div>
    </div>
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        document.getElementById('reject-form').addEventListener('submit', function() {
            var btn = document.getElementById('btn-reject');
            btn.disabled = true;
            btn.textContent = 'Зачекайте...';
        });
    </script>
</body>
</html>

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1D4289">
    <link rel="icon" type="image/png" href="/favicon.png">
    <title>{{ $action === 'approved' ? 'Замовлення погоджено' : 'Замовлення відхилено' }} | Центр поліграфії</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#f0f2f5 0%,#e2e6ed 100%);padding:1rem}
        .card{background:#fff;border-radius:1rem;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:420px;width:100%;text-align:center;padding:3rem 2rem}
        .icon{margin-bottom:1rem;display:flex;align-items:center;justify-content:center}
        .icon-approve{color:#059669}
        .icon-reject{color:#dc2626}
        h1{font-size:1.25rem;font-weight:600;color:#1D4289;margin-bottom:.5rem}
        p{color:#6b7280;font-size:.95rem;margin-bottom:.5rem}
        .order-num{font-weight:600;color:#374151;font-family:'SFMono-Regular',Consolas,monospace}
        .note{margin-top:1.5rem;padding:1rem;background:#f9fafb;border-radius:.5rem;font-size:.85rem;color:#6b7280}
    </style>
</head>
<body>
    <div class="card">
        @if($action === 'approved')
            <div class="icon icon-approve">
                <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/></svg>
            </div>
            <h1>Замовлення погоджено</h1>
            <p>Ви успішно погодили замовлення</p>
            <p class="order-num">{{ $approval->order->order_number }}</p>
            <div class="note">
                Заявка автоматично відмічена як отримана в системі.
                Паперова заявка не потрібна.
            </div>
        @else
            <div class="icon icon-reject">
                <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>
            </div>
            <h1>Замовлення відхилено</h1>
            <p>Ви відхилили замовлення</p>
            <p class="order-num">{{ $approval->order->order_number }}</p>
            @if($approval->rejection_reason)
                <div class="note">
                    <strong>Причина:</strong> {{ $approval->rejection_reason }}
                </div>
            @endif
            <div class="note">
                Оператори поліграфії отримали сповіщення про ваше рішення.
            </div>
        @endif
    </div>
</body>
</html>

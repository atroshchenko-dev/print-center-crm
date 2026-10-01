<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1D4289">
    <link rel="icon" type="image/png" href="/favicon.png">
    <title>Вже оброблено | Центр поліграфії</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#f0f2f5 0%,#e2e6ed 100%);padding:1rem}
        .card{background:#fff;border-radius:1rem;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:420px;width:100%;text-align:center;padding:3rem 2rem}
        .icon{margin-bottom:1rem;display:flex;align-items:center;justify-content:center;color:#6b7280}
        h1{font-size:1.25rem;font-weight:600;color:#1D4289;margin-bottom:.5rem}
        p{color:#6b7280;font-size:.95rem}
        .status{margin-top:1rem;display:inline-flex;align-items:center;gap:.35rem;padding:.4rem 1rem;border-radius:2rem;font-size:.85rem;font-weight:600}
        .status-approved{background:#d1fae5;color:#065f46}
        .status-rejected{background:#fee2e2;color:#991b1b}
        .status-superseded{background:#e0e7ff;color:#3730a3}
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">
            <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
        </div>
        @if(!empty($order_deleted))
            <h1>Замовлення видалено</h1>
            <p>Це замовлення було видалено з системи.<br>Погодження більше не потрібне.</p>
            <div class="status status-superseded">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                Видалено
            </div>
        @elseif(!empty($order_cancelled))
            <h1>Замовлення скасовано</h1>
            <p>Це замовлення було скасовано.<br>Погодження більше не потрібне.</p>
            <div class="status status-rejected">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
                Скасовано
            </div>
        @elseif($approval->status === 'superseded')
            <h1>Посилання замінено</h1>
            <p>Це посилання більше не активне — було надіслано нове.<br>Перевірте пошту на наявність свіжого листа.</p>
            <div class="status status-superseded">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                Замінено
            </div>
        @else
            <h1>Замовлення вже оброблено</h1>
            <p>Це замовлення було {{ $approval->status === 'approved' ? 'погоджено' : 'відхилено' }}
               @if($approval->responded_at)
                   {{ $approval->responded_at->timezone('Europe/Kyiv')->format('d.m.Y о H:i') }}
               @endif
            .</p>
            <div class="status {{ $approval->status === 'approved' ? 'status-approved' : 'status-rejected' }}">
                @if($approval->status === 'approved')
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
                    Погоджено
                @else
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    Відхилено
                @endif
            </div>
        @endif
    </div>
</body>
</html>

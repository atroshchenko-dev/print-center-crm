<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1D4289">
    <link rel="icon" type="image/png" href="/favicon.png">
    <title>Погодження замовлення {{ $order->order_number }} | Центр поліграфії</title>
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
            max-width: 480px;
            width: 100%;
            overflow: hidden;
        }
        .header {
            background: #1D4289;
            color: white;
            padding: 1.5rem;
            text-align: center;
        }
        .header h1 { font-size: 1.1rem; font-weight: 600; margin-top: 0.5rem; }
        .header .brand { font-size: 0.75rem; opacity: 0.8; text-transform: uppercase; letter-spacing: 0.1em; }
        .body { padding: 1.5rem; }
        .order-number {
            text-align: center;
            font-size: 1.25rem;
            font-weight: 700;
            color: #1D4289;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e5e7eb;
            font-family: 'SFMono-Regular', Consolas, monospace;
        }
        .field { margin-bottom: 0.75rem; }
        .field-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #9ca3af;
            margin-bottom: 0.15rem;
        }
        .field-value { font-size: 0.95rem; color: #374151; }
        .items-list {
            background: #f9fafb;
            border-radius: 0.5rem;
            padding: 0.75rem;
            margin: 1rem 0;
        }
        .items-list .item {
            display: flex;
            justify-content: space-between;
            padding: 0.4rem 0;
            font-size: 0.9rem;
        }
        /* Only between two genuine item rows — never above a category
           heading, and never between a heading and the first item under it,
           so the heading below is not read as one more item in the list. */
        .items-list .item:not(.category-row) + .item:not(.category-row) { border-top: 1px solid #e5e7eb; }
        .items-list .item-name { color: #374151; }
        .items-list .item-qty { color: #6b7280; font-size: 0.85rem; }
        .items-list .item.category-row {
            padding-top: 0.75rem;
        }
        .items-list .item.category-row .item-name,
        .items-list .item.category-row .item-qty {
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.75rem;
            color: #1D4289;
        }
        .actions {
            padding: 1.5rem;
            background: #f9fafb;
            border-top: 1px solid #e5e7eb;
        }
        .btn {
            display: block;
            width: 100%;
            padding: 0.85rem 1rem;
            border: none;
            border-radius: 0.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
        }
        .btn-approve {
            background: #059669;
            color: white;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .btn-approve:hover { background: #047857; }
        .btn-reject {
            background: white;
            color: #dc2626;
            border: 1.5px solid #fca5a5;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .btn-reject:hover { background: #fef2f2; border-color: #dc2626; }
        .rejection-area {
            display: none;
            margin-top: 0.75rem;
        }
        .rejection-area.visible { display: block; }
        .rejection-area textarea {
            width: 100%;
            min-height: 80px;
            padding: 0.75rem;
            border: 1.5px solid #d1d5db;
            border-radius: 0.5rem;
            font-family: inherit;
            font-size: 0.9rem;
            resize: vertical;
            margin-bottom: 0.75rem;
        }
        .rejection-area textarea:focus { outline: none; border-color: #dc2626; }
        .btn-confirm-reject {
            background: #dc2626;
            color: white;
        }
        .btn-confirm-reject:hover { background: #b91c1c; }
        .btn-cancel {
            background: white;
            color: #6b7280;
            border: 1px solid #d1d5db;
            margin-top: 0.5rem;
        }
        .btn-cancel:hover { background: #f9fafb; }
        .material-desc {
            font-size: 0.8rem;
            color: #6b7280;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div class="brand">Департамент поліграфії університету</div>
            <h1>Погодження замовлення</h1>
        </div>

        <div class="body">
            <div class="order-number">{{ $order->order_number }}</div>

            <div class="field">
                <div class="field-label">Підписант</div>
                <div class="field-value">{{ $order->authorized_person }}</div>
            </div>

            @if($order->cost_center)
            <div class="field">
                <div class="field-label">Центр витрат</div>
                <div class="field-value">{{ $order->cost_center }}</div>
            </div>
            @endif

            @if($order->initiator)
            <div class="field">
                <div class="field-label">Ініціатор</div>
                <div class="field-value">{{ $order->initiator }}</div>
            </div>
            @endif

            <div class="field">
                <div class="field-label">Дата створення</div>
                <div class="field-value">{{ $order->created_at->timezone('Europe/Kyiv')->format('d.m.Y H:i') }}</div>
            </div>

            <div class="items-list">
                @foreach($itemsByCategory as $group)
                <div class="item category-row">
                    <span class="item-name">{{ $group['category'] }}</span>
                </div>
                    @foreach($group['items'] as $item)
                <div class="item">
                    <div>
                        <span class="item-name">{{ $item['name'] }}</span>
                        @if($item['material'])
                            <br><span class="material-desc">{{ $item['material'] }}</span>
                        @endif
                    </div>
                    <span class="item-qty">&times; {{ $item['quantity'] }}</span>
                </div>
                    @endforeach
                @endforeach
            </div>
        </div>

        <div class="actions" id="actions-panel">
            {{-- Approve form --}}
            <form method="POST" action="{{ URL::temporarySignedRoute('approvals.respond', $approval->linkValidUntil(), ['token' => $approval->token]) }}" id="approve-form">
                @csrf
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="btn btn-approve" id="btn-approve">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                    Погоджую
                </button>
            </form>

            {{-- Reject toggle --}}
            <button type="button" class="btn btn-reject" id="btn-reject-toggle" onclick="showRejectForm()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                Відхиляю
            </button>

            {{-- Reject form (hidden initially) --}}
            <div class="rejection-area" id="rejection-area">
                <form method="POST" action="{{ URL::temporarySignedRoute('approvals.respond', $approval->linkValidUntil(), ['token' => $approval->token]) }}">
                    @csrf
                    <input type="hidden" name="action" value="reject">
                    <textarea name="rejection_reason" placeholder="Причина відхилення (необов'язково)"></textarea>
                    <button type="submit" class="btn btn-confirm-reject">Підтвердити відхилення</button>
                    <button type="button" class="btn btn-cancel" onclick="hideRejectForm()">Скасувати</button>
                </form>
            </div>
        </div>
    </div>

    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        function showRejectForm() {
            document.getElementById('rejection-area').classList.add('visible');
            document.getElementById('btn-reject-toggle').style.display = 'none';
            document.getElementById('approve-form').style.display = 'none';
        }
        function hideRejectForm() {
            document.getElementById('rejection-area').classList.remove('visible');
            document.getElementById('btn-reject-toggle').style.display = '';
            document.getElementById('approve-form').style.display = '';
        }
        // Double-click protection
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                const btn = this.querySelector('button[type="submit"]');
                btn.disabled = true;
                btn.textContent = 'Зачекайте...';
            });
        });
    </script>
</body>
</html>

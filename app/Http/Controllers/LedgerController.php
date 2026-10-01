<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Ledger\WithdrawalRequest;
use App\Models\Shift;
use App\Services\AuditService;
use App\Services\LedgerService;
use App\Services\ShiftService;
use App\Services\TelegramService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LedgerController extends Controller
{
    public function __construct(
        private readonly LedgerService $ledgerService,
        private readonly AuditService  $auditService,
        private readonly ShiftService  $shiftService,
        private readonly TelegramService $telegram,
    ) {}

    /**
     * Show the ledger history for the current shift.
     */
    public function history(Request $request): Response|RedirectResponse
    {
        $shift = $this->shiftService->getCurrentShift();

        if (! $shift) {
            return redirect()->route('shifts.open.form')
                ->with('error', 'Немає відкритої зміни.');
        }

        return Inertia::render('Ledger/History', [
            'shift'        => $shift,
            'transactions' => $shift->ledgerTransactions()
                ->with('user', 'order')
                ->latest('created_at')
                ->get(),
            'balance'      => $this->ledgerService->getCurrentBalance($shift),
        ]);
    }

    /**
     * Record a cash withdrawal.
     */
    public function withdrawal(WithdrawalRequest $request): RedirectResponse
    {
        $data  = $request->validated();
        $shift = $this->shiftService->getCurrentShift();
        $user  = $request->user();

        try {
            $this->ledgerService->recordWithdrawal(
                shift:   $shift,
                amount:  $data['amount'],
                comment: $data['comment'],
                user:    $user,
            );

            $this->auditService->log(
                'cash_withdrawal',
                $user,
                "Cash withdrawal: {$data['amount']} UAH. Reason: {$data['comment']}",
                $shift->id,
                ['amount' => $data['amount']],
            );

            $this->telegram->cashWithdrawal($user->name, (float) $data['amount'], $data['comment']);

            return back()->with('success', 'Видача готівки зафіксована.');
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}

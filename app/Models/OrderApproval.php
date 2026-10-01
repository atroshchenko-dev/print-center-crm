<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * OrderApproval — tracks digital approval/rejection by authorized signatories.
 *
 * Each approval has a unique token used in signed URLs sent via email.
 * Signatories don't need CRM accounts — they approve via one-time links.
 *
 * @property int $id
 * @property int $order_id
 * @property string $token
 * @property string $signatory_email
 * @property string $signatory_name
 * @property string $status pending|approved|rejected|superseded
 * @property string|null $rejection_reason
 * @property Carbon|null $responded_at
 * @property string|null $responded_ip
 * @property Carbon $expires_at
 * @property Carbon|null $anonymized_at
 * @property-read Order|null $order
 */
class OrderApproval extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_id',
        'token',
        'signatory_email',
        'signatory_name',
        'status',
        'rejection_reason',
        'responded_at',
        'responded_ip',
        'expires_at',
        'anonymized_at',
    ];

    protected $casts = [
        'responded_at'  => 'datetime',
        'expires_at'    => 'datetime',
        'anonymized_at' => 'datetime',
    ];

    // ─── Relationships ───────────────────────────────────

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // ─── Helpers ─────────────────────────────────────────

    /**
     * How long a link stays *readable* after the approval itself has expired.
     *
     * The row and the signature used to die at the same instant, and it was
     * written down as a virtue: «a link that outlives its row, or vice versa,
     * is a link whose expiry nobody can state» (OrderApprovalEmailTest). What
     * nobody tried is what a signatory sees one minute later — the `signed`
     * middleware refuses the request before ApprovalController runs, so the
     * page that exists to say «Посилання протухло», in Ukrainian, with what to
     * do next, could never render for the link the system actually mails. The
     * signatory got a bare 403.
     *
     * So the two clocks are deliberately no longer the same clock, and they
     * answer different questions: `expires_at` decides whether the approval can
     * still *act*, the signature only proves the link was not forged. The grace
     * window is what the explanation is served through — a page that carries no
     * order data, only the sentence «зверніться до поліграфії».
     *
     * A month, because the failure this exists for is an email read late.
     */
    public const LINK_GRACE_DAYS = 30;

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * The instant every signed URL for this approval stops verifying.
     *
     * One author for both the mailed links and the ones the pages mint: the
     * views used to build theirs with `URL::signedRoute()`, which never expires
     * at all, so the TTL the class docblock advertises was gone the moment the
     * signatory opened the page.
     */
    public function linkValidUntil(): CarbonInterface
    {
        return $this->expires_at->copy()->addDays(self::LINK_GRACE_DAYS);
    }

    public function isActionable(): bool
    {
        return $this->isPending() && ! $this->isExpired();
    }

    // ─── Retention ─────────────────────────────────

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }

    /**
     * Approvals whose personal data is due for removal.
     *
     * The clock starts when the approval stopped being live: the moment the
     * signatory answered, or — for links nobody ever opened — the moment the
     * link expired. Rows already processed are excluded by anonymized_at, so
     * the job is idempotent and cheap to run nightly.
     */
    public function scopeDuePersonalDataRemoval(Builder $query, CarbonInterface $cutoff): Builder
    {
        return $query
            ->whereNull('anonymized_at')
            ->where(function (Builder $q) use ($cutoff): void {
                $q->where('responded_at', '<', $cutoff)
                    ->orWhere(function (Builder $never) use ($cutoff): void {
                        $never->whereNull('responded_at')->where('expires_at', '<', $cutoff);
                    });
            });
    }

    /**
     * Strip the identifying fields, keep the approval itself.
     *
     * Who approved is gone; that an approval happened, when, and with what
     * outcome stays — those are the parts the financial trail depends on.
     */
    public function anonymize(): void
    {
        $placeholder = (string) config('privacy.placeholder');

        $this->forceFill([
            'signatory_email' => $placeholder,
            'signatory_name'  => $placeholder,
            'responded_ip'    => null,
            'anonymized_at'   => now(),
        ])->save();
    }
}

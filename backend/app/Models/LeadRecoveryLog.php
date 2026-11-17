<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * ทั้งการส่งซ้ำ (ทาง legacy) และงาน "ตามลูกค้า" (customer follow-up) อยู่ในตารางนี้
 * การ์ด B2a: คอลัมน์/ค่าคงที่/casts/relations เท่านั้น — ตรรกะธุรกิจเป็นของการ์ดถัดไป
 */
class LeadRecoveryLog extends Model
{
    use HasFactory;

    /** lifecycle ของ delivery_status (ค่าเก่า sent/failed/blocked คงใช้ได้) */
    public const DELIVERY_STATUS_CANDIDATE = 'candidate';

    public const DELIVERY_STATUS_SORTING = 'sorting';

    public const DELIVERY_STATUS_EXCLUDED = 'excluded';

    public const DELIVERY_STATUS_SORT_RETRY = 'sort_retry';

    public const DELIVERY_STATUS_DEFERRED = 'deferred';

    public const DELIVERY_STATUS_DRAFTING = 'drafting';

    public const DELIVERY_STATUS_PENDING = 'pending';

    public const DELIVERY_STATUS_APPROVED = 'approved';

    public const DELIVERY_STATUS_SENDING = 'sending';

    public const DELIVERY_STATUS_SENT = 'sent';

    public const DELIVERY_STATUS_RETRY = 'retry';

    public const DELIVERY_STATUS_SEND_FAILED = 'send_failed';

    public const DELIVERY_STATUS_SKIPPED = 'skipped';

    public const DELIVERY_STATUS_EXPIRED = 'expired';

    public const DELIVERY_STATUS_CANCELLED = 'cancelled';

    /** group_code G1-G4 (ชื่อกลุ่มดูที่ plan §3.1) */
    public const GROUP_CODE_G1 = 'G1';

    public const GROUP_CODE_G2 = 'G2';

    public const GROUP_CODE_G3 = 'G3';

    public const GROUP_CODE_G4 = 'G4';

    /** owner_action ที่ผู้ใช้กด Send/Skip/Edit หรือระบบส่งอัตโนมัติ */
    public const OWNER_ACTION_SENT = 'sent';

    public const OWNER_ACTION_EDITED = 'edited';

    public const OWNER_ACTION_SKIPPED = 'skipped';

    public const OWNER_ACTION_EXPIRED = 'expired';

    public const OWNER_ACTION_AUTOMATIC = 'automatic';

    protected $fillable = [
        'conversation_id',
        'bot_id',
        'attempt_number',
        'message_mode',
        'message_sent',
        'sent_at',
        'delivery_status',
        'error_message',
        'customer_responded',
        'responded_at',
        // การ์ด B2a — คอลัมน์ใหม่ฝั่ง "ตามลูกค้า"
        'silence_user_message_id',
        'observed_last_message_id',
        'customer_key',
        'sales_started_at',
        'payment_step',
        'transfer_summary_sent',
        'group_code',
        'group_confidence',
        'declined_score',
        'decision_version',
        'context_redacted',
        'sort_answers',
        'check_answers',
        'draft',
        'drafted_at',
        'draft_day',
        'draft_reserved_at',
        'draft_model',
        'owner_action',
        'owner_telegram_user_id',
        'owner_action_at',
        'check_overridden',
        'retry_key',
        'first_push_at',
        'line_request_id',
        'message_id',
        'next_attempt_at',
        'attempt_count',
        'claimed_at',
        'claim_token',
        'reason_code',
        'sort_latency_ms',
        'draft_latency_ms',
        'check_latency_ms',
        'model_cost',
        'usage_totals',
        'replied_within_72h',
        'response_window_closed_at',
        'ordered_within_7d',
        'order_id',
        'ordered_at',
        'order_window_closed_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'customer_responded' => 'boolean',
        'responded_at' => 'datetime',
        // การ์ด B2a — casts คอลัมน์ใหม่
        'silence_user_message_id' => 'integer',
        'observed_last_message_id' => 'integer',
        'sales_started_at' => 'datetime',
        'payment_step' => 'boolean',
        'transfer_summary_sent' => 'boolean',
        'group_confidence' => 'decimal:4',
        'declined_score' => 'decimal:4',
        'context_redacted' => 'array',
        'sort_answers' => 'array',
        'check_answers' => 'array',
        'drafted_at' => 'datetime',
        'draft_day' => 'date',
        'draft_reserved_at' => 'datetime',
        'owner_action_at' => 'datetime',
        'check_overridden' => 'boolean',
        'first_push_at' => 'datetime',
        'message_id' => 'integer',
        'next_attempt_at' => 'datetime',
        'attempt_count' => 'integer',
        'claimed_at' => 'datetime',
        'sort_latency_ms' => 'integer',
        'draft_latency_ms' => 'integer',
        'check_latency_ms' => 'integer',
        'model_cost' => 'decimal:6',
        'usage_totals' => 'array',
        'replied_within_72h' => 'boolean',
        'response_window_closed_at' => 'datetime',
        'ordered_within_7d' => 'boolean',
        'order_id' => 'integer',
        'ordered_at' => 'datetime',
        'order_window_closed_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function silenceUserMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'silence_user_message_id');
    }

    public function observedLastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'observed_last_message_id');
    }

    /** ข้อความที่ถูกส่งจริง (push) ถ้ามี */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function approval(): HasOne
    {
        return $this->hasOne(FollowUpApproval::class);
    }
}

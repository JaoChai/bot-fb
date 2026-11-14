<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * บัตรอนุมัติข้อความ "ตามลูกค้า" ใน Telegram — หนึ่ง log ต่อบัตรเดียว (unique)
 * การ์ด B2a: โครงสร้างล้วนๆ ไม่มีตรรกธุรกิจ
 */
class FollowUpApproval extends Model
{
    use HasFactory;

    /** วงจรบัตร */
    public const STATUS_PENDING = 'pending';

    public const STATUS_EDITING = 'editing';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    /** สถานะส่งการ์ดใน Telegram (ก่อน/หลังแก้ไข) */
    public const TELEGRAM_STATE_NEW = 'new';

    public const TELEGRAM_STATE_SENDING = 'sending';

    public const TELEGRAM_STATE_SENT = 'sent';

    public const TELEGRAM_STATE_UNKNOWN = 'unknown';

    public const TELEGRAM_STATE_FAILED = 'failed';

    protected $fillable = [
        'lead_recovery_log_id',
        'status',
        'revision',
        'nonce',
        'text',
        'check_answers',
        'check_overridden',
        'expires_at',
        'telegram_plugin_id',
        'telegram_chat_id',
        'telegram_message_id',
        'telegram_delivery_state',
        'edit_prompt_message_id',
        'editor_telegram_user_id',
        'last_update_id',
        'confirmed_revision',
    ];

    protected $casts = [
        'revision' => 'integer',
        'check_answers' => 'array',
        'check_overridden' => 'boolean',
        'expires_at' => 'datetime',
        'telegram_message_id' => 'integer',
        'edit_prompt_message_id' => 'integer',
        'last_update_id' => 'integer',
        'confirmed_revision' => 'integer',
    ];

    public function leadRecoveryLog(): BelongsTo
    {
        return $this->belongsTo(LeadRecoveryLog::class);
    }

    public function telegramPlugin(): BelongsTo
    {
        return $this->belongsTo(FlowPlugin::class);
    }
}

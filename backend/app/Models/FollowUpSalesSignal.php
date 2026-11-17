<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * read model รายห้องของสัญญาณการขาย — ไร้ข้อความดิบ มี id + เวลา + ตัวนับเท่านั้น
 * หนึ่งแถวต่อ conversation (PK ซ้ำกับ conversation_id); แถวขาด/ล้าสมัย = ไม่ eligible (D2)
 * การ์ด B2a: โครงสร้างล้วนๆ ไม่มีตรรกธุรกิจ
 */
class FollowUpSalesSignal extends Model
{
    use HasFactory;

    /** ตารางไม่มี created_at/updated_at (plan §3.1) — เวลาที่ต้องใช้มี projected_at/last_* เอง */
    public $timestamps = false;

    protected $table = 'follow_up_sales_signals';

    /** ตารางใช้ PK = conversation_id (ไม่มีคอลัมน์ id) — Eloquent ต้องรู้ ไม่งั้น PostgreSQL `returning "id"` พัง */
    protected $primaryKey = 'conversation_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'conversation_id',
        'last_message_id',
        'last_user_message_id',
        'last_user_at',
        'last_message_at',
        'latest_order_id',
        'sales_started_at',
        'customer_message_count',
        'payment_step',
        'transfer_summary_sent',
        'sensitive_account_data',
        'projected_at',
        'projection_version',
    ];

    protected $casts = [
        'conversation_id' => 'integer',
        'last_message_id' => 'integer',
        'last_user_message_id' => 'integer',
        'last_user_at' => 'datetime',
        'last_message_at' => 'datetime',
        'latest_order_id' => 'integer',
        'sales_started_at' => 'datetime',
        'customer_message_count' => 'integer',
        'payment_step' => 'boolean',
        'transfer_summary_sent' => 'boolean',
        'sensitive_account_data' => 'boolean',
        'projected_at' => 'datetime',
        'projection_version' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    public function lastUserMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_user_message_id');
    }

    public function latestOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'latest_order_id');
    }
}

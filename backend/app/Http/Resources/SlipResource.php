<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SlipResource extends JsonResource
{
    private const LABELS = [
        'passed' => 'ผ่าน',
        'fake' => 'ปลอม',
        'wrong_account' => 'บัญชีผิด',
        'duplicate' => 'สลิปซ้ำ',
        'amount_mismatch' => 'ยอดไม่ตรง',
        'no_pending_order' => 'ไม่มีออเดอร์ค้าง',
        'unreadable' => 'อ่านสลิปไม่ได้',
        'api_error' => 'API ผิดพลาด',
        'config_error' => 'ตั้งค่าไม่ครบ',
        'image_download_failed' => 'โหลดรูปไม่ได้',
        'pending' => 'รอธนาคารยืนยัน',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'created_at' => $this->created_at->toIso8601String(),
            'status' => $this->status,
            'status_label' => self::LABELS[$this->status] ?? $this->status,
            'amount' => $this->amount,
            'trans_ref' => $this->trans_ref,
            'receiver_account' => $this->receiver_account,
            'conversation_id' => $this->conversation_id,
            'customer_name' => $this->conversation?->customerProfile?->display_name,
            // passthrough เฉพาะ data node สำหรับกล่องรายละเอียด (null-safe, ไม่เดา path ลึก)
            'raw' => is_array($this->raw_response) ? ($this->raw_response['data'] ?? null) : null,
        ];
    }
}

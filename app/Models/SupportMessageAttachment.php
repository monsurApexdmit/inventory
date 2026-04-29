<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SupportMessageAttachment extends Model
{
    protected $table = 'support_message_attachments';

    protected $fillable = [
        'support_message_id',
        'ticket_id',
        'company_id',
        'original_name',
        'stored_path',
        'mime_type',
        'size_bytes',
        'attachment_type',
    ];

    protected static function booted(): void
    {
        static::deleting(function (SupportMessageAttachment $attachment) {
            if ($attachment->stored_path) {
                Storage::disk('public')->delete($attachment->stored_path);
            }
        });
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'support_message_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }
}

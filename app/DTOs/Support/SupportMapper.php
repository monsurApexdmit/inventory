<?php

namespace App\DTOs\Support;

use App\DTOs\BaseMapper;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use Illuminate\Database\Eloquent\Model;

class SupportMapper extends BaseMapper
{
    public function toDTO(Model $model): SupportTicketDTO
    {
        if (!$model instanceof SupportTicket) {
            throw new \InvalidArgumentException('Model must be instance of SupportTicket');
        }

        $messages = [];
        if ($model->relationLoaded('messages')) {
            $messages = $model->messages->map(fn(SupportMessage $m) => (new SupportMessageDTO(
                id:         $m->id,
                ticketId:   $m->ticket_id,
                customerId: $m->customer_id,
                body:       $m->body,
                senderType: $m->sender_type,
                senderName: $m->sender_name,
                createdAt:  $this->formatTimestamp($m->created_at),
                attachments: $m->relationLoaded('attachments')
                    ? $m->attachments->map(fn(SupportMessageAttachment $attachment) => (new SupportAttachmentDTO(
                        id: $attachment->id,
                        name: $attachment->original_name,
                        url: $this->attachmentUrl($attachment->stored_path),
                        mimeType: $attachment->mime_type,
                        sizeBytes: (int) $attachment->size_bytes,
                        type: $attachment->attachment_type,
                        isImage: $attachment->attachment_type === 'image',
                        isAudio: $attachment->attachment_type === 'voice',
                    ))->toArray())->values()->all()
                    : [],
            ))->toArray())->values()->all();
        }

        $customerName  = $model->customer_name  ?? $model->customer?->name;
        $customerEmail = $model->customer_email ?? $model->customer?->email;

        return new SupportTicketDTO(
            id:            $model->id,
            companyId:     $model->company_id,
            customerId:    $model->customer_id,
            ticketNumber:  $model->ticket_number,
            subject:       $model->subject,
            status:        $model->status,
            priority:      $model->priority,
            category:      $model->category,
            customerName:  $customerName,
            customerEmail: $customerEmail,
            resolvedAt:    $this->formatTimestamp($model->resolved_at),
            createdAt:     $this->formatTimestamp($model->created_at),
            messages:      $messages,
        );
    }

    private function attachmentUrl(string $storedPath): string
    {
        $baseUrl = rtrim(request()?->getSchemeAndHttpHost() ?? config('app.url'), '/');
        return $baseUrl . '/storage/' . ltrim($storedPath, '/');
    }
}

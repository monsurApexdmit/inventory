<?php

namespace App\DTOs\Support;

use App\DTOs\BaseDTO;

class SupportAttachmentDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $url,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly string $type,
        public readonly bool $isImage,
        public readonly bool $isAudio,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'mimeType' => $this->mimeType,
            'sizeBytes' => $this->sizeBytes,
            'type' => $this->type,
            'isImage' => $this->isImage,
            'isAudio' => $this->isAudio,
        ];
    }
}

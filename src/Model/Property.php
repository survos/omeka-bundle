<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Model;

readonly class Property
{
    public function __construct(
        public int $id,
        public string $term,
        public string $label,
        public string $localName,
        public ?string $comment,
        public int $vocabularyId,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['o:id'],
            term: $data['o:term'],
            label: $data['o:label'],
            localName: $data['o:local_name'],
            comment: $data['o:comment'] ?? null,
            vocabularyId: $data['o:vocabulary']['o:id'],
        );
    }
}

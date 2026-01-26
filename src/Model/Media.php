<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Model;

readonly class Media
{
    public function __construct(
        public int $id,
        public int $itemId,
        public string $mediaType,
        public ?string $source,
        public ?string $originalUrl,
        public array $thumbnailUrls,
        public array $properties,
        public array $rawData,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['o:id'],
            itemId: $data['o:item']['o:id'],
            mediaType: $data['o:media_type'] ?? '',
            source: $data['o:source'] ?? null,
            originalUrl: $data['o:original_url'] ?? null,
            thumbnailUrls: $data['thumbnail_display_urls'] ?? [],
            properties: self::extractProperties($data),
            rawData: $data,
        );
    }

    private static function extractProperties(array $data): array
    {
        $properties = [];
        $skip = ['@context', '@id', '@type', 'o:id', 'o:is_public', 'o:owner',
                 'o:resource_class', 'o:resource_template', 'o:thumbnail',
                 'o:created', 'o:modified', 'o:item', 'o:media_type', 'o:ingester',
                 'o:renderer', 'o:source', 'o:original_url', 'o:sha256', 'o:size',
                 'o:filename', 'o:lang', 'o:alt_text', 'o:position', 'thumbnail_display_urls',
                 'data'];

        foreach ($data as $key => $value) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            if (is_array($value)) {
                $properties[$key] = $value;
            }
        }

        return $properties;
    }
}

<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Model;

readonly class Item
{
    public function __construct(
        public int $id,
        public string $title,
        public bool $isPublic,
        public ?\DateTimeImmutable $created,
        public ?\DateTimeImmutable $modified,
        public ?int $resourceTemplateId,
        public ?int $resourceClassId,
        public array $mediaIds,
        public array $itemSetIds,
        public array $properties,
        public array $rawData,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['o:id'],
            title: $data['o:title'] ?? '',
            isPublic: $data['o:is_public'] ?? true,
            created: isset($data['o:created']['@value'])
                ? new \DateTimeImmutable($data['o:created']['@value'])
                : null,
            modified: isset($data['o:modified']['@value'])
                ? new \DateTimeImmutable($data['o:modified']['@value'])
                : null,
            resourceTemplateId: $data['o:resource_template']['o:id'] ?? null,
            resourceClassId: $data['o:resource_class']['o:id'] ?? null,
            mediaIds: array_map(fn($m) => $m['o:id'], $data['o:media'] ?? []),
            itemSetIds: array_map(fn($s) => $s['o:id'], $data['o:item_set'] ?? []),
            properties: self::extractProperties($data),
            rawData: $data,
        );
    }

    private static function extractProperties(array $data): array
    {
        $properties = [];
        $skip = ['@context', '@id', '@type', 'o:id', 'o:is_public', 'o:owner', 
                 'o:resource_class', 'o:resource_template', 'o:thumbnail', 'o:title',
                 'o:created', 'o:modified', 'o:media', 'o:item_set', 'o:site',
                 'thumbnail_display_urls'];

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

    public function getPropertyValue(string $term): ?string
    {
        $values = $this->properties[$term] ?? [];
        if (empty($values)) {
            return null;
        }
        return $values[0]['@value'] ?? $values[0]['@id'] ?? null;
    }

    public function getPropertyValues(string $term): array
    {
        $values = $this->properties[$term] ?? [];
        return array_map(fn($v) => $v['@value'] ?? $v['@id'] ?? null, $values);
    }
}

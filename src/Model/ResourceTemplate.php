<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Model;

readonly class ResourceTemplate
{
    public function __construct(
        public int $id,
        public string $label,
        public ?int $resourceClassId,
        public ?int $titlePropertyId,
        public ?int $descriptionPropertyId,
        public array $properties,
        public array $rawData,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['o:id'],
            label: $data['o:label'],
            resourceClassId: $data['o:resource_class']['o:id'] ?? null,
            titlePropertyId: $data['o:title_property']['o:id'] ?? null,
            descriptionPropertyId: $data['o:description_property']['o:id'] ?? null,
            properties: $data['o:resource_template_property'] ?? [],
            rawData: $data,
        );
    }

    public function getPropertyIds(): array
    {
        return array_map(
            fn($p) => $p['o:property']['o:id'],
            $this->properties
        );
    }
}

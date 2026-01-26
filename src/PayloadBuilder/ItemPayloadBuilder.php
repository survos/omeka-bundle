<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\PayloadBuilder;

use Survos\OmekaBundle\Client\OmekaClient;
use Survos\OmekaBundle\Model\Item;

/**
 * Fluent builder for Omeka item payloads.
 * 
 * Usage:
 *   $item = $omeka->payloadBuilder(templateId: 5)
 *       ->set('dcterms:title', 'My Document')
 *       ->set('dcterms:date', '1862-04-15')
 *       ->set('dcterms:creator', 'John Smith')
 *       ->addMedia('/path/to/scan.tiff')
 *       ->create();
 */
final class ItemPayloadBuilder
{
    private array $properties = [];
    private array $mediaFiles = [];
    private ?int $itemSetId = null;
    private ?int $classId = null;

    public function __construct(
        private OmekaClient $client,
        private ?int $templateId = null,
    ) {}

    public function set(string $term, string|int|array $value): self
    {
        if (!isset($this->properties[$term])) {
            $this->properties[$term] = [];
        }

        if (is_array($value) && !isset($value['value']) && !isset($value['@value'])) {
            // Array of values
            foreach ($value as $v) {
                $this->properties[$term][] = $v;
            }
        } else {
            $this->properties[$term][] = $value;
        }

        return $this;
    }

    public function setUri(string $term, string $uri): self
    {
        $this->properties[$term][] = ['value' => $uri, 'type' => 'uri'];
        return $this;
    }

    public function setResource(string $term, int $resourceId): self
    {
        $this->properties[$term][] = ['value' => $resourceId, 'type' => 'resource:item'];
        return $this;
    }

    public function addMedia(string $path, ?string $title = null): self
    {
        if ($title !== null) {
            $this->mediaFiles[] = ['path' => $path, 'title' => $title];
        } else {
            $this->mediaFiles[] = $path;
        }
        return $this;
    }

    public function inItemSet(int $itemSetId): self
    {
        $this->itemSetId = $itemSetId;
        return $this;
    }

    public function withClass(int $classId): self
    {
        $this->classId = $classId;
        return $this;
    }

    public function withTemplate(int $templateId): self
    {
        $this->templateId = $templateId;
        return $this;
    }

    public function getPayload(): array
    {
        return $this->client->buildPayload($this->properties, $this->templateId);
    }

    public function create(): Item
    {
        return $this->client->createItem(
            properties: $this->properties,
            templateId: $this->templateId,
            classId: $this->classId,
            itemSetId: $this->itemSetId,
            mediaFiles: !empty($this->mediaFiles) ? $this->mediaFiles : null,
        );
    }

    public function reset(): self
    {
        $this->properties = [];
        $this->mediaFiles = [];
        $this->itemSetId = null;
        $this->classId = null;
        return $this;
    }
}

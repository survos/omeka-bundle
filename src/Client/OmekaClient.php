<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Client;

use Survos\OmekaBundle\Exception\OmekaApiException;
use Survos\OmekaBundle\Model\Item;
use Survos\OmekaBundle\Model\Media;
use Survos\OmekaBundle\Model\Property;
use Survos\OmekaBundle\Model\ResourceTemplate;
use Survos\OmekaBundle\Model\SearchResult;
use Survos\OmekaBundle\PayloadBuilder\ItemPayloadBuilder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OmekaClient
{
    private array $propertyCache = [];
    private array $templatePropertyCache = [];

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(OMEKA_API_URL)%')]
        private string $apiUrl,
        #[Autowire('%env(default::OMEKA_KEY_IDENTITY)%')]
        private ?string $keyIdentity = null,
        #[Autowire('%env(default::OMEKA_KEY_CREDENTIAL)%')]
        private ?string $keyCredential = null,
    ) {
        $this->apiUrl = rtrim($this->apiUrl, '/');
    }

    // ========== ITEMS ==========

    public function getItems(
        ?int $resourceTemplateId = null,
        ?int $resourceClassId = null,
        ?int $itemSetId = null,
        ?bool $isPublic = null,
        int $page = 1,
        int $perPage = 25,
        string $sortBy = 'id',
        string $sortOrder = 'desc',
    ): SearchResult {
        $query = array_filter([
            'resource_template_id' => $resourceTemplateId,
            'resource_class_id' => $resourceClassId,
            'item_set_id' => $itemSetId,
            'is_public' => $isPublic,
            'page' => $page,
            'per_page' => $perPage,
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ], fn($v) => $v !== null);

        return $this->search('items', $query);
    }

    public function getItem(int $id): Item
    {
        $data = $this->get("items/{$id}");
        return Item::fromArray($data);
    }

    public function searchItems(
        string $query,
        bool $fulltextSearch = true,
        ?int $resourceTemplateId = null,
        ?int $resourceClassId = null,
        ?int $itemSetId = null,
        int $page = 1,
    ): SearchResult {
        $params = array_filter([
            $fulltextSearch ? 'fulltext_search' : 'search' => $query,
            'resource_template_id' => $resourceTemplateId,
            'resource_class_id' => $resourceClassId,
            'item_set_id' => $itemSetId,
            'page' => $page,
        ], fn($v) => $v !== null);

        return $this->search('items', $params);
    }

    public function filterItemsByProperty(
        string $property,
        string $value,
        string $type = 'eq',
        int $page = 1,
        ?int $resourceTemplateId = null,
    ): SearchResult {
        $propertyId = $this->getPropertyId($property);

        $params = array_filter([
            'property' => [
                [
                    'property' => $propertyId,
                    'type' => $type,
                    'text' => $value,
                ],
            ],
            'resource_template_id' => $resourceTemplateId,
            'page' => $page,
        ], fn($v) => $v !== null);

        return $this->search('items', $params);
    }

    public function createItem(
        array $properties,
        ?int $templateId = null,
        ?int $classId = null,
        ?int $itemSetId = null,
        ?array $mediaFiles = null,
    ): Item {
        $payload = $this->buildPayload($properties, $templateId);

        if ($templateId !== null) {
            $payload['o:resource_template'] = ['o:id' => $templateId];
        }
        if ($classId !== null) {
            $payload['o:resource_class'] = ['o:id' => $classId];
        }
        if ($itemSetId !== null) {
            $payload['o:item_set'] = [['o:id' => $itemSetId]];
        }

        if ($mediaFiles !== null && count($mediaFiles) > 0) {
            $data = $this->postWithMedia('items', $payload, $mediaFiles);
        } else {
            $data = $this->post('items', $payload);
        }

        return Item::fromArray($data);
    }

    public function updateItem(int $id, array $properties): Item
    {
        // Fetch current item, merge changes
        $current = $this->get("items/{$id}");

        foreach ($properties as $term => $value) {
            $propertyId = $this->getPropertyId($term);
            $current[$term] = $this->formatPropertyValues($value, $propertyId);
        }

        $data = $this->put("items/{$id}", $current);
        return Item::fromArray($data);
    }

    public function deleteItem(int $id): void
    {
        $this->delete("items/{$id}");
    }

    // ========== MEDIA ==========

    public function addMediaToItem(
        int $itemId,
        string $filePath,
        ?array $metadata = null,
        ?string $title = null,
    ): Media {
        $payload = [
            'o:ingester' => 'upload',
            'o:item' => ['o:id' => $itemId],
        ];

        if ($title !== null) {
            $payload['dcterms:title'] = [[
                'property_id' => $this->getPropertyId('dcterms:title'),
                'type' => 'literal',
                '@value' => $title,
            ]];
        }

        if ($metadata !== null) {
            $payload = array_merge($payload, $this->buildPayload($metadata));
        }

        $data = $this->postWithMedia('media', $payload, [$filePath]);
        return Media::fromArray($data);
    }

    // ========== RESOURCE TEMPLATES ==========

    public function getResourceTemplates(): array
    {
        $result = $this->search('resource_templates', ['per_page' => 100]);
        return array_map(ResourceTemplate::fromArray(...), $result->results);
    }

    public function getResourceTemplate(int $id): ResourceTemplate
    {
        $data = $this->get("resource_templates/{$id}");
        return ResourceTemplate::fromArray($data);
    }

    public function getResourceTemplateByLabel(string $label): ?ResourceTemplate
    {
        $result = $this->search('resource_templates', ['label' => $label]);
        if (count($result->results) === 0) {
            return null;
        }
        return ResourceTemplate::fromArray($result->results[0]);
    }

    /**
     * @return array<string, array{property_id: int, types: string[]}>
     */
    public function getTemplateProperties(int $templateId): array
    {
        if (isset($this->templatePropertyCache[$templateId])) {
            return $this->templatePropertyCache[$templateId];
        }

        $template = $this->get("resource_templates/{$templateId}");
        $properties = [];

        foreach ($template['o:resource_template_property'] ?? [] as $prop) {
            $propData = $this->get("properties/{$prop['o:property']['o:id']}");
            $term = $propData['o:term'];

            $types = [];
            foreach ($prop['o:data_type'] ?? [] as $dt) {
                $types[] = is_array($dt) ? $dt['name'] : $dt;
            }
            if (empty($types)) {
                $types = ['literal'];
            }

            $properties[$term] = [
                'property_id' => $prop['o:property']['o:id'],
                'types' => $types,
            ];
        }

        $this->templatePropertyCache[$templateId] = $properties;
        return $properties;
    }

    // ========== PROPERTIES ==========

    public function getProperties(): array
    {
        if (!empty($this->propertyCache)) {
            return $this->propertyCache;
        }

        $page = 1;
        $properties = [];

        do {
            $result = $this->search('properties', ['page' => $page, 'per_page' => 100]);
            foreach ($result->results as $prop) {
                $properties[$prop['o:term']] = Property::fromArray($prop);
            }
            $page++;
        } while (count($result->results) === 100);

        $this->propertyCache = $properties;
        return $properties;
    }

    public function getProperty(string $term): ?Property
    {
        $properties = $this->getProperties();
        return $properties[$term] ?? null;
    }

    public function getPropertyId(string $term): int
    {
        $property = $this->getProperty($term);
        if ($property === null) {
            throw new OmekaApiException("Unknown property term: {$term}");
        }
        return $property->id;
    }

    // ========== VOCABULARIES ==========

    public function getVocabularies(): array
    {
        return $this->search('vocabularies', ['per_page' => 100])->results;
    }

    // ========== CUSTOM VOCABS ==========

    /**
     * Get terms from a Custom Vocab by label.
     * Requires the CustomVocab module.
     *
     * @return string[]
     */
    public function getCustomVocabTerms(string $label): array
    {
        $result = $this->search('custom_vocabs', ['label' => $label]);
        if (count($result->results) === 0) {
            return [];
        }

        $vocab = $result->results[0];
        $terms = $vocab['o:terms'] ?? '';

        if (is_string($terms)) {
            return array_filter(array_map('trim', explode("\n", $terms)));
        }

        return $terms;
    }

    // ========== ITEM SETS ==========

    public function getItemSets(int $page = 1, int $perPage = 25): SearchResult
    {
        return $this->search('item_sets', ['page' => $page, 'per_page' => $perPage]);
    }

    public function getItemSet(int $id): array
    {
        return $this->get("item_sets/{$id}");
    }

    // ========== PAYLOAD BUILDING ==========

    public function buildPayload(array $properties, ?int $templateId = null): array
    {
        $templateProperties = $templateId !== null
            ? $this->getTemplateProperties($templateId)
            : [];

        $payload = [];

        foreach ($properties as $term => $values) {
            // Normalize to array
            if (!is_array($values) || (isset($values['value']) || isset($values['@value']))) {
                $values = [$values];
            }

            // Validate against template if provided
            if ($templateId !== null && !isset($templateProperties[$term])) {
                trigger_error("Term '{$term}' not in template {$templateId}", E_USER_WARNING);
                continue;
            }

            $propertyId = $this->getPropertyId($term);
            $payload[$term] = $this->formatPropertyValues($values, $propertyId, $templateProperties[$term] ?? null);
        }

        return $payload;
    }

    public function payloadBuilder(?int $templateId = null): ItemPayloadBuilder
    {
        return new ItemPayloadBuilder($this, $templateId);
    }

    // ========== INTERNAL HTTP ==========

    private function get(string $endpoint): array
    {
        $response = $this->httpClient->request('GET', "{$this->apiUrl}/{$endpoint}", [
            'query' => $this->authQuery(),
        ]);

        return $response->toArray();
    }

    private function search(string $resource, array $query = []): SearchResult
    {
        $response = $this->httpClient->request('GET', "{$this->apiUrl}/{$resource}", [
            'query' => array_merge($this->authQuery(), $query),
        ]);

        $headers = $response->getHeaders();
        $totalResults = (int) ($headers['omeka-s-total-results'][0] ?? 0);

        return new SearchResult(
            totalResults: $totalResults,
            results: $response->toArray(),
        );
    }

    private function post(string $endpoint, array $payload): array
    {
        $this->requireAuth();

        $response = $this->httpClient->request('POST', "{$this->apiUrl}/{$endpoint}", [
            'query' => $this->authQuery(),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    private function postWithMedia(string $endpoint, array $payload, array $mediaFiles): array
    {
        $this->requireAuth();

        $formFields = [
            'data' => json_encode($payload),
        ];

        foreach ($mediaFiles as $index => $file) {
            $path = is_array($file) ? $file['path'] : $file;
            $filename = is_array($file) ? ($file['title'] ?? basename($path)) : basename($path);

            $formFields["file[{$index}]"] = DataPart::fromPath($path, $filename);
        }

        $formData = new FormDataPart($formFields);

        $response = $this->httpClient->request('POST', "{$this->apiUrl}/{$endpoint}", [
            'query' => $this->authQuery(),
            'headers' => $formData->getPreparedHeaders()->toArray(),
            'body' => $formData->bodyToIterable(),
        ]);

        return $response->toArray();
    }

    private function put(string $endpoint, array $payload): array
    {
        $this->requireAuth();

        $response = $this->httpClient->request('PUT', "{$this->apiUrl}/{$endpoint}", [
            'query' => $this->authQuery(),
            'json' => $payload,
        ]);

        return $response->toArray();
    }

    private function delete(string $endpoint): void
    {
        $this->requireAuth();

        $this->httpClient->request('DELETE', "{$this->apiUrl}/{$endpoint}", [
            'query' => $this->authQuery(),
        ]);
    }

    private function authQuery(): array
    {
        if ($this->keyIdentity === null || $this->keyCredential === null) {
            return [];
        }

        return [
            'key_identity' => $this->keyIdentity,
            'key_credential' => $this->keyCredential,
        ];
    }

    private function requireAuth(): void
    {
        if ($this->keyIdentity === null || $this->keyCredential === null) {
            throw new OmekaApiException('Authentication required. Set OMEKA_KEY_IDENTITY and OMEKA_KEY_CREDENTIAL.');
        }
    }

    private function formatPropertyValues(array $values, int $propertyId, ?array $templateDef = null): array
    {
        $formatted = [];

        foreach ($values as $value) {
            // Simple string value
            if (is_string($value) || is_int($value)) {
                $value = ['value' => (string) $value];
            }

            // Determine type
            $type = $value['type'] ?? $templateDef['types'][0] ?? 'literal';

            // Validate type against template
            if ($templateDef !== null && !in_array($type, $templateDef['types'], true)) {
                trigger_error("Type '{$type}' not allowed for this property", E_USER_WARNING);
                continue;
            }

            $formatted[] = match ($type) {
                'uri' => [
                    'property_id' => $propertyId,
                    'type' => 'uri',
                    '@id' => $value['value'] ?? $value['@id'],
                ],
                'resource', 'resource:item', 'resource:media', 'resource:itemset' => [
                    'property_id' => $propertyId,
                    'type' => $type,
                    'value_resource_id' => (int) ($value['value'] ?? $value['value_resource_id'] ?? $value['@id']),
                ],
                default => [
                    'property_id' => $propertyId,
                    'type' => $type,
                    '@value' => $value['value'] ?? $value['@value'],
                ],
            };
        }

        return $formatted;
    }
}

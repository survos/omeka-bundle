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
use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function basename;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_encode;
use function rtrim;
use function str_starts_with;
use function str_ends_with;
use function substr;
use function trim;
use function trigger_error;

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
        ?int $siteId = null,
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
            'site_id' => $siteId,
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
        ?array $itemSetIds = null,
        ?array $siteIds = null,
        ?array $mediaFiles = null,
        ?bool $isPublic = null,
    ): Item {
        $payload = $this->buildPayload($properties, $templateId);

        if ($templateId !== null) {
            $payload['o:resource_template'] = ['o:id' => $templateId];
        }
        if ($classId !== null) {
            $payload['o:resource_class'] = ['o:id' => $classId];
        }
        $itemSetRefs = [];
        if ($itemSetId !== null) {
            $itemSetRefs[] = ['o:id' => $itemSetId];
        }
        if ($itemSetIds !== null) {
            foreach ($itemSetIds as $id) {
                $itemSetRefs[] = ['o:id' => $id];
            }
        }
        if ($itemSetRefs !== []) {
            $payload['o:item_set'] = $itemSetRefs;
        }

        if ($siteIds !== null) {
            $payload['o:site'] = array_map(static fn(int $id): array => ['o:id' => $id], $siteIds);
        }
        if ($isPublic !== null) {
            $payload['o:is_public'] = $isPublic;
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

    public function updateItemSites(int $id, array $siteIds): Item
    {
        $current = $this->get("items/{$id}");
        $currentSites = $current['o:site'] ?? [];
        $merged = [];

        foreach ($currentSites as $site) {
            $siteId = $site['o:id'] ?? null;
            if (is_int($siteId)) {
                $merged[$siteId] = true;
            }
        }

        foreach ($siteIds as $siteId) {
            if (is_int($siteId)) {
                $merged[$siteId] = true;
            }
        }

        $current['o:site'] = array_map(
            static fn(int $siteId): array => ['o:id' => $siteId],
            array_keys($merged),
        );

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

    public function createMediaFromUrl(
        int $itemId,
        string $url,
        ?array $metadata = null,
        ?string $title = null,
    ): Media {
        $payload = [
            'o:ingester' => 'url',
            'o:item' => ['o:id' => $itemId],
            'o:source' => $url,
            'ingest_url' => $url,
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

        $data = $this->post('media', $payload);
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
     * @param array<int, array{
     *     property_id: int,
     *     required: bool,
     *     private: bool,
     *     data_types: string[]
     * }> $properties
     */
    public function createResourceTemplate(
        string $label,
        ?int $resourceClassId,
        ?int $titlePropertyId,
        ?int $descriptionPropertyId,
        array $properties,
    ): ResourceTemplate {
        $payload = [
            'o:label' => $label,
        ];

        if ($resourceClassId !== null) {
            $payload['o:resource_class'] = ['o:id' => $resourceClassId];
        }
        if ($titlePropertyId !== null) {
            $payload['o:title_property'] = ['o:id' => $titlePropertyId];
        }
        if ($descriptionPropertyId !== null) {
            $payload['o:description_property'] = ['o:id' => $descriptionPropertyId];
        }

        $payload['o:resource_template_property'] = array_values(array_map(
            static function (array $property): array {
                return [
                    'o:property' => ['o:id' => $property['property_id']],
                    'o:is_required' => $property['required'],
                    'o:is_private' => $property['private'],
                    'o:data_type' => $property['data_types'],
                ];
            },
            $properties,
        ));

        $data = $this->post('resource_templates', $payload);

        return ResourceTemplate::fromArray($data);
    }

    // ========== RESOURCE CLASSES ==========

    public function getResourceClasses(): array
    {
        return $this->search('resource_classes', ['per_page' => 100])->results;
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

    public function createVocabulary(string $prefix, string $label, ?string $namespaceUri = null): array
    {
        $payload = [
            'o:prefix' => $prefix,
            'o:label' => $label,
        ];

        if ($namespaceUri !== null) {
            $payload['o:namespace_uri'] = $namespaceUri;
        }

        return $this->post('vocabularies', $payload);
    }

    public function createProperty(
        string $term,
        string $label,
        string $localName,
        ?string $comment,
        int $vocabularyId,
    ): array {
        $payload = [
            'o:term' => $term,
            'o:label' => $label,
            'o:local_name' => $localName,
            'o:vocabulary' => ['o:id' => $vocabularyId],
        ];

        if ($comment !== null) {
            $payload['o:comment'] = $comment;
        }

        return $this->post('properties', $payload);
    }

    // ========== SITES ==========

    public function getSites(): array
    {
        return $this->search('sites', ['per_page' => 100])->results;
    }

    public function getSite(int $id): array
    {
        return $this->get("sites/{$id}");
    }

    public function createSite(string $slug, string $title, ?string $theme = null, bool $isPublic = true): array
    {
        $payload = [
            'o:slug' => $slug,
            'o:title' => $title,
            'o:is_public' => $isPublic,
        ];

        if ($theme !== null) {
            $payload['o:theme'] = $theme;
        }

        return $this->post('sites', $payload);
    }

    public function updateSite(int $id, array $payload): array
    {
        return $this->put("sites/{$id}", $payload);
    }

    public function getSitePages(int $siteId, int $page = 1, int $perPage = 100): SearchResult
    {
        return $this->search('site_pages', [
            'site_id' => $siteId,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    public function createSitePage(int $siteId, array $payload): array
    {
        $payload['o:site'] = ['o:id' => $siteId];
        return $this->post('site_pages', $payload);
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

    public function createItemSet(array $properties, ?bool $isPublic = null): array
    {
        $payload = $this->buildPayload($properties);

        if ($isPublic !== null) {
            $payload['o:is_public'] = $isPublic;
        }

        return $this->post('item_sets', $payload);
    }

    public function filterItemSetsByProperty(
        string $property,
        string $value,
        string $type = 'eq',
        int $page = 1,
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
            'page' => $page,
        ], fn($v) => $v !== null);

        return $this->search('item_sets', $params);
    }

    public function getMedia(int $page = 1, int $perPage = 25, ?int $itemId = null): SearchResult
    {
        return $this->search('media', array_filter([
            'page' => $page,
            'per_page' => $perPage,
            'item_id' => $itemId,
        ], fn($v) => $v !== null));
    }

    public function filterMediaByProperty(
        string $property,
        string $value,
        string $type = 'eq',
        int $page = 1,
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
            'page' => $page,
        ], fn($v) => $v !== null);

        return $this->search('media', $params);
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
            $base = $this->apiUrl;
            if (str_ends_with($base, '/api')) {
                $base = substr($base, 0, -4);
            }

            $adminUrl = $base . '/admin/user/1/edit#edit-keys';

            throw new OmekaApiException(
                'Authentication required. Set OMEKA_KEY_IDENTITY and OMEKA_KEY_CREDENTIAL. ' .
                'Create an API key in the Omeka UI: ' . $adminUrl
            );
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

            if (!is_array($value)) {
                continue;
            }

            // Determine type
            $type = $value['type'] ?? $templateDef['types'][0] ?? 'literal';

            // Validate type against template
            if ($templateDef !== null && !in_array($type, $templateDef['types'], true)) {
                trigger_error("Type '{$type}' not allowed for this property", E_USER_WARNING);
                continue;
            }

            $payload = match ($type) {
                'uri' => [
                    'property_id' => $propertyId,
                    'type' => 'uri',
                    '@id' => $value['value'] ?? $value['@id'] ?? null,
                ],
                'resource', 'resource:item', 'resource:media', 'resource:itemset' => [
                    'property_id' => $propertyId,
                    'type' => $type,
                    'value_resource_id' => $value['value'] ?? $value['value_resource_id'] ?? $value['@id'] ?? null,
                ],
                default => [
                    'property_id' => $propertyId,
                    'type' => $type,
                    '@value' => $value['value'] ?? $value['@value'] ?? null,
                ],
            };

            if (($payload['@value'] ?? $payload['@id'] ?? $payload['value_resource_id'] ?? null) === null) {
                continue;
            }

            if (isset($payload['value_resource_id'])) {
                $payload['value_resource_id'] = (int) $payload['value_resource_id'];
            }

            $formatted[] = $payload;
        }

        return $formatted;
    }
}

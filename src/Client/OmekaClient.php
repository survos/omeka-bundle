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
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OmekaClient
{
    private array $propertyCache = [];
    private array $templatePropertyCache = [];

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiUrl,
        private ?string $keyIdentity = null,
        private ?string $keyCredential = null,
    ) {
        $this->apiUrl = \rtrim($this->apiUrl, '/');
    }

    public function getApiUrl(): string
    {
        return $this->apiUrl;
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
        $query = \array_filter([
            'resource_template_id' => $resourceTemplateId,
            'resource_class_id' => $resourceClassId,
            'item_set_id' => $itemSetId,
            'is_public' => $isPublic,
            'site_id' => $siteId,
            'page' => $page,
            'per_page' => $perPage,
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ], fn ($v) => null !== $v);

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
        $params = \array_filter([
            $fulltextSearch ? 'fulltext_search' : 'search' => $query,
            'resource_template_id' => $resourceTemplateId,
            'resource_class_id' => $resourceClassId,
            'item_set_id' => $itemSetId,
            'page' => $page,
        ], fn ($v) => null !== $v);

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

        $params = \array_filter([
            'property' => [
                [
                    'property' => $propertyId,
                    'type' => $type,
                    'text' => $value,
                ],
            ],
            'resource_template_id' => $resourceTemplateId,
            'page' => $page,
        ], fn ($v) => null !== $v);

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

        if (null !== $templateId) {
            $payload['o:resource_template'] = ['o:id' => $templateId];
        }
        if (null !== $classId) {
            $payload['o:resource_class'] = ['o:id' => $classId];
        }
        $itemSetRefs = [];
        if (null !== $itemSetId) {
            $itemSetRefs[] = ['o:id' => $itemSetId];
        }
        if (null !== $itemSetIds) {
            foreach ($itemSetIds as $id) {
                $itemSetRefs[] = ['o:id' => $id];
            }
        }
        if ([] !== $itemSetRefs) {
            $payload['o:item_set'] = $itemSetRefs;
        }

        if (null !== $siteIds) {
            $payload['o:site'] = \array_map(static fn (int $id): array => ['o:id' => $id], $siteIds);
        }
        if (null !== $isPublic) {
            $payload['o:is_public'] = $isPublic;
        }

        if (null !== $mediaFiles && \count($mediaFiles) > 0) {
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
            if (\is_int($siteId)) {
                $merged[$siteId] = true;
            }
        }

        foreach ($siteIds as $siteId) {
            if (\is_int($siteId)) {
                $merged[$siteId] = true;
            }
        }

        $current['o:site'] = \array_map(
            static fn (int $siteId): array => ['o:id' => $siteId],
            \array_keys($merged),
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

        if (null !== $title) {
            $payload['dcterms:title'] = [[
                'property_id' => $this->getPropertyId('dcterms:title'),
                'type' => 'literal',
                '@value' => $title,
            ]];
        }

        if (null !== $metadata) {
            $payload = \array_merge($payload, $this->buildPayload($metadata));
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

        if (null !== $title) {
            $payload['dcterms:title'] = [[
                'property_id' => $this->getPropertyId('dcterms:title'),
                'type' => 'literal',
                '@value' => $title,
            ]];
        }

        if (null !== $metadata) {
            $payload = \array_merge($payload, $this->buildPayload($metadata));
        }

        $data = $this->post('media', $payload);

        return Media::fromArray($data);
    }

    // ========== RESOURCE TEMPLATES ==========

    public function getResourceTemplates(): array
    {
        $result = $this->search('resource_templates', ['per_page' => 100]);

        return \array_map(ResourceTemplate::fromArray(...), $result->results);
    }

    public function getResourceTemplate(int $id): ResourceTemplate
    {
        $data = $this->get("resource_templates/{$id}");

        return ResourceTemplate::fromArray($data);
    }

    public function getResourceTemplateByLabel(string $label): ?ResourceTemplate
    {
        $result = $this->search('resource_templates', ['label' => $label]);
        if (0 === \count($result->results)) {
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

        if (null !== $resourceClassId) {
            $payload['o:resource_class'] = ['o:id' => $resourceClassId];
        }
        if (null !== $titlePropertyId) {
            $payload['o:title_property'] = ['o:id' => $titlePropertyId];
        }
        if (null !== $descriptionPropertyId) {
            $payload['o:description_property'] = ['o:id' => $descriptionPropertyId];
        }

        $payload['o:resource_template_property'] = \array_values(\array_map(
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

    /**
     * @param array<int, array{
     *     property_id: int,
     *     required: bool,
     *     private: bool,
     *     data_types: string[]
     * }> $properties
     */
    public function updateResourceTemplate(
        int $id,
        string $label,
        ?int $resourceClassId,
        ?int $titlePropertyId,
        ?int $descriptionPropertyId,
        array $properties,
    ): ResourceTemplate {
        $current = $this->get("resource_templates/{$id}");
        $current['o:label'] = $label;

        $current['o:resource_class'] = null !== $resourceClassId
            ? ['o:id' => $resourceClassId]
            : null;
        $current['o:title_property'] = null !== $titlePropertyId
            ? ['o:id' => $titlePropertyId]
            : null;
        $current['o:description_property'] = null !== $descriptionPropertyId
            ? ['o:id' => $descriptionPropertyId]
            : null;

        $current['o:resource_template_property'] = \array_values(\array_map(
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

        $data = $this->put("resource_templates/{$id}", $current);

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
                $types[] = \is_array($dt) ? $dt['name'] : $dt;
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
            ++$page;
        } while (100 === \count($result->results));

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
        if (null === $property) {
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

        if (null !== $namespaceUri) {
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
        if ($vocabularyId <= 0) {
            throw new OmekaApiException(\sprintf('Invalid vocabulary id for property term: %s', $term));
        }

        $prefix = null;
        $parts = \explode(':', $term, 2);
        if (2 === \count($parts) && '' !== $parts[0]) {
            $prefix = $parts[0];
        }

        $basePayload = [
            'o:term' => $term,
            'o:label' => $label,
            'o:local_name' => $localName,
        ];

        if (null !== $comment) {
            $basePayload['o:comment'] = $comment;
        }

        $payloadVariants = [];
        $payloadBases = [$basePayload, array_diff_key($basePayload, ['o:term' => true])];

        foreach ($payloadBases as $payloadBase) {
            $payloadVariants[] = $payloadBase + ['o:vocabulary' => ['o:id' => $vocabularyId]];
            $payloadVariants[] = $payloadBase + ['o:vocabulary' => $vocabularyId];
        }

        if (null !== $prefix) {
            foreach ($payloadBases as $payloadBase) {
                $payloadVariants[] = $payloadBase + [
                    'o:vocabulary' => ['o:id' => $vocabularyId, 'o:prefix' => $prefix],
                ];
                $payloadVariants[] = $payloadBase + [
                    'o:vocabulary' => ['@id' => $this->apiUrl.'/vocabularies/'.$vocabularyId],
                ];
            }
        }

        $vocabularyResource = $this->get("vocabularies/{$vocabularyId}");
        if (\is_array($vocabularyResource)) {
            foreach ($payloadBases as $payloadBase) {
                $payloadVariants[] = $payloadBase + ['o:vocabulary' => $vocabularyResource];
            }
        }

        $lastException = null;
        foreach ($payloadVariants as $payload) {
            try {
                return $this->post('properties', $payload);
            } catch (OmekaApiException $exception) {
                $lastException = $exception;
                if (!\str_contains($exception->getMessage(), 'o:vocabulary')) {
                    throw $exception;
                }
            }
        }

        if (null !== $lastException) {
            $payloadDump = \json_encode($payloadVariants[\array_key_last($payloadVariants)]) ?: 'unencodable';
            throw new OmekaApiException(\sprintf('Property create failed after retries. Payload: %s', $payloadDump), previous: $lastException);
        }

        throw new OmekaApiException('Property create failed without a response.');
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

        if (null !== $theme) {
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
        if (0 === \count($result->results)) {
            return [];
        }

        $vocab = $result->results[0];
        $terms = $vocab['o:terms'] ?? '';

        if (\is_string($terms)) {
            return \array_filter(\array_map('trim', \explode("\n", $terms)));
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

        if (null !== $isPublic) {
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

        $params = \array_filter([
            'property' => [
                [
                    'property' => $propertyId,
                    'type' => $type,
                    'text' => $value,
                ],
            ],
            'page' => $page,
        ], fn ($v) => null !== $v);

        return $this->search('item_sets', $params);
    }

    public function getMedia(int $page = 1, int $perPage = 25, ?int $itemId = null): SearchResult
    {
        return $this->search('media', \array_filter([
            'page' => $page,
            'per_page' => $perPage,
            'item_id' => $itemId,
        ], fn ($v) => null !== $v));
    }

    public function filterMediaByProperty(
        string $property,
        string $value,
        string $type = 'eq',
        int $page = 1,
    ): SearchResult {
        $propertyId = $this->getPropertyId($property);

        $params = \array_filter([
            'property' => [
                [
                    'property' => $propertyId,
                    'type' => $type,
                    'text' => $value,
                ],
            ],
            'page' => $page,
        ], fn ($v) => null !== $v);

        return $this->search('media', $params);
    }

    // ========== PAYLOAD BUILDING ==========

    public function buildPayload(array $properties, ?int $templateId = null): array
    {
        $templateProperties = null !== $templateId
            ? $this->getTemplateProperties($templateId)
            : [];

        $payload = [];

        foreach ($properties as $term => $values) {
            // Normalize to array
            if (!\is_array($values) || (isset($values['value']) || isset($values['@value']))) {
                $values = [$values];
            }

            // Validate against template if provided
            if (null !== $templateId && !isset($templateProperties[$term])) {
                \trigger_error("Term '{$term}' not in template {$templateId}", E_USER_WARNING);
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
            'query' => \array_merge($this->authQuery(), $query),
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

        try {
            return $response->toArray();
        } catch (ClientExceptionInterface $exception) {
            $details = $this->formatErrorDetails($exception->getResponse()->getContent(false));
            throw new OmekaApiException(\sprintf('Omeka API POST %s failed: %s', $endpoint, $details), previous: $exception);
        }
    }

    private function postWithMedia(string $endpoint, array $payload, array $mediaFiles): array
    {
        $this->requireAuth();

        $formFields = [
            'data' => \json_encode($payload),
        ];

        foreach ($mediaFiles as $index => $file) {
            $path = \is_array($file) ? $file['path'] : $file;
            $filename = \is_array($file) ? ($file['title'] ?? \basename($path)) : \basename($path);

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

    private function formatErrorDetails(?string $content): string
    {
        if (!\is_string($content) || '' === \trim($content)) {
            return 'empty response body';
        }

        $decoded = \json_decode($content, true);
        if (!\is_array($decoded)) {
            return $content;
        }

        if (isset($decoded['errors'])) {
            return \json_encode($decoded['errors']) ?: $content;
        }

        if (isset($decoded['message']) && \is_string($decoded['message'])) {
            return $decoded['message'];
        }

        return $content;
    }

    private function authQuery(): array
    {
        if (null === $this->keyIdentity || null === $this->keyCredential) {
            return [];
        }

        return [
            'key_identity' => $this->keyIdentity,
            'key_credential' => $this->keyCredential,
        ];
    }

    private function requireAuth(): void
    {
        if (null === $this->keyIdentity || null === $this->keyCredential) {
            $base = $this->apiUrl;
            if (\str_ends_with($base, '/api')) {
                $base = \substr($base, 0, -4);
            }

            $adminUrl = $base.'/admin/user/1/edit#edit-keys';

            throw new OmekaApiException('Authentication required. Configure key_identity and key_credential for the active Omeka client. Create an API key in the Omeka UI: '.$adminUrl);
        }
    }

    private function formatPropertyValues(array $values, int $propertyId, ?array $templateDef = null): array
    {
        $formatted = [];

        foreach ($values as $value) {
            // Simple string value
            if (\is_string($value) || \is_int($value)) {
                $value = ['value' => (string) $value];
            }

            if (!\is_array($value)) {
                continue;
            }

            // Determine type
            $type = $value['type'] ?? $templateDef['types'][0] ?? 'literal';

            // Validate type against template
            if (null !== $templateDef && !\in_array($type, $templateDef['types'], true)) {
                \trigger_error("Type '{$type}' not allowed for this property", E_USER_WARNING);
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

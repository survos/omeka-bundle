<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Command;

use RuntimeException;
use Survos\OmekaBundle\Client\OmekaClient;
use Survos\OmekaBundle\Client\OmekaClientRegistry;
use Survos\OmekaBundle\Exception\OmekaApiException;
use Survos\OmekaBundle\Model\Item;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use function array_filter;
use function array_key_exists;
use function array_values;
use function array_keys;
use function count;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function in_array;
use function sprintf;
use function str_contains;
use function str_starts_with;

#[AsCommand('omeka:sync', 'Sync items from one Omeka API to another')]
final class OmekaSyncCommand
{
    public function __construct(private OmekaClientRegistry $clients)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Source client name')]
        string $source,
        #[Argument('Target client name')]
        string $target,
        #[Option('Sync vocabularies')]
        bool $withVocabularies = false,
        #[Option('Sync properties')]
        bool $withProperties = false,
        #[Option('Sync resource templates')]
        bool $withTemplates = false,
        #[Option('Sync item sets')]
        bool $withItemSets = false,
        #[Option('Sync sites')]
        bool $withSites = false,
        #[Option('Sync site items (site-item assignments)')]
        bool $withSiteItems = false,
        #[Option('Sync site pages and navigation')]
        bool $withSitePages = false,
        #[Option('Sync media')]
        bool $withMedia = false,
        #[Option('Sync items')]
        bool $withItems = false,
        #[Option('Max items to sync (null = all)')]
        ?int $limit = null,
        #[Option('Start page')]
        int $page = 1,
        #[Option('Items per page')]
        int $perPage = 25,
    ): int {
        $sourceClient = $this->clients->get($source);
        $targetClient = $this->clients->get($target);

        $explicit = $withVocabularies || $withProperties || $withTemplates || $withItemSets || $withSites || $withSiteItems || $withSitePages || $withMedia || $withItems;
        if (!$explicit) {
            $withVocabularies = true;
            $withProperties = true;
            $withTemplates = true;
            $withItemSets = true;
            $withSites = true;
            $withSiteItems = true;
            $withSitePages = true;
            $withMedia = true;
            $withItems = true;
        }

        if ($withVocabularies || $withProperties || $withTemplates) {
            $this->syncSchema($io, $sourceClient, $targetClient, $withVocabularies, $withProperties, $withTemplates);
        }

        $allowedTerms = $this->propertyTerms($targetClient);

        $siteMap = [];
        if ($withSites) {
            $siteMap = $this->syncSites($sourceClient, $targetClient);
            $io->writeln(sprintf('Sites synced: %d', count($siteMap)));
        }

        $itemSetMap = [];
        if ($withItemSets) {
            $itemSetMap = $this->syncItemSets($io, $sourceClient, $targetClient, $source, $allowedTerms);
        }

        if (!$withItems) {
            return Command::SUCCESS;
        }

        $io->note('Syncing literal/URI metadata only (media and site attachments handled separately when enabled).');

        if (!$this->hasIdentifierProperty($targetClient)) {
            throw new RuntimeException('Missing dcterms:identifier on target. Sync properties first.');
        }

        $created = 0;
        $errors = 0;
        $processed = 0;
        $itemIdMap = [];

        while (true) {
            $result = $sourceClient->getItems(page: $page, perPage: $perPage, sortBy: 'id', sortOrder: 'asc');

            if ($result->results === []) {
                break;
            }

            foreach ($result->results as $row) {
                $processed++;
                $item = Item::fromArray($row);
                $properties = $this->sanitizeProperties($item->properties, $allowedTerms);
                $identifier = $this->sourceIdentifier('item', $source, $item->id);
                $properties = $this->ensureIdentifier($properties, $identifier);

                if ($properties === []) {
                    continue;
                }

                $existing = $targetClient->filterItemsByProperty('dcterms:identifier', $identifier);
                if ($existing->results !== []) {
                    $targetId = $existing->results[0]['o:id'] ?? null;
                    if (is_int($targetId)) {
                        $itemIdMap[$item->id] = $targetId;
                    }
                    if ($limit !== null && $processed >= $limit) {
                        break 2;
                    }
                    continue;
                }

                $itemSetIds = $this->mapItemSetIds($item->itemSetIds, $itemSetMap, $withItemSets);

                try {
                    $createdItem = $targetClient->createItem(
                        $properties,
                        isPublic: $item->isPublic,
                        itemSetIds: $itemSetIds,
                    );
                    $itemIdMap[$item->id] = $createdItem->id;
                    $created++;
                } catch (OmekaApiException $exception) {
                    if (str_contains($exception->getMessage(), 'Authentication required')) {
                        throw $exception;
                    }

                    $errors++;
                    $io->error(sprintf('Failed syncing item %d: %s', $item->id, $exception->getMessage()));
                }

                if ($limit !== null && $processed >= $limit) {
                    break 2;
                }
            }

            $page++;
        }

        if ($created === 0 && $errors === 0) {
            throw new RuntimeException('No items were synced.');
        }

        $io->success(sprintf('Synced %d items (%d errors).', $created, $errors));

        if ($withMedia) {
            $mediaCount = $this->syncMedia($io, $sourceClient, $targetClient, $source, $itemIdMap, $allowedTerms);
            $io->writeln(sprintf('Media synced: %d', $mediaCount));
        }

        if ($withSiteItems) {
            $siteItemCount = $this->syncSiteItems($io, $sourceClient, $targetClient, $siteMap, $source, $itemIdMap);
            $io->writeln(sprintf('Site items synced: %d', $siteItemCount));
        }

        if ($withSitePages) {
            $pageCount = $this->syncSitePages(
                $io,
                $sourceClient,
                $targetClient,
                $siteMap,
                $source,
                $itemIdMap,
                $itemSetMap,
            );
            $io->writeln(sprintf('Site pages synced: %d', $pageCount));
        }

        return Command::SUCCESS;
    }

    private function syncSchema(
        SymfonyStyle $io,
        OmekaClient $sourceClient,
        OmekaClient $targetClient,
        bool $withVocabularies,
        bool $withProperties,
        bool $withTemplates,
    ): void {
        if ($withVocabularies) {
            $created = $this->syncVocabularies($sourceClient, $targetClient);
            $io->writeln(sprintf('Vocabularies synced: %d', $created));
        }

        if ($withProperties) {
            $created = $this->syncProperties($sourceClient, $targetClient);
            $io->writeln(sprintf('Properties synced: %d', $created));
        }

        if ($withTemplates) {
            $created = $this->syncTemplates($sourceClient, $targetClient);
            $io->writeln(sprintf('Templates synced: %d', $created));
        }
    }

    private function syncVocabularies(OmekaClient $sourceClient, OmekaClient $targetClient): int
    {
        $source = $sourceClient->getVocabularies();
        $target = $targetClient->getVocabularies();

        $targetByPrefix = [];
        foreach ($target as $vocab) {
            $prefix = $vocab['o:prefix'] ?? null;
            if (is_string($prefix) && $prefix !== '') {
                $targetByPrefix[$prefix] = $vocab;
            }
        }

        $created = 0;
        foreach ($source as $vocab) {
            $prefix = $vocab['o:prefix'] ?? null;
            $label = $vocab['o:label'] ?? null;
            if (!is_string($prefix) || $prefix === '' || !is_string($label) || $label === '') {
                continue;
            }

            if (array_key_exists($prefix, $targetByPrefix)) {
                continue;
            }

            $namespace = $vocab['o:namespace_uri'] ?? null;
            $namespace = is_string($namespace) && $namespace !== '' ? $namespace : null;

            $targetClient->createVocabulary($prefix, $label, $namespace);
            $created++;
        }

        return $created;
    }

    private function syncProperties(OmekaClient $sourceClient, OmekaClient $targetClient): int
    {
        $sourceProps = $sourceClient->getProperties();
        $targetProps = $targetClient->getProperties();

        $targetTerms = [];
        foreach ($targetProps as $term => $property) {
            $targetTerms[$term] = true;
        }

        $targetVocabs = $this->vocabularyIdByPrefix($targetClient);
        $sourceVocabById = $this->vocabularyPrefixById($sourceClient);

        $created = 0;
        foreach ($sourceProps as $term => $property) {
            if (isset($targetTerms[$term])) {
                continue;
            }

            $sourceVocabId = $property->vocabularyId;
            $prefix = $sourceVocabById[$sourceVocabId] ?? null;
            if (!is_string($prefix) || $prefix === '') {
                throw new RuntimeException(sprintf('Property vocabulary missing for term "%s".', $property->term));
            }

            $targetVocabId = $targetVocabs[$prefix] ?? null;
            if (!is_int($targetVocabId)) {
                throw new RuntimeException(sprintf(
                    'Vocabulary "%s" missing on target for property "%s".',
                    $prefix,
                    $property->term,
                ));
            }

            $targetClient->createProperty(
                $property->term,
                $property->label,
                $property->localName,
                $property->comment,
                $targetVocabId,
            );
            $created++;
        }

        return $created;
    }

    private function vocabularyIdByPrefix(OmekaClient $client): array
    {
        $map = [];
        foreach ($client->getVocabularies() as $vocab) {
            $prefix = $vocab['o:prefix'] ?? null;
            $id = $vocab['o:id'] ?? null;
            if (is_string($prefix) && $prefix !== '' && is_int($id)) {
                $map[$prefix] = $id;
            }
        }

        return $map;
    }

    private function vocabularyPrefixById(OmekaClient $client): array
    {
        $map = [];
        foreach ($client->getVocabularies() as $vocab) {
            $prefix = $vocab['o:prefix'] ?? null;
            $id = $vocab['o:id'] ?? null;
            if (is_string($prefix) && $prefix !== '' && is_int($id)) {
                $map[$id] = $prefix;
            }
        }

        return $map;
    }

    private function syncTemplates(OmekaClient $sourceClient, OmekaClient $targetClient): int
    {
        $targetByLabel = [];
        foreach ($targetClient->getResourceTemplates() as $template) {
            $targetByLabel[$template->label] = true;
        }

        $targetProperties = $this->propertyIdByTerm($targetClient);
        $targetResourceClasses = $this->resourceClassIdByTerm($targetClient);

        $sourceProperties = $this->propertyTermById($sourceClient);
        $sourceResourceClasses = $this->resourceClassTermById($sourceClient);

        $created = 0;

        foreach ($sourceClient->getResourceTemplates() as $template) {
            if (isset($targetByLabel[$template->label])) {
                continue;
            }

            $resourceClassId = null;
            if ($template->resourceClassId !== null) {
                $term = $sourceResourceClasses[$template->resourceClassId] ?? null;
                if (!is_string($term) || $term === '') {
                    throw new RuntimeException(sprintf(
                        'Missing resource class term for template "%s".',
                        $template->label,
                    ));
                }
                $resourceClassId = $targetResourceClasses[$term] ?? null;
                if (!is_int($resourceClassId)) {
                    throw new RuntimeException(sprintf(
                        'Resource class "%s" missing on target for template "%s".',
                        $term,
                        $template->label,
                    ));
                }
            }

            $titlePropertyId = null;
            if ($template->titlePropertyId !== null) {
                $term = $sourceProperties[$template->titlePropertyId] ?? null;
                if (!is_string($term) || $term === '') {
                    throw new RuntimeException(sprintf(
                        'Missing title property term for template "%s".',
                        $template->label,
                    ));
                }
                $titlePropertyId = $targetProperties[$term] ?? null;
                if (!is_int($titlePropertyId)) {
                    throw new RuntimeException(sprintf(
                        'Title property "%s" missing on target for template "%s".',
                        $term,
                        $template->label,
                    ));
                }
            }

            $descriptionPropertyId = null;
            if ($template->descriptionPropertyId !== null) {
                $term = $sourceProperties[$template->descriptionPropertyId] ?? null;
                if (!is_string($term) || $term === '') {
                    throw new RuntimeException(sprintf(
                        'Missing description property term for template "%s".',
                        $template->label,
                    ));
                }
                $descriptionPropertyId = $targetProperties[$term] ?? null;
                if (!is_int($descriptionPropertyId)) {
                    throw new RuntimeException(sprintf(
                        'Description property "%s" missing on target for template "%s".',
                        $term,
                        $template->label,
                    ));
                }
            }

            $properties = $this->mapTemplateProperties(
                $template,
                $sourceProperties,
                $targetProperties,
            );

            $targetClient->createResourceTemplate(
                $template->label,
                $resourceClassId,
                $titlePropertyId,
                $descriptionPropertyId,
                $properties,
            );
            $created++;
        }

        return $created;
    }

    private function mapTemplateProperties(
        \Survos\OmekaBundle\Model\ResourceTemplate $template,
        array $sourcePropertyTerms,
        array $targetPropertyIds,
    ): array {
        $properties = [];

        foreach ($template->properties as $templateProperty) {
            $sourcePropertyId = $templateProperty['o:property']['o:id'] ?? null;
            $term = $templateProperty['o:property']['o:term'] ?? null;
            if (!is_string($term) || $term === '') {
                $term = is_int($sourcePropertyId) ? ($sourcePropertyTerms[$sourcePropertyId] ?? null) : null;
            }

            if (!is_string($term) || $term === '') {
                throw new RuntimeException(sprintf(
                    'Missing property term in template "%s".',
                    $template->label,
                ));
            }

            $targetPropertyId = $targetPropertyIds[$term] ?? null;
            if (!is_int($targetPropertyId)) {
                throw new RuntimeException(sprintf(
                    'Property "%s" missing on target for template "%s".',
                    $term,
                    $template->label,
                ));
            }

            $dataTypes = [];
            foreach ($templateProperty['o:data_type'] ?? [] as $dataType) {
                $name = is_array($dataType) ? ($dataType['name'] ?? null) : $dataType;
                if (is_string($name) && $name !== '') {
                    $dataTypes[] = $name;
                }
            }

            $dataTypes = array_values(array_filter($dataTypes));

            $properties[] = [
                'property_id' => $targetPropertyId,
                'required' => (bool) ($templateProperty['o:is_required'] ?? false),
                'private' => (bool) ($templateProperty['o:is_private'] ?? false),
                'data_types' => $dataTypes,
            ];
        }

        return $properties;
    }

    private function propertyIdByTerm(OmekaClient $client): array
    {
        $map = [];
        foreach ($client->getProperties() as $property) {
            $map[$property->term] = $property->id;
        }

        return $map;
    }

    private function propertyTermById(OmekaClient $client): array
    {
        $map = [];
        foreach ($client->getProperties() as $property) {
            $map[$property->id] = $property->term;
        }

        return $map;
    }

    private function resourceClassIdByTerm(OmekaClient $client): array
    {
        $map = [];
        foreach ($client->getResourceClasses() as $class) {
            $term = $class['o:term'] ?? null;
            $id = $class['o:id'] ?? null;
            if (is_string($term) && $term !== '' && is_int($id)) {
                $map[$term] = $id;
            }
        }

        return $map;
    }

    private function resourceClassTermById(OmekaClient $client): array
    {
        $map = [];
        foreach ($client->getResourceClasses() as $class) {
            $term = $class['o:term'] ?? null;
            $id = $class['o:id'] ?? null;
            if (is_string($term) && $term !== '' && is_int($id)) {
                $map[$id] = $term;
            }
        }

        return $map;
    }

    private function sanitizeProperties(array $properties, array $allowedTerms): array
    {
        $sanitized = [];

        foreach ($properties as $term => $values) {
            if (str_starts_with($term, 'o:') || str_starts_with($term, '@')) {
                continue;
            }
            if ($allowedTerms !== [] && !isset($allowedTerms[$term])) {
                continue;
            }

            if (!is_array($values)) {
                $sanitized[$term] = $values;
                continue;
            }

            $filtered = [];
            foreach ($values as $value) {
                if (!is_array($value)) {
                    $filtered[] = $value;
                    continue;
                }

                $type = $value['type'] ?? 'literal';
                if (!is_string($type) || str_starts_with($type, 'resource')) {
                    continue;
                }

                $filtered[] = $value;
            }

            if (count($filtered) > 0) {
                $sanitized[$term] = array_values($filtered);
            }
        }

        return $sanitized;
    }

    private function propertyTerms(OmekaClient $client): array
    {
        $terms = [];

        foreach ($client->getProperties() as $property) {
            $terms[$property->term] = true;
        }

        return $terms;
    }

    private function syncItemSets(
        SymfonyStyle $io,
        OmekaClient $sourceClient,
        OmekaClient $targetClient,
        string $sourceName,
        array $allowedTerms,
    ): array {
        $map = [];
        $page = 1;
        $created = 0;

        if (!$this->hasIdentifierProperty($targetClient)) {
            throw new RuntimeException('Missing dcterms:identifier on target. Sync properties first.');
        }

        while (true) {
            $result = $sourceClient->getItemSets(page: $page, perPage: 50);
            if ($result->results === []) {
                break;
            }

            foreach ($result->results as $row) {
                $sourceId = $row['o:id'] ?? null;
                if (!is_int($sourceId)) {
                    continue;
                }

                $identifier = $this->sourceIdentifier('item_set', $sourceName, $sourceId);
                $existing = $targetClient->filterItemSetsByProperty('dcterms:identifier', $identifier);
                if ($existing->results !== []) {
                    $targetId = $existing->results[0]['o:id'] ?? null;
                    if (is_int($targetId)) {
                        $map[$sourceId] = $targetId;
                    }
                    continue;
                }

                $properties = $this->extractProperties($row);
                $properties = $this->sanitizeProperties($properties, $allowedTerms);
                $properties = $this->ensureIdentifier($properties, $identifier);

                $isPublic = $row['o:is_public'] ?? null;
                $isPublic = is_bool($isPublic) ? $isPublic : null;

                $createdSet = $targetClient->createItemSet($properties, $isPublic);
                $targetId = $createdSet['o:id'] ?? null;
                if (is_int($targetId)) {
                    $map[$sourceId] = $targetId;
                }
                $created++;
            }

            $page++;
        }

        $io->writeln(sprintf('Item sets synced: %d', $created));

        return $map;
    }

    private function syncSites(OmekaClient $sourceClient, OmekaClient $targetClient): array
    {
        $targetBySlug = [];
        foreach ($targetClient->getSites() as $site) {
            $slug = $site['o:slug'] ?? null;
            $id = $site['o:id'] ?? null;
            if (is_string($slug) && $slug !== '' && is_int($id)) {
                $targetBySlug[$slug] = $id;
            }
        }

        $map = [];
        foreach ($sourceClient->getSites() as $site) {
            $slug = $site['o:slug'] ?? null;
            $title = $site['o:title'] ?? null;
            $sourceId = $site['o:id'] ?? null;
            if (!is_string($slug) || $slug === '' || !is_string($title) || $title === '' || !is_int($sourceId)) {
                continue;
            }

            if (isset($targetBySlug[$slug])) {
                $map[$sourceId] = $targetBySlug[$slug];
                continue;
            }

            $theme = $site['o:theme'] ?? null;
            $theme = is_string($theme) && $theme !== '' ? $theme : null;
            $isPublic = $site['o:is_public'] ?? true;
            $isPublic = is_bool($isPublic) ? $isPublic : true;

            $created = $targetClient->createSite($slug, $title, $theme, $isPublic);
            $targetId = $created['o:id'] ?? null;
            if (is_int($targetId)) {
                $map[$sourceId] = $targetId;
            }
        }

        return $map;
    }

    private function syncSiteItems(
        SymfonyStyle $io,
        OmekaClient $sourceClient,
        OmekaClient $targetClient,
        array $siteMap,
        string $sourceName,
        array $itemIdMap,
    ): int {
        if ($siteMap === []) {
            $io->warning('No sites mapped; skipping site items.');
            return 0;
        }

        $count = 0;

        foreach ($siteMap as $sourceSiteId => $targetSiteId) {
            $page = 1;
            while (true) {
                $result = $sourceClient->getItems(siteId: (int) $sourceSiteId, page: $page, perPage: 50);
                if ($result->results === []) {
                    break;
                }

                foreach ($result->results as $row) {
                    $sourceItemId = $row['o:id'] ?? null;
                    if (!is_int($sourceItemId)) {
                        continue;
                    }

                    $targetItemId = $itemIdMap[$sourceItemId] ?? $this->findTargetItemId(
                        $targetClient,
                        $sourceName,
                        $sourceItemId,
                    );

                    if (!is_int($targetItemId)) {
                        continue;
                    }

                    $targetClient->updateItemSites($targetItemId, [(int) $targetSiteId]);
                    $count++;
                }

                $page++;
            }
        }

        return $count;
    }

    private function syncSitePages(
        SymfonyStyle $io,
        OmekaClient $sourceClient,
        OmekaClient $targetClient,
        array $siteMap,
        string $sourceName,
        array $itemIdMap,
        array $itemSetMap,
    ): int {
        if ($siteMap === []) {
            $io->warning('No sites mapped; skipping site pages.');
            return 0;
        }

        $pageMap = [];
        $created = 0;

        foreach ($siteMap as $sourceSiteId => $targetSiteId) {
            $targetPages = $this->sitePagesBySlug($targetClient, (int) $targetSiteId);
            $page = 1;

            while (true) {
                $result = $sourceClient->getSitePages((int) $sourceSiteId, $page, 100);
                if ($result->results === []) {
                    break;
                }

                foreach ($result->results as $pageData) {
                    $sourcePageId = $pageData['o:id'] ?? null;
                    $slug = $pageData['o:slug'] ?? null;
                    if (!is_int($sourcePageId) || !is_string($slug) || $slug === '') {
                        continue;
                    }

                    if (isset($targetPages[$slug])) {
                        $pageMap[$sourcePageId] = $targetPages[$slug];
                        continue;
                    }

                    $payload = $this->buildSitePagePayload(
                        $pageData,
                        (int) $targetSiteId,
                        $sourceName,
                        $itemIdMap,
                        $itemSetMap,
                    );

                    $createdPage = $targetClient->createSitePage((int) $targetSiteId, $payload);
                    $targetPageId = $createdPage['o:id'] ?? null;
                    if (is_int($targetPageId)) {
                        $pageMap[$sourcePageId] = $targetPageId;
                    }

                    $created++;
                }

                $page++;
            }

            $this->syncSiteNavigation($sourceClient, $targetClient, (int) $sourceSiteId, (int) $targetSiteId, $pageMap);
        }

        return $created;
    }

    private function syncSiteNavigation(
        OmekaClient $sourceClient,
        OmekaClient $targetClient,
        int $sourceSiteId,
        int $targetSiteId,
        array $pageMap,
    ): void {
        $sourceSite = $sourceClient->getSite($sourceSiteId);
        $navigation = $sourceSite['o:navigation'] ?? null;
        if (!is_array($navigation)) {
            return;
        }

        $mapped = $this->mapNavigation($navigation, $pageMap);

        $targetSite = $targetClient->getSite($targetSiteId);
        $targetSite['o:navigation'] = $mapped;
        $targetClient->updateSite($targetSiteId, $targetSite);
    }

    private function mapNavigation(array $navigation, array $pageMap): array
    {
        $mapped = [];

        foreach ($navigation as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (($entry['type'] ?? null) === 'page' && isset($entry['data']['id'])) {
                $sourcePageId = $entry['data']['id'];
                if (is_int($sourcePageId) && isset($pageMap[$sourcePageId])) {
                    $entry['data']['id'] = $pageMap[$sourcePageId];
                }
            }

            if (isset($entry['children']) && is_array($entry['children'])) {
                $entry['children'] = $this->mapNavigation($entry['children'], $pageMap);
            }

            $mapped[] = $entry;
        }

        return $mapped;
    }

    private function sitePagesBySlug(OmekaClient $client, int $siteId): array
    {
        $map = [];
        $page = 1;
        while (true) {
            $result = $client->getSitePages($siteId, $page, 100);
            if ($result->results === []) {
                break;
            }

            foreach ($result->results as $pageData) {
                $slug = $pageData['o:slug'] ?? null;
                $id = $pageData['o:id'] ?? null;
                if (is_string($slug) && $slug !== '' && is_int($id)) {
                    $map[$slug] = $id;
                }
            }

            $page++;
        }

        return $map;
    }

    private function buildSitePagePayload(
        array $pageData,
        int $targetSiteId,
        string $sourceName,
        array $itemIdMap,
        array $itemSetMap,
    ): array {
        $payload = [];
        $keep = ['o:slug', 'o:title', 'o:is_public', 'o:layout', 'o:layout_data', 'o:blocks', 'o:block'];

        foreach ($keep as $key) {
            if (array_key_exists($key, $pageData)) {
                $payload[$key] = $pageData[$key];
            }
        }

        $payload['o:site'] = ['o:id' => $targetSiteId];

        if (isset($payload['o:blocks']) && is_array($payload['o:blocks'])) {
            $payload['o:blocks'] = $this->mapBlocks($payload['o:blocks'], $sourceName, $itemIdMap, $itemSetMap);
        }
        if (isset($payload['o:block']) && is_array($payload['o:block'])) {
            $payload['o:block'] = $this->mapBlocks($payload['o:block'], $sourceName, $itemIdMap, $itemSetMap);
        }

        return $payload;
    }

    private function mapBlocks(
        array $blocks,
        string $sourceName,
        array $itemIdMap,
        array $itemSetMap,
    ): array {
        $mapped = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            if (isset($block['o:data']) && is_array($block['o:data'])) {
                $block['o:data'] = $this->mapBlockData($block['o:data'], $sourceName, $itemIdMap, $itemSetMap);
            }

            $mapped[] = $block;
        }

        return $mapped;
    }

    private function mapBlockData(
        array $data,
        string $sourceName,
        array $itemIdMap,
        array $itemSetMap,
    ): array {
        $itemKeys = ['item', 'items', 'item_id', 'item_ids'];
        $itemSetKeys = ['item_set', 'item_sets', 'item_set_id', 'item_set_ids'];

        foreach ($data as $key => $value) {
            if (in_array($key, $itemKeys, true)) {
                $data[$key] = $this->mapIds($value, $sourceName, $itemIdMap, 'item');
                continue;
            }

            if (in_array($key, $itemSetKeys, true)) {
                $data[$key] = $this->mapIds($value, $sourceName, $itemSetMap, 'item_set');
            }
        }

        return $data;
    }

    private function mapIds(mixed $value, string $sourceName, array $map, string $type): mixed
    {
        if (is_int($value)) {
            return $map[$value] ?? $value;
        }

        if (is_array($value)) {
            $mapped = [];
            foreach ($value as $entry) {
                if (is_int($entry)) {
                    $mapped[] = $map[$entry] ?? $entry;
                } else {
                    $mapped[] = $entry;
                }
            }

            return $mapped;
        }

        return $value;
    }

    private function syncMedia(
        SymfonyStyle $io,
        OmekaClient $sourceClient,
        OmekaClient $targetClient,
        string $sourceName,
        array $itemIdMap,
        array $allowedTerms,
    ): int {
        $count = 0;
        $page = 1;

        if (!$this->hasIdentifierProperty($targetClient)) {
            throw new RuntimeException('Missing dcterms:identifier on target. Sync properties first.');
        }

        while (true) {
            $mediaResult = $sourceClient->getMedia(page: $page, perPage: 25);
            if ($mediaResult->results === []) {
                break;
            }

            foreach ($mediaResult->results as $media) {
                $sourceMediaId = $media['o:id'] ?? null;
                $sourceItemId = $media['o:item']['o:id'] ?? null;
                if (!is_int($sourceMediaId) || !is_int($sourceItemId)) {
                    continue;
                }

                $targetItemId = $itemIdMap[$sourceItemId] ?? $this->findTargetItemId(
                    $targetClient,
                    $sourceName,
                    $sourceItemId,
                );

                if (!is_int($targetItemId)) {
                    continue;
                }

                $identifier = $this->sourceIdentifier('media', $sourceName, $sourceMediaId);
                $existing = $targetClient->filterMediaByProperty('dcterms:identifier', $identifier);
                if ($existing->results !== []) {
                    continue;
                }

                $url = $this->mediaUrl($media);
                if ($url === null) {
                    $io->warning(sprintf('Skipping media %d: no URL found.', $sourceMediaId));
                    continue;
                }

                $properties = $this->extractProperties($media);
                $properties = $this->sanitizeProperties($properties, $allowedTerms);
                $properties = $this->ensureIdentifier($properties, $identifier);

                $title = null;
                if (isset($media['o:title']) && is_string($media['o:title'])) {
                    $title = $media['o:title'];
                }

                $targetClient->createMediaFromUrl($targetItemId, $url, $properties, $title);
                $count++;
            }

            $page++;
        }

        return $count;
    }

    private function mediaUrl(array $media): ?string
    {
        $url = $media['o:original_url'] ?? null;
        if (is_string($url) && str_starts_with($url, 'http')) {
            return $url;
        }

        $source = $media['o:source'] ?? null;
        if (is_string($source) && str_starts_with($source, 'http')) {
            return $source;
        }

        return null;
    }

    private function findTargetItemId(
        OmekaClient $client,
        string $sourceName,
        int $sourceItemId,
    ): ?int {
        $identifier = $this->sourceIdentifier('item', $sourceName, $sourceItemId);
        $existing = $client->filterItemsByProperty('dcterms:identifier', $identifier);
        $targetId = $existing->results[0]['o:id'] ?? null;

        return is_int($targetId) ? $targetId : null;
    }

    private function mapItemSetIds(array $sourceIds, array $itemSetMap, bool $withItemSets): array
    {
        if ($sourceIds === []) {
            return [];
        }

        $mapped = [];
        foreach ($sourceIds as $sourceId) {
            if (!is_int($sourceId)) {
                continue;
            }

            if (!isset($itemSetMap[$sourceId])) {
                if ($withItemSets) {
                    throw new RuntimeException(sprintf('Missing item set mapping for source id %d.', $sourceId));
                }
                continue;
            }

            $mapped[] = $itemSetMap[$sourceId];
        }

        return $mapped;
    }

    private function hasIdentifierProperty(OmekaClient $client): bool
    {
        foreach ($client->getProperties() as $property) {
            if ($property->term === 'dcterms:identifier') {
                return true;
            }
        }

        return false;
    }

    private function sourceIdentifier(string $type, string $sourceName, int $id): string
    {
        return sprintf('omeka:%s:%s:%d', $sourceName, $type, $id);
    }

    private function ensureIdentifier(array $properties, string $identifier): array
    {
        $value = ['value' => $identifier, 'type' => 'literal'];

        if (!isset($properties['dcterms:identifier'])) {
            $properties['dcterms:identifier'] = [$value];
            return $properties;
        }

        $existing = $properties['dcterms:identifier'];
        if (is_string($existing)) {
            $properties['dcterms:identifier'] = [$existing, $value];
            return $properties;
        }

        if (is_array($existing)) {
            $existing[] = $value;
            $properties['dcterms:identifier'] = $existing;
        }

        return $properties;
    }

    private function extractProperties(array $data): array
    {
        $properties = [];
        $skip = [
            '@context', '@id', '@type',
            'o:id', 'o:is_public', 'o:owner',
            'o:resource_class', 'o:resource_template',
            'o:thumbnail', 'o:title', 'o:created', 'o:modified',
            'o:media', 'o:item_set', 'o:site', 'o:items', 'thumbnail_display_urls',
        ];

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

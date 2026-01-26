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
use function array_key_exists;
use function array_values;
use function count;
use function is_array;
use function is_int;
use function is_string;
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

        $explicit = $withVocabularies || $withProperties || $withTemplates || $withItems;
        if (!$explicit) {
            $withItems = true;
        }

        if ($withVocabularies || $withProperties || $withTemplates) {
            $this->syncSchema($io, $sourceClient, $targetClient, $withVocabularies, $withProperties, $withTemplates);
        }

        if (!$withItems) {
            return Command::SUCCESS;
        }

        $io->note('Syncing literal/URI metadata only (no media, item sets, templates, or resource links).');

        $allowedTerms = $this->propertyTerms($targetClient);

        $created = 0;
        $errors = 0;
        $processed = 0;

        while (true) {
            $result = $sourceClient->getItems(page: $page, perPage: $perPage, sortBy: 'id', sortOrder: 'asc');

            if ($result->results === []) {
                break;
            }

            foreach ($result->results as $row) {
                $processed++;
                $item = Item::fromArray($row);
                $properties = $this->sanitizeProperties($item->properties, $allowedTerms);

                if ($properties === []) {
                    continue;
                }

                try {
                    $targetClient->createItem(
                        $properties,
                        isPublic: $item->isPublic,
                    );
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
            $io->warning('Template sync not implemented yet.');
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
                continue;
            }

            $targetVocabId = $targetVocabs[$prefix] ?? null;
            if (!is_int($targetVocabId)) {
                continue;
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
}

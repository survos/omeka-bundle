<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Command;

use DirectoryIterator;
use JsonException;
use RuntimeException;
use Survos\OmekaBundle\Client\OmekaClient;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;
use function array_filter;
use function array_is_list;
use function array_key_exists;
use function array_merge;
use function array_values;
use function class_exists;
use function explode;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_dir;
use function is_int;
use function is_string;
use function json_decode;
use function ltrim;
use function sprintf;
use function str_starts_with;
use function strtolower;

#[AsCommand('omeka:resources:create', 'Create or update Omeka resource templates')]
final class OmekaCreateResourcesCommand
{
    public function __construct(
        private OmekaClient $omeka,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Config directory path')]
        ?string $configDir = null,
        #[Option('Dry run (no API writes)')]
        bool $dryRun = false,
        #[Option('Skip existing templates instead of updating')]
        bool $skipExisting = false,
    ): int {
        $configDir ??= 'config/omeka';
        $configDir = $this->resolvePath($configDir);

        if (!is_dir($configDir)) {
            throw new RuntimeException(sprintf('Config directory not found: %s', $configDir));
        }

        $config = $this->loadConfig($configDir);
        $templates = $config['templates'];
        if ($templates === []) {
            $io->warning(sprintf('No templates found in %s.', $configDir));
            return Command::SUCCESS;
        }

        $propertyIds = $this->propertyIdsByTerm();
        $resourceClassIds = $this->resourceClassIdsByTerm();
        $vocabularyIds = $this->vocabularyIdsByPrefix();
        $vocabularyDefinitions = $this->indexVocabularyDefinitions($config['vocabularies']);
        $propertyDefinitions = $this->indexPropertyDefinitions($config['properties']);

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($templates as $template) {
            if (!is_array($template)) {
                throw new RuntimeException('Template entry must be an object.');
            }

            $label = $this->requireString($template, 'label');
            $resourceClassTerm = $this->optionalString($template, 'resource_class');
            $titlePropertyTerm = $this->optionalString($template, 'title_property') ?? 'dcterms:title';
            $descriptionPropertyTerm = $this->optionalString($template, 'description_property');

            $resourceClassId = null;
            if ($resourceClassTerm !== null) {
                $resourceClassId = $resourceClassIds[$resourceClassTerm] ?? null;
                if (!is_int($resourceClassId)) {
                    throw new RuntimeException(sprintf('Unknown resource class term: %s', $resourceClassTerm));
                }
            }

            $titlePropertyId = $this->ensurePropertyId(
                $titlePropertyTerm,
                $propertyIds,
                $propertyDefinitions,
                $vocabularyIds,
                $vocabularyDefinitions,
                $io,
                $dryRun,
            );

            $descriptionPropertyId = null;
            if ($descriptionPropertyTerm !== null) {
                $descriptionPropertyId = $this->ensurePropertyId(
                    $descriptionPropertyTerm,
                    $propertyIds,
                    $propertyDefinitions,
                    $vocabularyIds,
                    $vocabularyDefinitions,
                    $io,
                    $dryRun,
                );
            }

            $properties = $this->buildTemplateProperties(
                $template,
                $propertyIds,
                $propertyDefinitions,
                $vocabularyIds,
                $vocabularyDefinitions,
                $label,
                $io,
                $dryRun,
            );
            $existing = $this->omeka->getResourceTemplateByLabel($label);

            if ($dryRun) {
                $io->text(sprintf('Would %s template "%s".', $existing ? 'update' : 'create', $label));
                continue;
            }

            if ($existing === null) {
                $this->omeka->createResourceTemplate(
                    $label,
                    $resourceClassId,
                    $titlePropertyId,
                    $descriptionPropertyId,
                    $properties,
                );
                $created++;
                $io->text(sprintf('Created "%s".', $label));
                continue;
            }

            if ($skipExisting) {
                $skipped++;
                $io->text(sprintf('Skipped "%s".', $label));
                continue;
            }

            $this->omeka->updateResourceTemplate(
                $existing->id,
                $label,
                $resourceClassId,
                $titlePropertyId,
                $descriptionPropertyId,
                $properties,
            );
            $updated++;
            $io->text(sprintf('Updated "%s".', $label));
        }

        $summary = sprintf(
            'Templates processed: %d created, %d updated, %d skipped.',
            $created,
            $updated,
            $skipped,
        );
        $io->success($summary);

        return Command::SUCCESS;
    }

    private function resolvePath(string $configDir): string
    {
        if (str_starts_with($configDir, '/')) {
            return $configDir;
        }

        return $this->projectDir . '/' . ltrim($configDir, '/');
    }

    /**
     * @return array{templates: array<int, array>, vocabularies: array<int, array>, properties: array<int, array>}
     */
    private function loadConfig(string $configDir): array
    {
        $templates = [];
        $vocabularies = [];
        $properties = [];

        foreach (new DirectoryIterator($configDir) as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $extension = strtolower($fileInfo->getExtension());
            if (!in_array($extension, ['json', 'yaml', 'yml'], true)) {
                continue;
            }

            $parsed = $this->parseTemplateFile($fileInfo->getPathname(), $extension);
            $templates = array_merge($templates, $parsed['templates']);
            $vocabularies = array_merge($vocabularies, $parsed['vocabularies']);
            $properties = array_merge($properties, $parsed['properties']);
        }

        return [
            'templates' => $templates,
            'vocabularies' => $vocabularies,
            'properties' => $properties,
        ];
    }

    /**
     * @return array{templates: array<int, array>, vocabularies: array<int, array>, properties: array<int, array>}
     */
    private function parseTemplateFile(string $path, string $extension): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException(sprintf('Failed reading template file: %s', $path));
        }

        if ($extension === 'json') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException(
                    sprintf('Invalid JSON in %s: %s', $path, $exception->getMessage()),
                    previous: $exception,
                );
            }
        } else {
            if (!class_exists(Yaml::class)) {
                throw new RuntimeException('symfony/yaml is required to parse YAML template files.');
            }
            $decoded = Yaml::parse($raw);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Template file must decode to an object or list: %s', $path));
        }

        $templates = $decoded['templates'] ?? $decoded;
        $vocabularies = $decoded['vocabularies'] ?? [];
        $properties = $decoded['properties'] ?? [];

        if (is_array($templates) && array_is_list($templates)) {
            $normalizedTemplates = $templates;
        } elseif (is_array($templates) && isset($templates['label'])) {
            $normalizedTemplates = [$templates];
        } else {
            throw new RuntimeException(sprintf('Template file must contain templates: %s', $path));
        }

        $normalizedVocabularies = $this->normalizeList($vocabularies, 'vocabularies', $path);
        $normalizedProperties = $this->normalizeList($properties, 'properties', $path);

        return [
            'templates' => $normalizedTemplates,
            'vocabularies' => $normalizedVocabularies,
            'properties' => $normalizedProperties,
        ];
    }

    private function normalizeList(mixed $value, string $label, string $path): array
    {
        if ($value === null || $value === []) {
            return [];
        }

        if (!is_array($value)) {
            throw new RuntimeException(sprintf('Invalid %s in %s.', $label, $path));
        }

        if (array_is_list($value)) {
            return $value;
        }

        return [$value];
    }

    private function propertyIdsByTerm(): array
    {
        $map = [];
        foreach ($this->omeka->getProperties() as $term => $property) {
            $map[$term] = $property->id;
        }

        return $map;
    }

    private function resourceClassIdsByTerm(): array
    {
        $map = [];
        foreach ($this->omeka->getResourceClasses() as $class) {
            $term = $class['o:term'] ?? null;
            $id = $class['o:id'] ?? null;
            if (is_string($term) && $term !== '' && is_int($id)) {
                $map[$term] = $id;
            }
        }

        return $map;
    }

    private function vocabularyIdsByPrefix(): array
    {
        $map = [];
        foreach ($this->omeka->getVocabularies() as $vocab) {
            $prefix = $vocab['o:prefix'] ?? null;
            $id = $vocab['o:id'] ?? null;
            if (is_string($prefix) && $prefix !== '' && is_int($id)) {
                $map[$prefix] = $id;
            }
        }

        return $map;
    }

    private function buildTemplateProperties(
        array $template,
        array &$propertyIds,
        array $propertyDefinitions,
        array &$vocabularyIds,
        array $vocabularyDefinitions,
        string $label,
        SymfonyStyle $io,
        bool $dryRun,
    ): array {
        $properties = $template['properties'] ?? null;
        if (!is_array($properties) || $properties === []) {
            throw new RuntimeException(sprintf('Template "%s" has no properties.', $label));
        }

        $normalized = [];
        foreach ($properties as $property) {
            if (!is_array($property)) {
                throw new RuntimeException(sprintf('Template "%s" has invalid property entry.', $label));
            }

            $term = $this->requireString($property, 'term');
            $propertyId = $this->ensurePropertyId(
                $term,
                $propertyIds,
                $propertyDefinitions,
                $vocabularyIds,
                $vocabularyDefinitions,
                $io,
                $dryRun,
            );

            $required = (bool) ($property['required'] ?? false);
            $private = (bool) ($property['private'] ?? false);

            $dataTypes = $property['data_types'] ?? null;
            if (is_string($dataTypes)) {
                $dataTypes = [$dataTypes];
            }
            if (!is_array($dataTypes)) {
                $dataTypes = ['literal'];
            }

            $dataTypes = array_values(array_filter(
                $dataTypes,
                static fn($value): bool => is_string($value) && $value !== '',
            ));
            if ($dataTypes === []) {
                $dataTypes = ['literal'];
            }

            $normalized[] = [
                'property_id' => $propertyId,
                'required' => $required,
                'private' => $private,
                'data_types' => $dataTypes,
            ];
        }

        return $normalized;
    }

    private function ensurePropertyId(
        string $term,
        array &$propertyIds,
        array $propertyDefinitions,
        array &$vocabularyIds,
        array $vocabularyDefinitions,
        SymfonyStyle $io,
        bool $dryRun,
    ): int {
        $propertyId = $propertyIds[$term] ?? null;
        if (is_int($propertyId)) {
            return $propertyId;
        }

        if (!array_key_exists($term, $propertyDefinitions)) {
            throw new RuntimeException(sprintf('Unknown property term: %s', $term));
        }

        $definition = $propertyDefinitions[$term];
        $label = $this->requireString($definition, 'label');
        $comment = $this->optionalString($definition, 'comment');
        $localName = $this->optionalString($definition, 'local_name');

        $parts = explode(':', $term, 2);
        if ($localName === null) {
            $localName = $parts[1] ?? null;
        }
        if ($localName === null || $localName === '') {
            throw new RuntimeException(sprintf('Missing local_name for property: %s', $term));
        }

        $prefix = $parts[0] ?? null;
        if ($prefix === null || $prefix === '') {
            throw new RuntimeException(sprintf('Invalid property term: %s', $term));
        }

        $vocabularyId = $this->ensureVocabularyId(
            $prefix,
            $vocabularyIds,
            $vocabularyDefinitions,
            $io,
            $dryRun,
        );

        if ($dryRun) {
            $io->text(sprintf('Would create property "%s".', $term));
            return 0;
        }

        $this->omeka->createProperty(
            $term,
            $label,
            $localName,
            $comment,
            $vocabularyId,
        );

        $propertyIds = $this->propertyIdsByTerm();
        $propertyId = $propertyIds[$term] ?? null;
        if (!is_int($propertyId)) {
            throw new RuntimeException(sprintf('Failed creating property term: %s', $term));
        }

        return $propertyId;
    }

    private function ensureVocabularyId(
        string $prefix,
        array &$vocabularyIds,
        array $vocabularyDefinitions,
        SymfonyStyle $io,
        bool $dryRun,
    ): int {
        $vocabularyId = $vocabularyIds[$prefix] ?? null;
        if (is_int($vocabularyId)) {
            return $vocabularyId;
        }

        if (!array_key_exists($prefix, $vocabularyDefinitions)) {
            throw new RuntimeException(sprintf('Unknown vocabulary prefix: %s', $prefix));
        }

        $definition = $vocabularyDefinitions[$prefix];
        $label = $this->requireString($definition, 'label');
        $namespace = $this->optionalString($definition, 'namespace_uri');

        if ($dryRun) {
            $io->text(sprintf('Would create vocabulary "%s".', $prefix));
            return 0;
        }

        $this->omeka->createVocabulary($prefix, $label, $namespace);

        $vocabularyIds = $this->vocabularyIdsByPrefix();
        $vocabularyId = $vocabularyIds[$prefix] ?? null;
        if (!is_int($vocabularyId)) {
            throw new RuntimeException(sprintf('Failed creating vocabulary prefix: %s', $prefix));
        }

        return $vocabularyId;
    }

    private function indexVocabularyDefinitions(array $definitions): array
    {
        $indexed = [];
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                throw new RuntimeException('Vocabulary definition must be an object.');
            }
            $prefix = $this->requireString($definition, 'prefix');
            $indexed[$prefix] = $definition;
        }

        return $indexed;
    }

    private function indexPropertyDefinitions(array $definitions): array
    {
        $indexed = [];
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                throw new RuntimeException('Property definition must be an object.');
            }
            $term = $this->requireString($definition, 'term');
            $indexed[$term] = $definition;
        }

        return $indexed;
    }

    private function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Missing or invalid "%s".', $key));
        }

        return $value;
    }

    private function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}

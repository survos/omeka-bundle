<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Command;

use Survos\OmekaBundle\Client\OmekaClient;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('omeka:items:create', 'Create an item via the Omeka API')]
final class OmekaCreateItemCommand
{
    public function __construct(
        private OmekaClient $omeka,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Item title')]
        ?string $title = null,
        #[Option('Resource template id')]
        ?int $templateId = null,
        #[Option('Resource class id')]
        ?int $classId = null,
        #[Option('Item set id')]
        ?int $itemSetId = null,
        #[Option('Prompt for optional fields as well')]
        ?bool $complete = null,
        #[Option('Make item public')]
        ?bool $isPublic = null,
        #[Option('Site slug (used for public URL)')]
        ?string $siteSlug = null,
        #[Option('Extra metadata JSON file')]
        ?string $metadataFile = null,
        #[Option('Media file path to attach')]
        ?string $mediaFile = null,
    ): int {
        if (null === $templateId) {
            $templateId = $this->chooseTemplateId($io);
        }

        if (null === $siteSlug) {
            $siteSlug = $this->chooseSiteSlug($io);
        }

        $complete ??= false;
        $isPublic ??= $io->confirm('Make item public?', true);
        $title ??= $io->ask('Title');

        if (!\is_string($title) || '' === $title) {
            throw new \RuntimeException('Title is required.');
        }

        $properties = ['dcterms:title' => $title];

        if (null !== $templateId) {
            $properties = \array_merge(
                $properties,
                $this->promptTemplateFields($io, $templateId, $complete)
            );
        }

        if (null !== $metadataFile) {
            if (!\is_file($metadataFile)) {
                throw new \RuntimeException(\sprintf('Metadata file not found: "%s".', $metadataFile));
            }

            $raw = \file_get_contents($metadataFile);
            if (false === $raw) {
                throw new \RuntimeException(\sprintf('Failed reading metadata file "%s".', $metadataFile));
            }

            try {
                $decoded = \json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \RuntimeException(\sprintf('Invalid JSON in "%s": %s', $metadataFile, $exception->getMessage()), previous: $exception);
            }

            if (!\is_array($decoded)) {
                throw new \RuntimeException(\sprintf('Metadata in "%s" must decode to an object.', $metadataFile));
            }

            $properties = \array_merge($properties, $decoded);
        }

        $mediaFiles = null !== $mediaFile ? [$mediaFile] : null;

        $item = $this->omeka->createItem(
            $properties,
            templateId: $templateId,
            classId: $classId,
            itemSetId: $itemSetId,
            mediaFiles: $mediaFiles,
            isPublic: $isPublic,
        );

        $io->success(\sprintf('Created item %d.', $item->id));
        $io->writeln(\sprintf('Admin URL: %s', $this->adminItemUrl($item->id)));
        $io->writeln(\sprintf('Public URL: %s', $this->itemUrl($item->id, $siteSlug)));

        return Command::SUCCESS;
    }

    private function chooseTemplateId(SymfonyStyle $io): ?int
    {
        $templates = $this->omeka->getResourceTemplates();

        if ([] === $templates) {
            $io->warning('No resource templates found; continuing without a template.');

            return null;
        }

        $choices = \array_map(
            static fn ($template): string => \sprintf('%d - %s', $template->id, $template->label),
            $templates,
        );

        $question = new ChoiceQuestion('Select a resource template', $choices);
        $selected = $io->askQuestion($question);

        if (!\is_string($selected)) {
            return null;
        }

        if (1 === \preg_match('/^(\d+)\s+-\s+/', $selected, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function promptTemplateFields(SymfonyStyle $io, int $templateId, bool $complete): array
    {
        $template = $this->omeka->getResourceTemplate($templateId);
        $propertyMap = $this->propertiesById();
        $metadata = [];

        foreach ($template->properties as $property) {
            $isRequired = $property['o:is_required'] ?? false;
            if (true !== $isRequired && false === $complete) {
                continue;
            }

            $term = $property['o:property']['o:term'] ?? null;
            if (!\is_string($term) || '' === $term) {
                $propertyId = $property['o:property']['o:id'] ?? null;
                $term = is_int($propertyId) && isset($propertyMap[$propertyId])
                    ? $propertyMap[$propertyId]
                    : null;
            }

            if (!\is_string($term) || '' === $term || 'dcterms:title' === $term) {
                continue;
            }

            $label = true === $isRequired ? 'Required' : 'Optional';
            $value = $io->ask(\sprintf('%s: %s', $label, $term));

            if (!\is_string($value) || '' === $value) {
                if (true === $isRequired) {
                    throw new \RuntimeException(\sprintf('Required field missing: %s', $term));
                }

                continue;
            }

            if (true === $isRequired && (!\is_string($value) || '' === $value)) {
                throw new \RuntimeException(\sprintf('Required field missing: %s', $term));
            }

            $metadata[$term] = $value;
        }

        return $metadata;
    }

    private function chooseSiteSlug(SymfonyStyle $io): ?string
    {
        $sites = $this->omeka->getSites();

        if ([] === $sites) {
            $io->warning('No sites returned; public URL may 404 without a site.');

            return null;
        }

        $choices = ['Skip (no site)'];
        foreach ($sites as $site) {
            $slug = $site['o:slug'] ?? '';
            $title = $site['o:title'] ?? '';
            if (!\is_string($slug) || '' === $slug) {
                continue;
            }
            if (!\is_string($title) || '' === $title) {
                $title = $slug;
            }

            $choices[] = \sprintf('%s - %s', $slug, $title);
        }

        $question = new ChoiceQuestion('Select a site for the public URL', $choices);
        $selected = $io->askQuestion($question);

        if (!\is_string($selected) || \str_contains($selected, 'Skip')) {
            return null;
        }

        if (1 === \preg_match('/^([^\s]+)\s+-\s+/', $selected, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function propertiesById(): array
    {
        $map = [];

        foreach ($this->omeka->getProperties() as $property) {
            $map[$property->id] = $property->term;
        }

        \ksort($map);

        return $map;
    }

    private function adminItemUrl(int $itemId): string
    {
        $base = $this->omeka->getApiUrl();
        if (\str_ends_with($base, '/api')) {
            $base = \substr($base, 0, -4);
        }

        return \sprintf('%s/admin/item/%d', $base, $itemId);
    }

    private function itemUrl(int $itemId, ?string $siteSlug): string
    {
        $base = $this->omeka->getApiUrl();
        if (\str_ends_with($base, '/api')) {
            $base = \substr($base, 0, -4);
        }

        if (null === $siteSlug || '' === $siteSlug) {
            return \sprintf('%s/item/%d', $base, $itemId);
        }

        return \sprintf('%s/s/%s/item/%d', $base, $siteSlug, $itemId);
    }
}

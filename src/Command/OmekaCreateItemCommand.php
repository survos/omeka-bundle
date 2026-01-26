<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Command;

use JsonException;
use RuntimeException;
use Survos\OmekaBundle\Client\OmekaClient;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use function array_merge;
use function file_get_contents;
use function is_array;
use function is_file;
use function json_decode;
use function sprintf;

#[AsCommand('omeka:items:create', 'Create an item via the Omeka API')]
final class OmekaCreateItemCommand
{
    public function __construct(private OmekaClient $omeka)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Item title')]
        string $title,
        #[Option('Resource template id')]
        ?int $templateId = null,
        #[Option('Resource class id')]
        ?int $classId = null,
        #[Option('Item set id')]
        ?int $itemSetId = null,
        #[Option('Extra metadata JSON file')]
        ?string $metadataFile = null,
        #[Option('Media file path to attach')]
        ?string $mediaFile = null,
    ): int {
        $properties = ['dcterms:title' => $title];

        if ($metadataFile !== null) {
            if (!is_file($metadataFile)) {
                throw new RuntimeException(sprintf('Metadata file not found: "%s".', $metadataFile));
            }

            $raw = file_get_contents($metadataFile);
            if ($raw === false) {
                throw new RuntimeException(sprintf('Failed reading metadata file "%s".', $metadataFile));
            }

            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException(
                    sprintf('Invalid JSON in "%s": %s', $metadataFile, $exception->getMessage()),
                    previous: $exception,
                );
            }

            if (!is_array($decoded)) {
                throw new RuntimeException(sprintf('Metadata in "%s" must decode to an object.', $metadataFile));
            }

            $properties = array_merge($properties, $decoded);
        }

        $mediaFiles = $mediaFile !== null ? [$mediaFile] : null;

        $item = $this->omeka->createItem(
            $properties,
            templateId: $templateId,
            classId: $classId,
            itemSetId: $itemSetId,
            mediaFiles: $mediaFiles,
        );

        $io->success(sprintf('Created item %d.', $item->id));

        return Command::SUCCESS;
    }
}

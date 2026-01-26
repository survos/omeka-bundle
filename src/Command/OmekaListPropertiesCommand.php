<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Command;

use Survos\OmekaBundle\Client\OmekaClient;
use Survos\OmekaBundle\Model\Property;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;

#[AsCommand('omeka:properties:list', 'List properties from an Omeka API')]
final class OmekaListPropertiesCommand
{
    public function __construct(private OmekaClient $omeka)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Filter by vocabulary id')]
        ?int $vocabularyId = null,
        #[Option('Limit number of rows')]
        ?int $limit = null,
    ): int {
        $properties = $this->omeka->getProperties();

        if ($vocabularyId !== null) {
            $properties = array_filter(
                $properties,
                static fn(Property $property): bool => $property->vocabularyId === $vocabularyId
            );
        }

        $rows = array_map(static function (Property $property): array {
            return [$property->id, $property->term, $property->label];
        }, array_values($properties));

        if ($limit !== null) {
            $rows = array_slice($rows, 0, $limit);
        }

        if (count($rows) > 0) {
            $io->table(['ID', 'Term', 'Label'], $rows);
        } else {
            $io->warning('No properties returned.');
        }

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Command;

use Survos\OmekaBundle\Client\OmekaClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use function array_map;
use function count;
use function is_string;
use function sprintf;

#[AsCommand('omeka:items:list', 'List items from an Omeka API')]
final class OmekaListItemsCommand
{
    public function __construct(private OmekaClient $omeka)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Resource template id')]
        ?int $resourceTemplateId = null,
        #[Option('Resource class id')]
        ?int $resourceClassId = null,
        #[Option('Item set id')]
        ?int $itemSetId = null,
        #[Option('Public filter (true/false)')]
        ?bool $isPublic = null,
        #[Option('Page number')]
        int $page = 1,
        #[Option('Items per page')]
        int $perPage = 25,
        #[Option('Sort by field')]
        string $sortBy = 'id',
        #[Option('Sort order')]
        string $sortOrder = 'desc',
    ): int {
        $result = $this->omeka->getItems(
            resourceTemplateId: $resourceTemplateId,
            resourceClassId: $resourceClassId,
            itemSetId: $itemSetId,
            isPublic: $isPublic,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortOrder: $sortOrder,
        );

        $rows = array_map(static function (array $item): array {
            $id = $item['o:id'] ?? null;
            $title = $item['o:title'] ?? '';
            if (!is_string($title) || $title === '') {
                $title = '[no title]';
            }

            return [$id, $title];
        }, $result->results);

        $io->writeln(sprintf('Total results: %d', $result->totalResults));

        if (count($rows) > 0) {
            $io->table(['ID', 'Title'], $rows);
        } else {
            $io->warning('No items returned for this query.');
        }

        return Command::SUCCESS;
    }
}

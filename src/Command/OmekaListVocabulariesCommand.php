<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Command;

use Survos\OmekaBundle\Client\OmekaClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use function array_map;
use function count;

#[AsCommand('omeka:vocabularies:list', 'List vocabularies from an Omeka API')]
final class OmekaListVocabulariesCommand
{
    public function __construct(private OmekaClient $omeka)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $vocabularies = $this->omeka->getVocabularies();

        $rows = array_map(static function (array $vocab): array {
            return [
                $vocab['o:id'] ?? null,
                $vocab['o:prefix'] ?? '',
                $vocab['o:label'] ?? '',
            ];
        }, $vocabularies);

        if (count($rows) > 0) {
            $io->table(['ID', 'Prefix', 'Label'], $rows);
        } else {
            $io->warning('No vocabularies returned.');
        }

        return Command::SUCCESS;
    }
}

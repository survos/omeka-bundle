<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Command;

use Survos\OmekaBundle\Client\OmekaClient;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('omeka:custom-vocab:terms', 'List terms from an Omeka Custom Vocab')]
final class OmekaCustomVocabTermsCommand
{
    public function __construct(private OmekaClient $omeka)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Custom vocab label')]
        string $label,
    ): int {
        $terms = $this->omeka->getCustomVocabTerms($label);

        if (0 === \count($terms)) {
            $io->warning('No terms returned.');

            return Command::SUCCESS;
        }

        $io->writeln(\implode("\n", $terms));

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Command;

use Survos\OmekaBundle\Client\OmekaClient;
use Survos\OmekaBundle\Model\ResourceTemplate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use function array_map;
use function count;
use function is_int;
use function is_string;

#[AsCommand('omeka:templates:list', 'List resource templates from an Omeka API')]
final class OmekaListResourceTemplatesCommand
{
    public function __construct(private OmekaClient $omeka)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $templates = $this->omeka->getResourceTemplates();
        $classes = $this->omeka->getResourceClasses();
        $classById = [];

        foreach ($classes as $class) {
            $id = $class['o:id'] ?? null;
            $label = $class['o:label'] ?? null;
            if (is_string($label) && $label !== '' && is_int($id)) {
                $classById[$id] = $label;
            }
        }

        $rows = array_map(static function (ResourceTemplate $template): array {
            return [$template->id, $template->label, $template->resourceClassId];
        }, $templates);

        $rows = array_map(static function (array $row) use ($classById): array {
            $classId = $row[2];
            $classLabel = is_int($classId) ? ($classById[$classId] ?? null) : null;
            $row[2] = $classLabel ?? $classId;
            return $row;
        }, $rows);

        if (count($rows) > 0) {
            $io->table(['ID', 'Label', 'Resource Class'], $rows);
        } else {
            $io->warning('No resource templates returned.');
        }

        return Command::SUCCESS;
    }
}

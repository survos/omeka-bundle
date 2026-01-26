<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Command;

use RuntimeException;
use Survos\OmekaBundle\Client\OmekaClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use function is_string;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function substr;

#[AsCommand('omeka:sites:create', 'Create an Omeka site')]
final class OmekaCreateSiteCommand
{
    public function __construct(
        private OmekaClient $omeka,
        #[Autowire('%env(OMEKA_API_URL)%')]
        private string $apiUrl,
    )
    {
        $this->apiUrl = rtrim($this->apiUrl, '/');
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Site slug')]
        ?string $slug = null,
        #[Argument('Site title')]
        ?string $title = null,
        #[Option('Theme name')]
        ?string $theme = null,
        #[Option('Make site public')]
        ?bool $isPublic = null,
    ): int {
        $slug ??= $io->ask('Site slug');
        $title ??= $io->ask('Site title');
        $theme ??= $io->ask('Theme name', 'default');
        $isPublic ??= $io->confirm('Make site public?', true);

        if (!is_string($slug) || $slug === '') {
            throw new RuntimeException('Site slug is required.');
        }
        if (!is_string($title) || $title === '') {
            throw new RuntimeException('Site title is required.');
        }

        $site = $this->omeka->createSite($slug, $title, $theme, $isPublic);

        $siteId = $site['o:id'] ?? null;
        $io->success(sprintf('Created site %s.', (string) $siteId));
        $io->writeln(sprintf('Admin URL: %s', $this->adminSiteUrl((int) $siteId)));
        $io->writeln(sprintf('Public URL: %s', $this->publicSiteUrl($slug)));

        return Command::SUCCESS;
    }

    private function adminSiteUrl(int $siteId): string
    {
        $base = $this->apiUrl;
        if (str_ends_with($base, '/api')) {
            $base = substr($base, 0, -4);
        }

        return sprintf('%s/admin/site/%d', $base, $siteId);
    }

    private function publicSiteUrl(string $slug): string
    {
        $base = $this->apiUrl;
        if (str_ends_with($base, '/api')) {
            $base = substr($base, 0, -4);
        }

        return sprintf('%s/s/%s', $base, $slug);
    }
}

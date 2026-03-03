<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Command;

use Survos\OmekaBundle\Crawler\OmekaPublicCrawler;
use Survos\OmekaBundle\Message\OmekaCrawlMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use function array_map;
use function count;
use function is_string;
use function sprintf;

/**
 * Crawl a public Omeka-S site and export items as JSONL.
 *
 * No API key required — works against any publicly-accessible Omeka-S installation.
 *
 * Examples:
 *
 *   # Discover what sites and collections are available
 *   bin/console omeka:crawl https://iaamcfh.omeka.net --list-sites
 *   bin/console omeka:crawl https://iaamcfh.omeka.net --list-collections
 *
 *   # Crawl everything, write raw JSON-LD to iaamcfh_omeka_net_all.jsonl
 *   bin/console omeka:crawl https://iaamcfh.omeka.net
 *
 *   # Crawl one collection, normalize to flat/sparse structure
 *   bin/console omeka:crawl https://iaamcfh.omeka.net --item-set=5 --normalize
 *
 *   # Write to a specific file
 *   bin/console omeka:crawl https://iaamcfh.omeka.net --output=/data/iaamcfh.jsonl
 *
 *   # Dispatch to an async Messenger transport (configure routing in messenger.yaml)
 *   bin/console omeka:crawl https://iaamcfh.omeka.net --transport=async
 *
 *   # Re-crawl even if the sidecar says the file is complete
 *   bin/console omeka:crawl https://iaamcfh.omeka.net --force
 */
#[AsCommand('omeka:crawl', 'Crawl a public Omeka-S site and export items as JSONL')]
final class OmekaCrawlCommand
{
    public function __construct(
        private readonly OmekaPublicCrawler $crawler,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Base URL of the public Omeka-S site (e.g. https://iaamcfh.omeka.net)')]
        string $url,
        #[Option('List Omeka sites registered in this installation and exit')]
        bool $listSites = false,
        #[Option('List item sets (collections) and exit')]
        bool $listCollections = false,
        #[Option('Restrict crawl to this item set ID')]
        ?int $itemSet = null,
        #[Option('Output .jsonl file path (default: auto-derived in CWD)')]
        ?string $output = null,
        #[Option('Output directory for auto-derived filenames (default: CWD)')]
        ?string $outputDir = null,
        #[Option('Normalize output to flat sparse structure (Meilisearch-ready)')]
        bool $normalize = false,
        #[Option('Items per API request (1–100)')]
        int $perPage = 50,
        #[Option('Re-crawl even if the sidecar reports the file is complete')]
        bool $force = false,
        #[Option('Dispatch to this Messenger transport (e.g. async) instead of running inline')]
        ?string $transport = null,
        #[Option('Count items without writing')]
        bool $dryRun = false,
    ): int {
        // ── Discovery: list sites ─────────────────────────────────────────────
        if ($listSites) {
            return $this->doListSites($io, $url);
        }

        // ── Discovery: list collections ───────────────────────────────────────
        if ($listCollections) {
            return $this->doListCollections($io, $url);
        }

        // ── Dry run ───────────────────────────────────────────────────────────
        if ($dryRun) {
            return $this->doDryRun($io, $url, $itemSet, $perPage);
        }

        // ── Crawl: determine which item sets to process ───────────────────────
        $itemSetIds = $this->resolveItemSetIds($io, $url, $itemSet);

        if ($itemSetIds === []) {
            // Crawl all items (no item-set filter)
            $itemSetIds = [null];
        }

        $dispatched = 0;

        foreach ($itemSetIds as $setId) {
            $message = new OmekaCrawlMessage(
                siteUrl: $url,
                itemSetId: $setId,
                outputPath: $output,
                outputDir: $outputDir,
                normalize: $normalize,
                perPage: $perPage,
                force: $force,
                transport: $transport,
            );

            $stamps = [];
            if (is_string($transport) && $transport !== '') {
                $stamps[] = new TransportNamesStamp($transport);
            }

            $this->bus->dispatch($message, $stamps);
            $dispatched++;

            $label = $setId !== null ? sprintf('item set %d', $setId) : 'all items';
            $io->writeln(sprintf(
                'Dispatched crawl for <info>%s</info> → %s',
                $label,
                $transport !== null ? sprintf('<comment>transport: %s</comment>', $transport) : 'sync',
            ));
        }

        $io->success(sprintf('Dispatched %d crawl message(s).', $dispatched));

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Discovery helpers
    // -------------------------------------------------------------------------

    private function doListSites(SymfonyStyle $io, string $url): int
    {
        $io->section(sprintf('Sites at %s', $url));

        $result = $this->crawler->getSites($url);
        $sites = $result['results'];

        if ($sites === []) {
            $io->warning('No public sites found.');
            return Command::SUCCESS;
        }

        $rows = array_map(static function (array $site): array {
            return [
                $site['o:id'] ?? '',
                $site['o:title'] ?? '',
                $site['o:slug'] ?? '',
                $site['@id'] ?? '',
            ];
        }, $sites);

        $io->writeln(sprintf('Total: <info>%d</info> site(s)', $result['total']));
        $io->table(['ID', 'Title', 'Slug', 'URL'], $rows);

        return Command::SUCCESS;
    }

    private function doListCollections(SymfonyStyle $io, string $url): int
    {
        $io->section(sprintf('Item sets (collections) at %s', $url));

        $result = $this->crawler->getItemSets($url);
        $sets = $result['results'];

        if ($sets === []) {
            $io->warning('No public item sets found.');
            return Command::SUCCESS;
        }

        $rows = array_map(static function (array $set): array {
            // o:items contains a link like {"@id": ".../api/items?item_set_id=5"}
            $itemsLink = $set['o:items']['@id'] ?? '';
            return [
                $set['o:id'] ?? '',
                $set['o:title'] ?? '',
                $itemsLink,
            ];
        }, $sets);

        $io->writeln(sprintf('Total: <info>%d</info> item set(s)', $result['total']));
        $io->table(['ID', 'Title', 'Items URL'], $rows);

        return Command::SUCCESS;
    }

    private function doDryRun(SymfonyStyle $io, string $url, ?int $itemSet, int $perPage): int
    {
        $io->section(sprintf('Dry run: counting items at %s', $url));

        $generator = $this->crawler->crawlItems($url, $itemSet, $perPage);

        $progress = $io->createProgressBar();
        $progress->start();

        $count = 0;
        foreach ($generator as $item) {
            $count++;
            $progress->advance();
        }

        $progress->finish();
        $io->newLine();
        $io->success(sprintf('Found %d item(s) — nothing written (dry run).', $count));

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * If --item-set was given, return [id]. Otherwise return [] to signal "all items".
     *
     * @return list<int>
     */
    private function resolveItemSetIds(SymfonyStyle $io, string $url, ?int $itemSet): array
    {
        if ($itemSet !== null) {
            return [$itemSet];
        }

        return [];
    }
}

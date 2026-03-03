<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Command;

use Survos\CoreBundle\Service\SurvosUtils;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\JsonlBundle\IO\JsonlWriterOptions;
use Survos\OmekaBundle\Crawler\OmekaDirectoryParser;
use Survos\OmekaBundle\Crawler\OmekaPublicCrawler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use function array_filter;
use function array_map;
use function array_values;
use function count;
use function getcwd;
use function in_array;
use function rtrim;
use function sprintf;
use function substr;

/**
 * Fetches the official Omeka Classic and Omeka S site directories from omeka.org,
 * probes each listed URL to confirm it is live and detect its Omeka version,
 * and writes a JSONL file of verified Omeka installations.
 *
 * This JSONL is the source of truth for the museum/institution directory:
 * which Omeka sites exist in the wild, what version they run, and how many
 * public items they expose.
 *
 * Usage:
 *   bin/console omeka:directory                           # probe all, write omeka-directory.jsonl
 *   bin/console omeka:directory --type=s                  # only Omeka S sites
 *   bin/console omeka:directory --type=classic            # only Omeka Classic sites
 *   bin/console omeka:directory --no-probe                # skip live probing, write raw directory
 *   bin/console omeka:directory --output=/data/sites.jsonl
 *   bin/console omeka:directory --table                   # show table, don't write JSONL
 *   bin/console omeka:directory --force                   # re-probe even if output exists
 *
 * The probe step makes one HTTP request per site (HEAD to /api/items?per_page=1).
 * It runs sequentially; expect ~500ms per site with a 10s timeout. The full
 * directory has ~600 Classic + ~120 S entries. Use --type to narrow scope.
 *
 * Output JSONL schema per row:
 *   {
 *     "name":         "Site display name",
 *     "url":          "https://resolved-installation-root",
 *     "listedUrl":    "https://original-url-from-directory",
 *     "type":         "s" | "classic",
 *     "description":  "...",
 *     "plugins":      ["Plugin A", "Plugin B"],
 *     "live":         true | false,
 *     "omekaVersion": "4.1.1" | null,
 *     "totalItems":   1578 | 0,
 *     "error":        null | "HTTP 404" | "Connection refused"
 *   }
 */
#[AsCommand('omeka:directory', 'Fetch the Omeka site directory and probe each site')]
final class OmekaDirectoryCommand
{
    public function __construct(
        private readonly OmekaDirectoryParser $parser,
        private readonly OmekaPublicCrawler $crawler,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Which directory to fetch: s, classic, or all')]
        string $type = 'all',
        #[Option('Output JSONL file path')]
        ?string $output = null,
        #[Option('Skip live probing — write raw directory data only')]
        bool $noProbe = false,
        #[Option('Show results as a table instead of writing JSONL')]
        bool $table = false,
        #[Option('Timeout in seconds per probe request')]
        int $timeout = 10,
        #[Option('Re-write output file even if it already exists and is complete')]
        bool $force = false,
        #[Option('Only write sites that are live (omit unreachable entries)')]
        bool $liveOnly = false,
        #[Option('Only write sites with permissive licenses (cc, cc0, pd)')]
        bool $permissiveOnly = false,
    ): int {
        // ── Validate --type ───────────────────────────────────────────────────
        if (!in_array($type, ['all', 's', 'classic'], true)) {
            $io->error(sprintf('Invalid --type "%s". Use "all", "s", or "classic".', $type));
            return Command::FAILURE;
        }

        // ── Fetch directory ───────────────────────────────────────────────────
        $io->section(sprintf('Fetching Omeka %s directory from omeka.org…', $type));

        $entries = match ($type) {
            's'       => $this->parser->fetchS(),
            'classic' => $this->parser->fetchClassic(),
            default   => $this->parser->fetchAll(),
        };

        $total = count($entries);
        $io->writeln(sprintf('Found <info>%d</info> directory entries.', $total));

        if ($total === 0) {
            $io->warning('No entries found — the directory page format may have changed.');
            return Command::FAILURE;
        }

        // ── Probe each site ───────────────────────────────────────────────────
        $rows = [];

        if ($noProbe) {
            foreach ($entries as $entry) {
                $rows[] = array_merge($entry, [
                    'live'         => null,
                    'omekaVersion' => null,
                    'totalItems'   => 0,
                    'error'        => null,
                ]);
            }
        } else {
            $io->section(sprintf('Probing %d sites (timeout: %ds each)…', $total, $timeout));
            $progress = $io->createProgressBar($total);
            $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
            $progress->start();

            foreach ($entries as $entry) {
                $progress->setMessage(sprintf('%-50s', substr($entry['name'], 0, 50)));

                $probe = $this->crawler->detectVersion($entry['url'], $timeout);

                $row = array_merge($entry, [
                    'live'          => $probe['version'] !== null,
                    'omekaVersion'  => $probe['omekaVersion'],
                    'totalItems'    => $probe['totalItems'],
                    'license'       => $probe['license'],
                    'licenseDetail' => $probe['licenseDetail'],
                    'error'         => $probe['error'],
                    // Override type with confirmed version if detection disagrees
                    // (e.g. a Classic site listed in the S directory)
                    'detectedType'  => $probe['version'],
                ]);

                /** @var array<string,mixed> $row */
                $row = SurvosUtils::removeNullsAndEmptyArrays($row);

                $rows[] = $row;
                $progress->advance();
            }

            $progress->finish();
            $io->newLine(2);

            $live        = count(array_filter($rows, fn($r) => ($r['live'] ?? false) === true));
            $dead        = $total - $live;
            $permissive  = count(array_filter($rows, fn($r) => in_array($r['license'] ?? '', ['cc', 'cc0', 'pd'], true)));
            $restricted  = count(array_filter($rows, fn($r) => ($r['license'] ?? '') === 'restricted'));
            $unknown     = count(array_filter($rows, fn($r) => in_array($r['license'] ?? '', ['unknown', ''], true)));
            $io->writeln(sprintf(
                '<info>%d</info> live  |  <comment>%d</comment> unreachable  |  license: <info>%d</info> permissive, <comment>%d</comment> restricted, %d unknown',
                $live, $dead, $permissive, $restricted, $unknown,
            ));
        }

        // ── Filter ────────────────────────────────────────────────────────────
        if ($liveOnly) {
            $rows = array_values(array_filter($rows, fn($r) => ($r['live'] ?? false) === true));
            $io->writeln(sprintf('After --live-only filter: <info>%d</info> rows.', count($rows)));
        }

        if ($permissiveOnly) {
            $rows = array_values(array_filter($rows, fn($r) => in_array($r['license'] ?? '', ['cc', 'cc0', 'pd'], true)));
            $io->writeln(sprintf('After --permissive-only filter: <info>%d</info> rows.', count($rows)));
        }

        // ── Output ────────────────────────────────────────────────────────────
        if ($table) {
            return $this->renderTable($io, $rows);
        }

        return $this->writeJsonl($io, $rows, $output, $force);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function renderTable(SymfonyStyle $io, array $rows): int
    {
        $tableRows = array_map(static function (array $r): array {
            $liveVal = $r['live'] ?? null;
            $live = match (true) {
                $liveVal === true  => '<info>yes</info>',
                $liveVal === false => '<comment>no</comment>',
                default            => '—',
            };
            $licenseVal = $r['license'] ?? '—';
            $license = match ($licenseVal) {
                'cc0'        => '<info>CC0</info>',
                'pd'         => '<info>Public Domain</info>',
                'cc'         => '<info>' . ($r['licenseDetail'] ?? 'CC') . '</info>',
                'restricted' => '<comment>restricted</comment>',
                'unknown'    => '?',
                default      => '—',
            };
            return [
                $r['type'] ?? '?',
                substr($r['name'] ?? '', 0, 38),
                substr($r['url'] ?? '', 0, 45),
                $live,
                $r['omekaVersion'] ?? '—',
                $r['totalItems'] ?? '—',
                $license,
            ];
        }, $rows);

        $io->table(['Type', 'Name', 'URL', 'Live', 'Version', 'Items', 'License'], $tableRows);

        return Command::SUCCESS;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function writeJsonl(SymfonyStyle $io, array $rows, ?string $outputPath, bool $force): int
    {
        $path = $outputPath ?? sprintf('%s/omeka-directory.jsonl', rtrim((string) getcwd(), '/'));

        $writer = JsonlWriter::open(
            $path,
            mode: $force ? 'w' : 'a',
            options: new JsonlWriterOptions(ensureDir: true, resetSidecars: $force),
        );

        foreach ($rows as $row) {
            // Use site URL as dedup token — skip if already written on resume
            $writer->write($row, $row['url'] ?? null);
        }

        $result = $writer->finish();

        $io->success(sprintf(
            'Wrote %d rows → %s',
            $result->state->getStats()->getRows(),
            $path,
        ));

        return Command::SUCCESS;
    }
}

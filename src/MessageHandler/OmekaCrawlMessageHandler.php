<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\MessageHandler;

use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\JsonlBundle\IO\JsonlWriterOptions;
use Survos\JsonlBundle\Service\SidecarService;
use Survos\OmekaBundle\Crawler\OmekaPublicCrawler;
use Survos\OmekaBundle\Event\OmekaCrawlItemEvent;
use Survos\OmekaBundle\Message\OmekaCrawlMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use function getcwd;
use function is_file;
use function rtrim;
use function sprintf;
use function str_replace;

/**
 * Handles OmekaCrawlMessage: crawls a public Omeka-S site and writes a JSONL file.
 *
 * Flow per message:
 *  1. Resolve the output path (explicit or auto-derived from site slug + item set)
 *  2. Check the sidecar — skip if already completed and $force = false
 *  3. Open a JsonlWriter (mode 'a' so the token index enables per-item dedup on resume)
 *  4. Page through the site via OmekaPublicCrawler::crawlItems()
 *  5. For each item: optionally normalize, dispatch OmekaCrawlItemEvent (listeners may
 *     modify or skip the row), then write to JSONL using o:id as the dedup token
 *  6. Finish (marks sidecar complete, closes file, releases lock)
 *
 * Make async by adding to messenger.yaml:
 *   framework.messenger.routing:
 *     Survos\OmekaBundle\Message\OmekaCrawlMessage: async
 */
#[AsMessageHandler]
final class OmekaCrawlMessageHandler
{
    public function __construct(
        private readonly OmekaPublicCrawler $crawler,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function __invoke(OmekaCrawlMessage $message): void
    {
        $outputPath = $this->resolveOutputPath($message);

        // Skip if already complete (unless forced)
        if (!$message->force && $this->isComplete($outputPath)) {
            return;
        }

        // Open in append mode so the token index lets us skip already-written
        // items on resume without re-reading the JSONL.
        $writer = JsonlWriter::open(
            $outputPath,
            mode: 'a',
            options: new JsonlWriterOptions(ensureDir: true, resetSidecars: false),
        );

        $index = 0;

        try {
            foreach ($this->crawler->crawlItems($message->siteUrl, $message->itemSetId, $message->perPage) as $raw) {
                $row = $message->normalize
                    ? $this->crawler->normalizeItem($raw)
                    : $raw;

                $event = new OmekaCrawlItemEvent(
                    row: $row,
                    siteUrl: $message->siteUrl,
                    itemSetId: $message->itemSetId,
                    index: $index,
                );

                $this->dispatcher->dispatch($event);

                if ($event->skip) {
                    $index++;
                    continue;
                }

                // Use o:id as the dedup token — safe on resume, prevents duplicates
                $tokenCode = isset($raw['o:id']) ? (string) $raw['o:id'] : null;
                $writer->write($event->row, $tokenCode);

                $index++;
            }

            $writer->finish();
        } catch (\Throwable $e) {
            // Close cleanly without marking complete so the next run can resume
            $writer->close();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolveOutputPath(OmekaCrawlMessage $message): string
    {
        if ($message->outputPath !== null) {
            return $message->outputPath;
        }

        $slug = $this->crawler->siteSlug($message->siteUrl);
        $setLabel = $message->itemSetId !== null
            ? sprintf('set_%d', $message->itemSetId)
            : 'all';

        $filename = sprintf('%s_%s.jsonl', str_replace('.', '_', $slug), $setLabel);

        $dir = $message->outputDir ?? (string) getcwd();

        return rtrim($dir, '/') . '/' . $filename;
    }

    private function isComplete(string $outputPath): bool
    {
        if (!is_file($outputPath)) {
            return false;
        }

        $sidecar = new SidecarService();

        if (!$sidecar->exists($outputPath)) {
            return false;
        }

        return $sidecar->load($outputPath)->completed;
    }
}

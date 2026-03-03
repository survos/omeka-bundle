<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Message;

/**
 * Message: crawl one item set (or all items) from a public Omeka-S site and
 * stream the results into a JSONL file.
 *
 * Dispatched by OmekaCrawlCommand. Handled by OmekaCrawlMessageHandler.
 *
 * By default this is processed synchronously. To move crawls to a background
 * queue, route this message class to an async transport in messenger.yaml:
 *
 *   framework:
 *     messenger:
 *       routing:
 *         Survos\OmekaBundle\Message\OmekaCrawlMessage: async
 */
final readonly class OmekaCrawlMessage
{
    /**
     * @param string      $siteUrl    Base URL of the public Omeka-S site
     *                                e.g. "https://iaamcfh.omeka.net"
     * @param int|null    $itemSetId  Restrict crawl to this item set; null = all items
     * @param string|null $outputPath Destination .jsonl file path.
     *                                When null, derived automatically:
     *                                  {slug}_{itemSetId}.jsonl  (or {slug}_all.jsonl)
     * @param string|null $outputDir  Directory for auto-derived filenames. Defaults to CWD.
     * @param bool        $normalize  Flatten JSON-LD to a sparse, Meilisearch-ready structure
     * @param int         $perPage    Items per API request (1–100)
     * @param bool        $force      Re-crawl even if the sidecar reports completion
     * @param string|null $transport  Messenger transport to stamp outgoing sub-messages (unused
     *                                internally; exposed so callers can forward it)
     */
    public function __construct(
        public string $siteUrl,
        public ?int $itemSetId = null,
        public ?string $outputPath = null,
        public ?string $outputDir = null,
        public bool $normalize = false,
        public int $perPage = 50,
        public bool $force = false,
        public ?string $transport = null,
    ) {
    }
}

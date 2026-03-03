<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Event;

/**
 * Dispatched for each item during an Omeka crawl, before it is written to JSONL.
 *
 * Listeners may:
 *   - Modify $event->row to transform the item (add, remove, or rename keys)
 *   - Set $event->skip = true to discard the item entirely
 *
 * Example listener:
 *
 *   #[AsEventListener(event: OmekaCrawlItemEvent::class)]
 *   public function onCrawlItem(OmekaCrawlItemEvent $event): void
 *   {
 *       // Discard items without a description
 *       if (empty($event->row['description'])) {
 *           $event->skip = true;
 *           return;
 *       }
 *       // Add a computed field
 *       $event->row['_source'] = 'omeka';
 *   }
 */
final class OmekaCrawlItemEvent
{
    /**
     * Set to true to prevent this item from being written to JSONL.
     */
    public bool $skip = false;

    /**
     * @param array<string,mixed> $row        The item data (raw JSON-LD or normalized). Mutable.
     * @param string              $siteUrl    The Omeka-S site base URL being crawled
     * @param int|null            $itemSetId  The item set being crawled, or null if crawling all
     * @param int                 $index      Zero-based position of this item within the current crawl
     */
    public function __construct(
        public array $row,
        public readonly string $siteUrl,
        public readonly ?int $itemSetId,
        public readonly int $index,
    ) {
    }
}

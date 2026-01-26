<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Model;

readonly class SearchResult
{
    public function __construct(
        public int $totalResults,
        public array $results,
    ) {}

    public function isEmpty(): bool
    {
        return $this->totalResults === 0;
    }

    public function count(): int
    {
        return count($this->results);
    }
}

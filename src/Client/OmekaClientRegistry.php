<?php

declare(strict_types=1);

namespace Survos\OmekaBundle\Client;

use RuntimeException;
use function array_keys;
use function iterator_to_array;
use function implode;
use function sprintf;

final class OmekaClientRegistry
{
    /**
     * @param array<string, OmekaClient> $clients
     */
    public function __construct(iterable $clients)
    {
        $this->clients = iterator_to_array($clients);
    }

    public function get(string $name): OmekaClient
    {
        if (!isset($this->clients[$name])) {
            throw new RuntimeException(sprintf(
                'Unknown Omeka client "%s". Known clients: %s',
                $name,
                implode(', ', array_keys($this->clients)),
            ));
        }

        return $this->clients[$name];
    }
}

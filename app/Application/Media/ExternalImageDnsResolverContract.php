<?php

declare(strict_types=1);

namespace App\Application\Media;

interface ExternalImageDnsResolverContract
{
    /**
     * @return list<string> IPs (v4/v6) for the host.
     */
    public function resolve(string $host): array;
}

<?php

declare(strict_types=1);

namespace App\Application\Media;

final class SystemExternalImageDnsResolver implements ExternalImageDnsResolverContract
{
    public function resolve(string $host): array
    {
        $ips = [];

        foreach (dns_get_record($host, DNS_A) ?: [] as $record) {
            if (! empty($record['ip'])) {
                $ips[] = $record['ip'];
            }
        }

        foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        if (empty($ips)) {
            $legacy = gethostbynamel($host);
            if (is_array($legacy)) {
                foreach ($legacy as $ip) {
                    $ips[] = $ip;
                }
            }
        }

        return array_values(
            array_unique(
                array_filter($ips, fn ($ip) => is_string($ip) && $ip !== '')
            )
        );
    }
}

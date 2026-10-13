<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

final class HttpRequestBodyDecoder
{
    /** @param array<string, mixed> $options */
    public static function decode(array $options): mixed
    {
        $body = $options['body'];
        $encoding = $options['normalized_headers']['content-encoding'][0] ?? '';
        if ('content-encoding: zstd' === strtolower($encoding)) {
            $body = zstd_uncompress($body);
            if (!\is_string($body)) {
                throw new \RuntimeException('Unable to decompress the test HTTP request body.');
            }
        }

        return json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
    }
}

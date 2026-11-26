<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Symfony\AI\Platform\Test\Replay\CassetteHttpClient;
use Symfony\AI\Platform\Test\Replay\HttpCassette;

/** Selects one cassette per scenario; Symfony owns request verification and replay. */
final class ProviderCassetteHttpClientFactory
{
    public static function offline(string $scenario = 'no-model-requests'): CassetteHttpClient
    {
        return new CassetteHttpClient(new HttpCassette(__DIR__.'/../Fixtures/cassettes/'.$scenario.'.json'), record: false);
    }
}

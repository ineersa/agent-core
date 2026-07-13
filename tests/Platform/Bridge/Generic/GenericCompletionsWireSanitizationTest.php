<?php

declare(strict_types=1);

namespace Ineersa\Platform\Tests\Bridge\Generic;

use Ineersa\Platform\Bridge\Generic\SanitizedGenericModelClient;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Generic\Completions\ModelClient as GenericCompletionsModelClient;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Capability;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Regression: volatile Hatfield run_id must not be merged into generic provider wire JSON.
 * Without this, llama-proxy cache keys change per session even when prompts match.
 */
final class GenericCompletionsWireSanitizationTest extends TestCase
{
    public function testWireJsonOmitsRunIdWhenSanitizerWrapsGenericClient(): void
    {
        $capturedBody = null;
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
            $capturedBody = $options['body'] ?? null;

            return new MockResponse('{"choices":[]}');
        });

        $client = new SanitizedGenericModelClient(new GenericCompletionsModelClient(
            $httpClient,
            'http://127.0.0.1:9052',
            null,
            '/v1/chat/completions',
        ));

        $model = new CompletionsModel('test', [Capability::INPUT_TEXT]);
        $client->request($model, ['messages' => [['role' => 'user', 'content' => 'ping']]], [
            'run_id' => 'volatile-session-run-id',
            'tools_ref' => 'default',
            'turn_no' => 2,
            'temperature' => 0.0,
        ]);

        $this->assertIsString($capturedBody);
        $decoded = json_decode($capturedBody, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('run_id', $decoded);
        $this->assertArrayNotHasKey('tools_ref', $decoded);
        $this->assertArrayNotHasKey('turn_no', $decoded);
        $this->assertSame(0.0, $decoded['temperature']);
        $this->assertSame('ping', $decoded['messages'][0]['content']);
    }
}

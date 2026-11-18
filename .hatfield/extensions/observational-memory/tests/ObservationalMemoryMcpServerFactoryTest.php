<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use HelgeSverre\Toon\Toon;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Claude\ClaudeOmSettingsProvider;
use Ineersa\HatfieldExt\ObservationalMemory\Claude\ObservationalMemoryMcpServerFactory;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmTestCase;
use Ineersa\HatfieldExt\ObservationalMemory\Tool\OmToolRegistrationFactory;
use Mcp\Server\Transport\StdioTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ObservationalMemoryMcpServerFactoryTest extends OmTestCase
{
    public function testServesExactlyTheHatfieldRetrievalTools(): void
    {
        $this->project("observational_memory:\n            librarian: {url: 'http://librarian.test/_mcp'}");

        $responses = $this->exchange([
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            // Passes the schema, then the shared handler rejects it before any Librarian request.
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'memory_search', 'arguments' => ['query' => '   ']]],
        ]);

        $tools = array_column($responses[2]['result']['tools'], null, 'name');
        $this->assertSame(['memory_search', 'recall'], array_keys($tools));
        $query = $this->query($this->api());
        foreach ([OmToolRegistrationFactory::search($query), OmToolRegistrationFactory::recall($query)] as $registration) {
            $this->assertSame(json_encode($registration->parametersJsonSchema), json_encode($tools[$registration->name]['inputSchema']));
            $this->assertStringStartsWith($registration->description, $tools[$registration->name]['description']);
            $this->assertStringContainsString($registration->promptGuidelines[0], $tools[$registration->name]['description']);
        }
        // Rejected arguments are a tool error with the same TOON text Hatfield returns.
        $this->assertTrue($responses[3]['result']['isError']);
        $this->assertSame('invalid_query', Toon::decode($responses[3]['result']['content'][0]['text'])['error']);
    }

    /** @return iterable<string, array{?array<string, mixed>, bool, string}> */
    public static function librarianOutcomes(): iterable
    {
        yield 'results' => [['ok' => true, 'results' => []], false, ''];
        yield 'upstream tool error' => [['ok' => false, 'error' => 'project_unknown', 'message' => 'Unknown project.'], true, 'project_unknown'];
        yield 'librarian unavailable' => [null, true, 'librarian_unavailable'];
    }

    /** @param array<string, mixed>|null $searchResult null: every Librarian request fails */
    #[DataProvider('librarianOutcomes')]
    public function testToolErrorStatusFollowsTheSharedResult(?array $searchResult, bool $isError, string $error): void
    {
        $this->project("observational_memory:\n            librarian: {url: 'http://librarian.test/_mcp'}");
        $exec = $this->createStub(ExecInterface::class);
        $exec->method('exec')->willReturn(new ExecResultDTO($this->directory.'/project/.git'."\n".$this->directory.'/project/.git', '', 0));

        $responses = $this->exchange(
            [['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'memory_search', 'arguments' => ['query' => 'deploy']]]],
            exec: $exec,
            http: null === $searchResult ? new MockHttpClient(new MockResponse('', ['http_code' => 503])) : $this->librarian($searchResult),
        );

        $this->assertSame($isError, $responses[2]['result']['isError'] ?? false);
        $text = Toon::decode($responses[2]['result']['content'][0]['text']);
        $this->assertSame($isError ? $error : null, $text['error'] ?? null);
    }

    #[DataProvider('unavailableProjects')]
    public function testUnavailableMemoryExposesNoToolsAndExplainsWhy(?string $settings, string $startDirectory, string $instructions): void
    {
        $this->project($settings);
        if (!is_dir($this->directory.'/project/'.$startDirectory)) {
            mkdir($this->directory.'/project/'.$startDirectory);
        }

        $responses = $this->exchange([['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']], $this->directory.'/project/'.$startDirectory);

        $this->assertStringContainsString($instructions, $responses[1]['result']['instructions']);
        $this->assertSame([], $responses[2]['result']['tools']);
    }

    public static function unavailableProjects(): iterable
    {
        yield 'unconfigured' => [null, '.', 'observational_memory.librarian.url'];
        yield 'invalid settings' => ["observational_memory:\n            librarian: {url: 'ftp://librarian.test'}", '.', 'settings are invalid'];
        yield 'started in subdirectory' => ["observational_memory:\n            librarian: {url: 'http://librarian.test/_mcp'}", 'src', 'started below the Git checkout root'];
    }

    private function project(?string $extensionSettings): void
    {
        // A checkout root of its own; otherwise this repository's .git makes it a subdirectory.
        mkdir($this->directory.'/project/.git', 0o750, true);
        if (null !== $extensionSettings) {
            mkdir($this->directory.'/project/.hatfield');
            file_put_contents($this->directory.'/project/.hatfield/settings.yaml', "extensions:\n    settings:\n        ".$extensionSettings."\n");
        }
    }

    /**
     * @param list<array<string, mixed>> $requests
     *
     * @return array<int, array<string, mixed>> Responses keyed by request id
     */
    private function exchange(array $requests, ?string $projectDir = null, ?ExecInterface $exec = null, ?MockHttpClient $http = null): array
    {
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ...$requests,
        ];
        // Plain files, unlike php://memory, are selectable like a real stdin pipe.
        $inputFile = $this->directory.'/mcp-input.jsonl';
        file_put_contents($inputFile, implode("\n", array_map(static fn (array $message): string => json_encode($message, \JSON_THROW_ON_ERROR), $messages))."\n");
        $input = fopen($inputFile, 'rb');
        $outputFile = $this->directory.'/mcp-output.jsonl';
        $output = fopen($outputFile, 'wb');
        $this->assertIsResource($input);
        $this->assertIsResource($output);

        $http ??= new MockHttpClient(static function (): never {
            self::fail('No Librarian request expected.');
        });
        // No HOME layer: only the fixture project's settings are read.
        $factory = new ObservationalMemoryMcpServerFactory(new ClaudeOmSettingsProvider(null), $exec ?? $this->createStub(ExecInterface::class), new NullLogger(), $http);
        $factory->create($projectDir ?? $this->directory.'/project', 'test')->run(new StdioTransport($input, $output));

        // The transport closes both streams when input ends.
        $responses = [];
        foreach (explode("\n", trim((string) file_get_contents($outputFile))) as $line) {
            $message = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            if (isset($message['id'])) {
                $responses[$message['id']] = $message;
            }
        }

        return $responses;
    }

    /** A Librarian MCP endpoint whose memory_search returns $searchResult. */
    private function librarian(array $searchResult): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use ($searchResult): MockResponse {
            $body = $options['body'];
            $request = json_decode(\is_resource($body) ? stream_get_contents($body) : $body, true, flags: \JSON_THROW_ON_ERROR);
            if (!isset($request['id'])) {
                return new MockResponse('', ['http_code' => 202]);
            }
            if ('server/discover' === $request['method']) {
                return new MockResponse(json_encode(['jsonrpc' => '2.0', 'id' => $request['id'], 'error' => ['code' => -32601, 'message' => 'Method not found']], \JSON_THROW_ON_ERROR), ['response_headers' => ['Content-Type: application/json']]);
            }
            if ('initialize' === $request['method']) {
                $result = ['protocolVersion' => $request['params']['protocolVersion'], 'serverInfo' => ['name' => 'test-librarian', 'version' => '1.0'], 'capabilities' => ['tools' => new \stdClass()]];
            } else {
                $value = 'ensure_project' === $request['params']['name'] ? ['ok' => true] : $searchResult;
                $result = ['content' => [['type' => 'text', 'text' => json_encode($value, \JSON_THROW_ON_ERROR)]], 'structuredContent' => $value, 'isError' => false === $value['ok']];
            }

            return new MockResponse(json_encode(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result], \JSON_THROW_ON_ERROR), [
                'response_headers' => ['Content-Type: application/json', 'Mcp-Session-Id: test-session'],
            ]);
        });
    }
}

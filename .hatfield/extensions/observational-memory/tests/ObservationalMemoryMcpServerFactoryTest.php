<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use HelgeSverre\Toon\Toon;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Claude\ClaudeOmSettingsProvider;
use Ineersa\HatfieldExt\ObservationalMemory\Claude\ObservationalMemoryMcpServerFactory;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmTestCase;
use Ineersa\HatfieldExt\ObservationalMemory\Tool\OmToolRegistrationFactory;
use Mcp\Server\Transport\StdioTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

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
        $this->assertFalse($responses[3]['result']['isError'] ?? false);
        $this->assertSame('invalid_query', Toon::decode($responses[3]['result']['content'][0]['text'])['error']);
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
    private function exchange(array $requests, ?string $projectDir = null): array
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

        $noRequests = new MockHttpClient(static function (): never {
            self::fail('No Librarian request expected.');
        });
        // No HOME layer: only the fixture project's settings are read.
        $factory = new ObservationalMemoryMcpServerFactory(new ClaudeOmSettingsProvider(null), $this->createStub(ExecInterface::class), new NullLogger(), $noRequests);
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
}

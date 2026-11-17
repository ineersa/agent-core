<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\HatfieldExt\TaskWorkflow\Claude\TaskWorkflowMcpServerFactory;
use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\WorkspaceFixture;
use Mcp\Server\Transport\StdioTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TaskWorkflowMcpServerFactoryTest extends TestCase
{
    public function testServesRegisteredToolsAndCommandsOverStdioWithoutInstallingHatfieldSkill(): void
    {
        $workspace = new WorkspaceFixture();
        try {
            $this->writeSettings($workspace, "extensions:\n    settings:\n        task_workflow:\n            repository: acme/project\n");

            $responses = $this->exchange($workspace, [
                ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
                // tasks-init refuses before any GitHub call when hooks are missing.
                ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'run_command', 'arguments' => ['command' => 'tasks-init']]],
            ]);

            $this->assertStringContainsString('acme/project', $responses[1]['result']['instructions']);
            $tools = array_column($responses[2]['result']['tools'], null, 'name');
            $this->assertSame(['task_list', 'create_task', 'read_task', 'update_task', 'move_task', 'run_command'], array_keys($tools));
            $this->assertSame(['task', 'to'], $tools['move_task']['inputSchema']['required']);
            $this->assertStringContainsString('Never infer merge authorization.', $tools['move_task']['description']);
            $this->assertContains('tasks-init', $tools['run_command']['inputSchema']['properties']['command']['enum']);
            $this->assertTrue($responses[3]['result']['isError']);
            $this->assertStringContainsString('Configure a committed script for each project hook', $responses[3]['result']['content'][0]['text']);
            $this->assertDirectoryDoesNotExist($workspace->root.'/.hatfield/skills');
        } finally {
            $workspace->close();
        }
    }

    public function testUnconfiguredProjectExposesNoToolsAndPointsToSetup(): void
    {
        $workspace = new WorkspaceFixture();
        try {
            $responses = $this->exchange($workspace, [['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']]);

            $this->assertStringContainsString('not configured', $responses[1]['result']['instructions']);
            $this->assertSame([], $responses[2]['result']['tools']);
        } finally {
            $workspace->close();
        }
    }

    private function writeSettings(WorkspaceFixture $workspace, string $yaml): void
    {
        $this->assertSame($workspace->root, realpath($workspace->root));
        mkdir($workspace->root.'/.hatfield');
        file_put_contents($workspace->root.'/.hatfield/settings.yaml', $yaml);
    }

    /**
     * @param list<array<string, mixed>> $requests
     *
     * @return array<int, array<string, mixed>> Responses keyed by request id
     */
    private function exchange(WorkspaceFixture $workspace, array $requests): array
    {
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ...$requests,
        ];
        // Plain files, unlike php://memory, are selectable like a real stdin pipe.
        $inputFile = $workspace->directory.'/mcp-input.jsonl';
        file_put_contents($inputFile, implode("\n", array_map(static fn (array $message): string => json_encode($message, \JSON_THROW_ON_ERROR), $messages))."\n");
        $input = fopen($inputFile, 'rb');
        $outputFile = $workspace->directory.'/mcp-output.jsonl';
        $output = fopen($outputFile, 'wb');
        $this->assertIsResource($input);
        $this->assertIsResource($output);

        // The workspace directory stands in for HOME, so no user settings leak in.
        $server = (new TaskWorkflowMcpServerFactory(new NullLogger()))->create($workspace->root, $workspace->directory, 'test');
        $server->run(new StdioTransport($input, $output));

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

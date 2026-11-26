<?php

/**
 * Fixture MCP server for STDIO integration tests.
 *
 * Registers two simple tools ("echo" and "reverse") using the PHP MCP SDK
 * server builder.  Runs as a STDIO transport server.
 *
 * Usage:
 *   php tests/CodingAgent/Mcp/Fixtures/stdio-echo-server.php
 *
 * Not autoloaded — requires Composer autoload from the worktree root.
 */

declare(strict_types=1);

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Server;
use Mcp\Server\Transport\StdioTransport;
use Psr\Log\NullLogger;

// Load Composer autoload relative to this script's location within the worktree.
$autoloadPath = __DIR__.'/../../../../vendor/autoload.php';
require_once $autoloadPath;

$server = Server::builder()
    ->setServerInfo(
        name: 'hatfield-test-echo',
        version: '0.0.0',
    )
    ->setInstructions('Test echo server for MCP integration tests.')
    ->setLogger(new NullLogger())
    ->addTool(
        handler: static function (string $text): CallToolResult {
            $content = [new TextContent('echo: '.$text)];

            return match ($text) {
                'structured-object' => new CallToolResult($content, structuredContent: (object) ['data' => str_repeat('m', 1_100_000)]),
                'structured-list' => new CallToolResult($content, structuredContent: []),
                default => new CallToolResult($content),
            };
        },
        name: 'echo',
        title: 'Echo Tool',
        description: 'Returns the input text with an echo prefix.',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'text' => [
                    'type' => 'string',
                    'description' => 'Text to echo back',
                ],
            ],
            'required' => ['text'],
        ],
    )
    ->addTool(
        handler: static function (string $input): array {
            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => strrev($input),
                    ],
                ],
            ];
        },
        name: 'reverse',
        title: 'Reverse Tool',
        description: 'Reverses the input string.',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'input' => [
                    'type' => 'string',
                    'description' => 'String to reverse',
                ],
            ],
            'required' => ['input'],
        ],
    )
    ->build();

$transport = new StdioTransport();
$server->run($transport);

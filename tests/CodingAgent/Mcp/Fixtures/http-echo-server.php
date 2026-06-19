<?php

/**
 * Fixture MCP server for HTTP integration tests.
 *
 * A minimal stateless JSON-RPC handler that runs behind PHP's built-in
 * web server.  Each request is handled independently — the PHP built-in
 * server spawns a new process per request, so session state is not
 * preserved.  This is acceptable for discovery tests (initialize +
 * tools/list) because the SDK HttpTransport sends both requests in
 * the same POST body (JSON-RPC batch/bidi) or sequentially.
 *
 * Usage:
 *   php -S 127.0.0.1:<port> tests/CodingAgent/Mcp/Fixtures/http-echo-server.php
 *
 * Not autoloaded — requires Composer autoload from the worktree root.
 */

declare(strict_types=1);

// Load Composer autoload relative to this script's location within the worktree.
$autoloadPath = __DIR__ . '/../../../../vendor/autoload.php';
require_once $autoloadPath;

// Only respond to expected paths.
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, \PHP_URL_PATH) ?? '/';

if ('/health' === $path) {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    exit(0);
}

if ('/' !== $path && '/mcp' !== $path) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'not found']);
    exit(1);
}

// Read request body
$body = file_get_contents('php://input');
if (false === $body || '' === $body) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'Empty body'], 'id' => null]);
    exit(1);
}

try {
    $request = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
} catch (\JsonException) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'Parse error'], 'id' => null]);
    exit(1);
}

if (!\is_array($request)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Invalid Request'], 'id' => null]);
    exit(1);
}

$method = $request['method'] ?? '';
$rawId = $request['id'] ?? null;
// Keep original id type for response fidelity but normalize for PHP signatures
$id = null !== $rawId ? (string) $rawId : null;

try {
    if ('initialize' === $method) {
        $response = handleInitialize($rawId);
    } elseif ('tools/list' === $method) {
        $response = handleListTools($rawId);
    } elseif ('notifications/initialized' === $method) {
        // Acknowledge without response
        http_response_code(202);
        header('Content-Type: application/json');
        echo json_encode(['jsonrpc' => '2.0', 'id' => null, 'result' => (object) []]);
        exit(0);
    } else {
        $response = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => -32601, 'message' => 'Method not found: ' . $method],
        ];
    }
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32603, 'message' => $e->getMessage()], 'id' => $id]);
    exit(1);
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode($response);

/**
 * Handle MCP initialize.
 */
function handleInitialize(mixed $id): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => (object) [],
            ],
            'serverInfo' => [
                'name' => 'hatfield-test-http',
                'version' => '0.0.0',
            ],
            'instructions' => 'Test HTTP server for MCP integration tests.',
        ],
    ];
}

/**
 * Handle tools/list — returns two simple tools.
 */
function handleListTools(mixed $id): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => [
            'tools' => [
                [
                    'name' => 'hello',
                    'description' => 'Returns a greeting for the given name.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'description' => 'Name to greet',
                            ],
                        ],
                        'required' => ['name'],
                    ],
                ],
                [
                    'name' => 'add',
                    'description' => 'Adds two numbers together.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'a' => [
                                'type' => 'integer',
                                'description' => 'First number',
                            ],
                            'b' => [
                                'type' => 'integer',
                                'description' => 'Second number',
                            ],
                        ],
                        'required' => ['a', 'b'],
                    ],
                ],
            ],
        ],
    ];
}

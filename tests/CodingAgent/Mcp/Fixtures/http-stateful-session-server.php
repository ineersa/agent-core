<?php

/**
 * Stateful Streamable HTTP MCP fixture for session-expiry recovery tests.
 *
 * The built-in server process stays up, but request-scoped PHP state resets, so
 * session validity lives only in MCP_FIXTURE_STATE. Creating
 * MCP_FIXTURE_INVALIDATE makes the next session-bound request return HTTP 404
 * with the configured body shape. MCP_FIXTURE_HEADER_LOG records method,
 * inbound session header, and status. MCP_FIXTURE_RECEIVED marks when a slow
 * tools/call has been accepted.
 *
 * Post-initialize calls require a non-empty matching Mcp-Session-Id. A missing
 * session header returns HTTP 400 so a sessionless retry cannot false-pass.
 * When MCP_FIXTURE_EXPIRE_ON_CANCEL is set, notifications/cancelled clears the
 * session and returns HTTP 404.
 *
 * Usage:
 *   MCP_FIXTURE_STATE=... MCP_FIXTURE_INVALIDATE=... MCP_FIXTURE_HEADER_LOG=... \
 *   php -S 127.0.0.1:<port> tests/CodingAgent/Mcp/Fixtures/http-stateful-session-server.php
 */

declare(strict_types=1);

$stateFile = getenv('MCP_FIXTURE_STATE');
$invalidateFile = getenv('MCP_FIXTURE_INVALIDATE');
$headerLog = getenv('MCP_FIXTURE_HEADER_LOG');
$receivedFile = getenv('MCP_FIXTURE_RECEIVED');
$expireOnCancel = getenv('MCP_FIXTURE_EXPIRE_ON_CANCEL');
$expiredContentType = getenv('MCP_FIXTURE_404_CONTENT_TYPE');
$expiredBody = getenv('MCP_FIXTURE_404_BODY');
if (false === $stateFile || '' === $stateFile) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'MCP_FIXTURE_STATE is required';
    exit(1);
}

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, \PHP_URL_PATH) ?? '/';
$sessionHeader = $_SERVER['HTTP_MCP_SESSION_ID'] ?? '';

if ('/' !== $path && '/mcp' !== $path) {
    respond(404, 'application/json', json_encode(['error' => 'not found'], \JSON_THROW_ON_ERROR), $headerLog, 'unknown', $sessionHeader);

    exit(1);
}

$body = file_get_contents('php://input');
if (false === $body || '' === $body) {
    respond(400, 'application/json', json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'Empty body'], 'id' => null], \JSON_THROW_ON_ERROR), $headerLog, 'empty', $sessionHeader);

    exit(1);
}

try {
    $request = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
} catch (JsonException) {
    respond(400, 'application/json', json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'Parse error'], 'id' => null], \JSON_THROW_ON_ERROR), $headerLog, 'parse-error', $sessionHeader);

    exit(1);
}

if (!is_array($request)) {
    respond(400, 'application/json', json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32600, 'message' => 'Invalid Request'], 'id' => null], \JSON_THROW_ON_ERROR), $headerLog, 'invalid', $sessionHeader);

    exit(1);
}

$method = (string) ($request['method'] ?? '');
$rawId = $request['id'] ?? null;
$currentSession = is_file($stateFile) ? trim((string) file_get_contents($stateFile)) : '';

if ('' !== $sessionHeader
    && false !== $invalidateFile
    && '' !== $invalidateFile
    && is_file($invalidateFile)
) {
    @unlink($invalidateFile);
    file_put_contents($stateFile, '');
    $contentType = false === $expiredContentType || '' === $expiredContentType ? '' : $expiredContentType;
    $responseBody = false === $expiredBody ? '' : $expiredBody;
    respond(404, $contentType, $responseBody, $headerLog, $method, $sessionHeader);

    exit(0);
}

if ('initialize' !== $method && '' === $sessionHeader) {
    respond(400, 'text/plain', 'Missing Mcp-Session-Id', $headerLog, $method, $sessionHeader);

    exit(0);
}

if ('' !== $sessionHeader && ('' === $currentSession || $sessionHeader !== $currentSession)) {
    respond(404, 'text/plain', 'Unknown session', $headerLog, $method, $sessionHeader);

    exit(0);
}

try {
    if ('initialize' === $method) {
        $sessionId = 'session-'.bin2hex(random_bytes(8));
        file_put_contents($stateFile, $sessionId);
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => $rawId,
            'result' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [
                    'tools' => (object) [],
                ],
                'serverInfo' => [
                    'name' => 'hatfield-test-http-stateful',
                    'version' => '0.0.0',
                ],
            ],
        ], \JSON_THROW_ON_ERROR);
        respond(200, 'application/json', $payload, $headerLog, $method, $sessionHeader, $sessionId);

        exit(0);
    }

    if ('notifications/initialized' === $method) {
        respond(202, 'application/json', '', $headerLog, $method, $sessionHeader);

        exit(0);
    }

    if ('notifications/cancelled' === $method) {
        if (false !== $expireOnCancel && '' !== $expireOnCancel) {
            file_put_contents($stateFile, '');
            respond(404, 'text/plain', 'Session expired', $headerLog, $method, $sessionHeader);

            exit(0);
        }

        respond(204, '', '', $headerLog, $method, $sessionHeader);

        exit(0);
    }

    if ('tools/list' === $method) {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => $rawId,
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
                        'name' => 'slow',
                        'description' => 'Marks receipt and leaves the call pending until interrupted.',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => (object) [],
                            'required' => [],
                        ],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);
        respond(200, 'application/json', $payload, $headerLog, $method, $sessionHeader);

        exit(0);
    }

    if ('tools/call' === $method && 'hello' === ($request['params']['name'] ?? null)) {
        $name = (string) ($request['params']['arguments']['name'] ?? '');
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => $rawId,
            'result' => [
                'content' => [
                    ['type' => 'text', 'text' => 'Hello, '.$name],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);
        respond(200, 'application/json', $payload, $headerLog, $method, $sessionHeader);

        exit(0);
    }

    if ('tools/call' === $method && 'slow' === ($request['params']['name'] ?? null)) {
        if (false !== $receivedFile && '' !== $receivedFile) {
            file_put_contents($receivedFile, 'received');
        }

        // An SSE comment is not a result. Leave the SDK request pending without
        // blocking body reads or delaying the fixture to force interruption.
        respond(200, 'text/event-stream', ": pending\n\n", $headerLog, $method, $sessionHeader);

        exit(0);
    }

    $payload = json_encode([
        'jsonrpc' => '2.0',
        'id' => $rawId,
        'error' => ['code' => -32601, 'message' => 'Method not found: '.$method],
    ], \JSON_THROW_ON_ERROR);
    respond(200, 'application/json', $payload, $headerLog, $method, $sessionHeader);
} catch (Throwable $e) {
    respond(500, 'application/json', json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32603, 'message' => $e->getMessage()], 'id' => $rawId], \JSON_THROW_ON_ERROR), $headerLog, $method, $sessionHeader);

    exit(1);
}

function respond(int $status, string $contentType, string $body, string|false $headerLog, string $method, string $sessionHeader, ?string $responseSessionId = null): void
{
    http_response_code($status);
    if ('' !== $contentType) {
        header('Content-Type: '.$contentType);
    }
    if (null !== $responseSessionId) {
        header('Mcp-Session-Id: '.$responseSessionId);
    }

    if (false !== $headerLog && '' !== $headerLog) {
        file_put_contents(
            $headerLog,
            json_encode([
                'method' => $method,
                'session' => $sessionHeader,
                'status' => $status,
                'response_session' => $responseSessionId,
            ], \JSON_THROW_ON_ERROR).\PHP_EOL,
            \FILE_APPEND,
        );
    }

    echo $body;
}

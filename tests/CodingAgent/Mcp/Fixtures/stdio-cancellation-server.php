<?php

/** A raw MCP server that acknowledges cancellation while a tool call is pending. */

declare(strict_types=1);

$marker = getenv('MCP_FIXTURE_MARKER');
$pending = null;

while (false !== ($line = fgets(\STDIN))) {
    $request = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
    $method = $request['method'] ?? null;
    $id = $request['id'] ?? null;

    if ('initialize' === $method) {
        $result = [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'serverInfo' => ['name' => 'cancellation-fixture', 'version' => '1.0.0'],
        ];
    } elseif ('tools/list' === $method) {
        $result = ['tools' => [
            ['name' => 'slow', 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'fast', 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
        ]];
    } elseif ('tools/call' === $method && 'slow' === $request['params']['name']) {
        $pending = $id;
        file_put_contents($marker, 'received');
        continue;
    } elseif ('notifications/cancelled' === $method) {
        file_put_contents($marker, 'cancelled:'.$request['params']['requestId']);
        if ($request['params']['requestId'] !== $pending) {
            exit(1);
        }
        $id = $pending;
        $result = ['content' => [['type' => 'text', 'text' => 'late']]];
    } elseif ('tools/call' === $method && 'fast' === $request['params']['name']) {
        $result = ['content' => [['type' => 'text', 'text' => 'quick']]];
    } else {
        continue;
    }

    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], \JSON_THROW_ON_ERROR)."\n";
}

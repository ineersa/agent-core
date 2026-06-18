<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Catalog;

/**
 * Status of an MCP server during discovery / in the session catalog.
 */
enum McpServerCatalogStatusEnum: string
{
    /** Server connected successfully and tools were discovered. */
    case CONNECTED = 'connected';

    /** Server discovery failed — no tools available from this server. */
    case FAILED = 'failed';
}

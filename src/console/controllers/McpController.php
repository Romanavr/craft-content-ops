<?php

namespace romanavr\contentops\console\controllers;

use craft\console\Controller;
use Mcp\Server\Transport\StdioTransport;
use romanavr\contentops\mcp\ServerFactory;

/**
 * Runs the Content Ops MCP server.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class McpController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Serves MCP over stdio (JSON-RPC on stdin/stdout). Point an MCP client at `php craft content-ops/mcp`.
     * Nothing else may write to stdout while this runs.
     *
     * @return int
     */
    public function actionIndex(): int
    {
        return ServerFactory::create()->run(new StdioTransport());
    }
}

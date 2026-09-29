<?php

namespace romanavr\contentops\mcp;

use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use romanavr\contentops\ContentOps;

/**
 * Builds the Content Ops MCP server. Transport-agnostic: the console command runs it over stdio,
 * and an HTTP transport (with per-user tokens) can reuse the same server later.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class ServerFactory
{
    // Public Methods
    // =========================================================================

    /**
     * Creates a server with all Content Ops tools and resources registered.
     *
     * @return Server
     */
    public static function create(): Server
    {
        $projectTools = new ProjectTools();
        $contentTools = new ContentTools();
        $readOnly = new ToolAnnotations(readOnlyHint: true, destructiveHint: false, openWorldHint: false);

        return Server::builder()
            ->setServerInfo('Content Ops', ContentOps::getInstance()->getVersion())
            ->setInstructions(<<<MD
Content Ops exposes a Craft CMS 5 project. Call `get_project_schema` first to learn the sites, sections,
entry types and fields, then use `search_content` and `read_entry`. All tools are currently read-only.
MD)
            ->addTool(
                handler: [$projectTools, 'getProjectSchema'],
                name: 'get_project_schema',
                title: 'Get project schema',
                annotations: $readOnly,
            )
            ->addResource(
                handler: fn() => $projectTools->getProjectSchema(),
                uri: 'project://schema',
                name: 'project_schema',
                title: 'Project schema',
                description: 'Sites, sections, entry types, field layouts and fields of this Craft project.',
                mimeType: 'application/json',
            )
            ->addTool(
                handler: [$contentTools, 'searchContent'],
                name: 'search_content',
                title: 'Search content',
                annotations: $readOnly,
            )
            ->addTool(
                handler: [$contentTools, 'readEntry'],
                name: 'read_entry',
                title: 'Read entry',
                annotations: $readOnly,
            )
            ->build();
    }
}

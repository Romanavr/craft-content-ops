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
     * Creates a server with the tools the session's mode allows.
     *
     * @param McpContext|null $context Defaults to a propose-mode console session
     * @return Server
     */
    public static function create(?McpContext $context = null): Server
    {
        $context ??= new McpContext();
        $projectTools = new ProjectTools();
        $contentTools = new ContentTools();
        $changesetTools = new ChangesetTools($context);
        $readOnly = new ToolAnnotations(readOnlyHint: true, destructiveHint: false, openWorldHint: false);
        $proposes = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false);
        $writes = new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false);

        $builder = Server::builder()
            ->setServerInfo('Content Ops', ContentOps::getInstance()->getVersion())
            ->setInstructions(self::instructions($context))
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
            ->addTool(handler: [$changesetTools, 'getEditOptions'], name: 'get_edit_options', title: 'Get edit options', annotations: $readOnly)
            ->addTool(handler: [$changesetTools, 'getChangeset'], name: 'get_changeset', title: 'Get changeset', annotations: $readOnly)
            ->addTool(handler: [$changesetTools, 'listChangesets'], name: 'list_changesets', title: 'List changesets', annotations: $readOnly);

        if ($context->canPropose()) {
            $builder
                ->addTool(handler: [$changesetTools, 'proposeBulkEdit'], name: 'propose_bulk_edit', title: 'Propose a bulk edit', annotations: $proposes)
                ->addTool(handler: [$changesetTools, 'proposeFindReplace'], name: 'propose_find_replace', title: 'Propose a find & replace', annotations: $proposes);
        }

        if ($context->canApply()) {
            $builder
                ->addTool(handler: [$changesetTools, 'applyChangeset'], name: 'apply_changeset', title: 'Apply a changeset', annotations: $writes)
                ->addTool(handler: [$changesetTools, 'undoChangeset'], name: 'undo_changeset', title: 'Undo a changeset', annotations: $writes);
        }

        return $builder->build();
    }

    /**
     * @param McpContext $context
     * @return string
     */
    public static function instructions(McpContext $context): string
    {
        $base = <<<MD
Content Ops exposes a Craft CMS 5 project. Call `get_project_schema` first to learn the sites, sections,
entry types and fields; use `search_content` and `read_entry` to read content.
MD;

        return $base . "\n\n" . match ($context->mode) {
            McpContext::MODE_READONLY => 'This session is read-only.',
            McpContext::MODE_PROPOSE => <<<MD
To change content, propose a changeset: `get_edit_options` shows what can be edited, then `propose_bulk_edit`
or `propose_find_replace` creates a preview without saving anything. Show the user the sample changes and give
them the review link: a person reviews and applies every change in the Craft control panel, and can undo it later.
MD,
            default => <<<MD
To change content, propose a changeset (`get_edit_options`, then `propose_bulk_edit` or `propose_find_replace`),
show the user the sample changes, and call `apply_changeset` only after they confirm. Every change can be undone
with `undo_changeset`.
MD,
        };
    }
}

# Content Ops

Bulk edit and find & replace for Craft CMS, with preview and undo.

## Requirements

This plugin requires Craft CMS 5.11.0 or later, and PHP 8.2 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for “Content Ops”. Then press “Install”.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require romanavr/craft-content-ops

# tell Craft to install the plugin
./craft plugin/install content-ops
```

## MCP server (AI access)

Content Ops ships an [MCP](https://modelcontextprotocol.io) server so AI clients (Claude Code, Claude Desktop, Cursor…) can
understand and read your content. It currently runs over stdio from the Craft console:

```bash
php craft content-ops/mcp
```

Example client config (`.mcp.json`):

```json
{
  "mcpServers": {
    "content-ops": { "command": "php", "args": ["/path/to/my-project/craft", "content-ops/mcp"] }
  }
}
```

| Type | Name | Purpose |
|---|---|---|
| tool | `get_project_schema` | Sites, sections, entry types, field layouts and fields |
| resource | `project://schema` | Same as above, as a resource |
| tool | `search_content` | Search entries by section, type, site, status and Craft search query |
| tool | `read_entry` | One entry with native attributes and all field values (incl. nested entries) |

All tools are read-only for now. Proposing, applying and undoing changesets via MCP is planned.

## Development

Development happens against the DDEV sandbox in `../sandbox` (see the project's `PLAN.md`). From the sandbox directory:

```bash
ddev pest                                          # tests (plugin/tests), run against the sandbox's `test` database
ddev exec -d /var/www/plugin composer check-cs     # ECS
ddev exec -d /var/www/plugin composer phpstan      # PHPStan (level 5)
```

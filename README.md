# Content Ops

Bulk edit and find & replace for Craft CMS, with preview and undo.

Change hundreds of entries in minutes, see every change before it happens, and undo it if you got it wrong.

## How it works

Every change goes through the same four steps:

1. **Select**: entries in an index (the selection, or everything matching the current search/filters), or everything a Find & Replace search finds.
2. **Preview**: every before → after value, per entry, site and field. Nothing is saved yet.
3. **Apply**: saved in the background as a queue job, with a normal entry revision per save.
4. **Undo**: from **Content Ops → History**, any time.

Each run is stored as a **changeset**: a log of every old and new value. That log is what makes previews exact, conflicts detectable and undo possible.

### Bulk edit

On any entry index, click **Bulk edit** in the footer (or select entries and use **Bulk edit…** in the actions menu):

- **Scope**: the selected entries, or *all entries matching the current view* (every page, including your search and filters).
- **Sites**: which sites to change.
- **Changes**: pick a field, an operation and a value; stack several. Fields that only some entries have show "applies to N of M".
- **Preview** shows a before/after table (and warns about entries with drafts). **Apply** runs in the background and refreshes the list when done.

| Field type | Operations |
|---|---|
| Title, Plain Text, CKEditor | set, clear, prepend, append, find & replace, set from a pattern (`{title} – {section.name}`) |
| Slug | the same; patterns are turned into valid slugs |
| Number | set, clear, ± value, ± % (empty values stay empty) |
| Lightswitch, Enabled, Enabled for site | on, off, toggle |
| Dropdown, Radio buttons / Checkboxes, Multi-select | set, clear / set, add, remove, clear |
| Date, Post Date, Expiry Date | set, clear, shift by ± days (keeps the local time across DST) |
| Entries, Categories, Assets, Tags, Users, Authors | replace, add, remove, clear |
| Matrix | add a nested entry; remove nested entries of a type (optionally only if their text contains X) |
| Fields inside Matrix (`Matrix › Type › Field`) | all of the above, applied to each nested entry of that type |

### Find & Replace

**Content Ops → Find & Replace** searches titles, Plain Text and CKEditor fields (optionally inside Matrix nested entries too) across chosen sections and sites.

- Case-sensitive, whole words, and regular expressions (`$1` in replacements; runaway patterns are stopped safely).
- In CKEditor fields only **visible text** is searched: HTML tags, reference tags (`{entry:12:url}`) and embedded entries are never touched. Optionally also replace inside `href`/`src` (e.g. moving `http://old.test` to `https://new.test`).
- Searches run in the background; you then review **every match in context** and untick the ones to keep before applying.

### History & undo

- **Undo** restores the original values. Values someone edited *after* the changeset ran are left alone ("Edited since"); **Force undo** overwrites them.
- Relations and Matrix undo only what the changeset did (removed nested entries come back with their original IDs and positions).
- If a value changed between preview and apply, that change is skipped as a **conflict**, so you never overwrite something you didn't see.
- Values shared between sites (untranslated fields, Matrix fields propagating to all sites) are changed **once**, never once per site.
- History is kept for 90 days by default (configurable); unapplied previews are removed after a day.

### Permissions

| Permission | Allows |
|---|---|
| Bulk edit elements | the Bulk edit button/action; entries the user can't save are skipped and reported |
| Find and replace | the Find & Replace page |
| Undo changesets | undo / force undo |
| View changeset history | everyone's changesets (otherwise only one's own) |

### Console

```bash
php craft content-ops/bulk-edit --section=news --field=title --op=append --value=" (2026)" [--site=*] [--apply]
php craft content-ops/find-replace --find="http://old.test" --replace="https://new.test" --links [--apply]
php craft content-ops/changesets                # list
php craft content-ops/changesets/view 12        # details
php craft content-ops/changesets/apply 12
php craft content-ops/changesets/undo 12 [--force]
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

All tools are read-only for now. AI-proposed changesets (reviewed and approved in the CP, undoable like any other) are planned.

## Requirements

Craft CMS 5.11.0 or later, PHP 8.2 or later.

## Installation

From the Plugin Store: search for “Content Ops” and press “Install”. Or with Composer:

```bash
composer require romanavr/craft-content-ops
php craft plugin/install content-ops
```

## Development

Development happens against the DDEV sandbox in `../sandbox`. From the sandbox directory:

```bash
ddev pest                                          # tests (plugin/tests), run against the sandbox's `test` database
ddev exec -d /var/www/plugin composer check-cs     # ECS
ddev exec -d /var/www/plugin composer phpstan      # PHPStan (level 5)
```

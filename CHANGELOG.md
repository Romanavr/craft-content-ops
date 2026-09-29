# Release Notes for Content Ops

## 1.0.0-beta.3 - 2026-09-30

### Changed
- Find & Replace searches all sections and all sites by default (“All sections” / “All sites” are checked); uncheck them to pick specific ones. Choosing nothing is now an error instead of meaning “everything”.
- New sidebar icon.
- While “All sections” / “All sites” is checked, the individual items are shown greyed out.
- The Bulk edit window checks all sites by default; unchecking every site shows an error instead of falling back to the current site.

### Fixed
- The Find & Replace form keeps what you typed when a search can’t start.

## 1.0.0-beta.2 - 2026-09-30

### Added
- AI access over MCP (Pro): AI tools propose bulk edits and find & replace as changesets; people review and apply them in the CP, and can undo them.
- HTTP MCP endpoint with per-user access tokens (read only / propose / propose and apply), managed under Content Ops → AI Access.
- New MCP tools: `get_edit_options`, `propose_bulk_edit`, `propose_find_replace`, `get_changeset`, `list_changesets`, `apply_changeset`, `undo_changeset`; new resource `project://context`.
- History marks AI proposals and can filter those awaiting review; the Content Ops menu shows how many are waiting.
- Settings: AI context notes, max items per AI changeset.
- “Use AI access (MCP tokens)” permission.
- Content Ops → Settings in the sidebar for admins (shown inactive where `allowAdminChanges` is off).

### Changed
- New plugin icon and a matching sidebar icon.
- Settings are grouped into Saving changes, History, AI access and Advanced, with shorter instructions.
- Shorter labels and text across Find & Replace, History and AI Access.

### Upgrading
- Run `php craft up` (adds the `contentops_tokens` table).

## 1.0.0-beta.1 - 2026-09-30

### Added
- Bulk edit for entries: “Bulk edit” button on every entry index (selected entries, or all entries matching the current view) and a “Bulk edit…” element action.
- Operators for Plain Text, CKEditor, Number, Lightswitch, Dropdown, Radio Buttons, Checkboxes, Multi-select, Date, relation fields (Entries, Categories, Assets, Tags, Users) and native attributes (title, slug, enabled, enabled for site, post/expiry date, authors).
- Matrix support (Pro): edit fields inside nested entries; add and remove nested entries.
- Find & Replace (plain text in Lite; regex, link/image URLs, nested entries and per-match exclusion in Pro), HTML-aware for CKEditor fields.
- Preview of every change before it's applied; changes are applied by a background job.
- Changeset history with undo (most recent changeset in Lite, any changeset in Pro), conflict detection, force undo and surgical undo for relations and Matrix.
- Multi-site aware: values shared between sites are changed once.
- Console commands: `content-ops/bulk-edit`, `content-ops/find-replace`, `content-ops/changesets`.
- Read-only MCP server (`content-ops/mcp`) with `get_project_schema`, `search_content` and `read_entry`.
- Permissions: bulk edit, find and replace, undo, view history.

# Release Notes for Content Ops

## Unreleased

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

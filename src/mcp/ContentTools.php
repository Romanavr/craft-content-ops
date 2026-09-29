<?php

namespace romanavr\contentops\mcp;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\Site;
use Mcp\Exception\ToolCallException;

/**
 * MCP tools for finding and reading content. Read-only: nothing here writes to the database.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class ContentTools
{
    // Const Properties
    // =========================================================================

    /**
     * @var int Upper bound on results per search call, to keep responses small enough for an AI context window.
     */
    public const MAX_LIMIT = 100;

    // Public Methods
    // =========================================================================

    /**
     * Searches entries. Returns a page of lightweight summaries plus the total match count.
     * Nested entries (Matrix blocks) are excluded unless `includeNested` is true.
     *
     * @param string|null $section Section handle, e.g. "news". Omit for all sections.
     * @param string|null $type Entry type handle.
     * @param string|null $site Site handle. Defaults to the primary site.
     * @param string|null $query Craft search query, e.g. "acme", "title:acme*", "body:\"old-domain.test\"".
     * @param string|null $status "live", "pending", "expired", "disabled", or omit for any status.
     * @param bool $includeNested Include nested entries (owned by Matrix/CKEditor fields).
     * @param int $limit Page size (max 100).
     * @param int $offset Number of results to skip.
     * @param string $orderBy SQL-style order, e.g. "dateUpdated DESC" or "title ASC".
     * @return array<string, mixed>
     * @throws ToolCallException if the site handle is invalid
     */
    public function searchContent(
        ?string $section = null,
        ?string $type = null,
        ?string $site = null,
        ?string $query = null,
        ?string $status = null,
        bool $includeNested = false,
        int $limit = 20,
        int $offset = 0,
        string $orderBy = 'dateUpdated DESC',
    ): array {
        $siteModel = $this->_resolveSite($site);

        $entryQuery = Entry::find()
            ->siteId($siteModel->id)
            ->status($status)
            ->orderBy($orderBy);

        if ($section !== null) {
            $entryQuery->section($section);
        } elseif (!$includeNested) {
            $entryQuery->section('*');
        }

        if ($type !== null) {
            $entryQuery->type($type);
        }

        if ($query !== null && $query !== '') {
            $entryQuery->search($query);
        }

        $total = (int)$entryQuery->count();
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $results = [];

        foreach ($entryQuery->limit($limit)->offset(max(0, $offset))->all() as $entry) {
            $results[] = $this->_summarize($entry);
        }

        return [
            'site' => $siteModel->handle,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'results' => $results,
        ];
    }

    /**
     * Reads one entry with all native attributes and custom field values (serialized as Craft stores them).
     * Matrix fields include their nested entries.
     *
     * @param int $id Entry ID.
     * @param string|null $site Site handle. Defaults to the primary site; see `availableSites` in the result for others.
     * @return array<string, mixed>
     * @throws ToolCallException if the site handle is invalid or the entry doesn't exist in that site
     */
    public function readEntry(int $id, ?string $site = null): array
    {
        $siteModel = $this->_resolveSite($site);

        // Element IDs are 32-bit; out-of-range input would be a database error on Postgres.
        if ($id < 1 || $id > 2147483647) {
            throw new ToolCallException("Entry $id doesn't exist.");
        }

        $entry = Entry::find()
            ->id($id)
            ->siteId($siteModel->id)
            ->status(null)
            ->one();

        if ($entry === null) {
            throw new ToolCallException("Entry $id doesn't exist in site \"$siteModel->handle\".");
        }

        $sites = Craft::$app->getSites();
        $fields = [];

        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $fields[$field->handle] = $field->serializeValue($entry->getFieldValue($field->handle), $entry);
        }

        return $this->_summarize($entry) + [
            'enabledGlobally' => $entry->enabled,
            'availableSites' => array_values(array_filter(array_map(
                fn($siteId) => $sites->getSiteById((int)$siteId)?->handle,
                (new Query())->select('siteId')->from(Table::ELEMENTS_SITES)->where(['elementId' => $entry->id])->column(),
            ))),
            'ownerId' => $entry->getOwnerId(),
            'fieldId' => $entry->fieldId,
            'parentId' => $entry->getParentId(),
            'level' => $entry->level,
            'authors' => array_map(fn(User $user) => $user->username, $entry->getAuthors()),
            'expiryDate' => $entry->expiryDate?->format(DATE_ATOM),
            'dateCreated' => $entry->dateCreated?->format(DATE_ATOM),
            'draftCount' => (int)Entry::find()->draftOf($entry)->siteId($siteModel->id)->status(null)->count(),
            'fields' => $fields,
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * @param string|null $handle
     * @return Site
     * @throws ToolCallException
     */
    private function _resolveSite(?string $handle): Site
    {
        $sites = Craft::$app->getSites();

        if ($handle === null) {
            return $sites->getPrimarySite();
        }

        $site = $sites->getSiteByHandle($handle);

        if ($site === null) {
            $valid = implode(', ', array_map(fn(Site $site) => $site->handle, $sites->getAllSites()));
            throw new ToolCallException("Unknown site \"$handle\". Valid handles: $valid.");
        }

        return $site;
    }

    /**
     * @param Entry $entry
     * @return array<string, mixed>
     */
    private function _summarize(Entry $entry): array
    {
        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'section' => $entry->getSection()?->handle,
            'type' => $entry->getType()->handle,
            'site' => $entry->getSite()->handle,
            'status' => $entry->getStatus(),
            'uri' => $entry->uri,
            'url' => $entry->getUrl(),
            'postDate' => $entry->postDate?->format(DATE_ATOM),
            'dateUpdated' => $entry->dateUpdated?->format(DATE_ATOM),
        ];
    }
}

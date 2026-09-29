<?php

namespace romanavr\contentops\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use romanavr\contentops\db\Table;

/**
 * Creates the changeset log tables.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class Install extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::CHANGES);
        $this->dropTableIfExists(Table::CHANGESETS);

        return true;
    }

    // Protected Methods
    // =========================================================================

    /**
     * Creates the tables.
     */
    protected function createTables(): void
    {
        $this->archiveTableIfExists(Table::CHANGESETS);
        $this->createTable(Table::CHANGESETS, [
            'id' => $this->primaryKey(),
            'type' => $this->string(32)->notNull(),
            'status' => $this->string(32)->notNull(),
            'userId' => $this->integer(),
            'selection' => $this->text()->notNull(),
            'operations' => $this->text()->notNull(),
            'options' => $this->text(),
            'counts' => $this->text(),
            'error' => $this->text(),
            'dateApplied' => $this->dateTime(),
            'dateUndone' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Values are stored as JSON-encoded strings in mediumText rather than a JSON column:
        // MySQL/Postgres JSON types reorder keys, and undo/conflict checks need exact round-trips.
        $this->archiveTableIfExists(Table::CHANGES);
        $this->createTable(Table::CHANGES, [
            'id' => $this->primaryKey(),
            'changesetId' => $this->integer()->notNull(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'target' => $this->string()->notNull(),
            'oldValue' => $this->mediumText(),
            'newValue' => $this->mediumText(),
            'status' => $this->string(32)->notNull(),
            'error' => $this->text(),
            'elementDateUpdated' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    /**
     * Creates the indexes.
     */
    protected function createIndexes(): void
    {
        $this->createIndex(null, Table::CHANGESETS, ['status']);
        $this->createIndex(null, Table::CHANGESETS, ['dateCreated']);
        $this->createIndex(null, Table::CHANGES, ['changesetId', 'status']);
        $this->createIndex(null, Table::CHANGES, ['elementId', 'siteId']);
    }

    /**
     * Adds the foreign keys.
     */
    protected function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::CHANGESETS, ['userId'], CraftTable::USERS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::CHANGES, ['changesetId'], Table::CHANGESETS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::CHANGES, ['elementId'], CraftTable::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::CHANGES, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE');
    }
}

<?php

namespace romanavr\contentops\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use romanavr\contentops\db\Table;

/**
 * Adds the MCP access tokens table.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class m260930_000000_add_tokens extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(Table::TOKENS)) {
            $this->createTokensTable($this);
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::TOKENS);

        return true;
    }

    /**
     * Creates the tokens table (shared with the install migration).
     *
     * @param Migration $migration
     */
    public function createTokensTable(Migration $migration): void
    {
        // Only a SHA-256 hash of each token is stored; the token itself is shown once, when it's created.
        $migration->createTable(Table::TOKENS, [
            'id' => $migration->primaryKey(),
            'name' => $migration->string()->notNull(),
            'userId' => $migration->integer()->notNull(),
            'tokenHash' => $migration->char(64)->notNull(),
            'tokenPrefix' => $migration->string(12)->notNull(),
            'mode' => $migration->string(16)->notNull(),
            'dateLastUsed' => $migration->dateTime(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);
        $migration->createIndex(null, Table::TOKENS, ['tokenHash'], true);
        $migration->addForeignKey(null, Table::TOKENS, ['userId'], CraftTable::USERS, ['id'], 'CASCADE');
    }
}

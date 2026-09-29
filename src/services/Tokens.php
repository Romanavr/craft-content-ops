<?php

namespace romanavr\contentops\services;

use Craft;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use romanavr\contentops\mcp\McpContext;
use romanavr\contentops\records\Token;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Per-user access tokens for the HTTP MCP endpoint.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class Tokens extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var string Prefix that makes Content Ops tokens recognisable (e.g. by secret scanners).
     */
    public const PREFIX = 'co_';

    // Public Methods
    // =========================================================================

    /**
     * Creates a token. Returns `[Token record, plain token]`; the plain token can't be retrieved again.
     *
     * @param string $name
     * @param User $user
     * @param string $mode
     * @return array{0: Token, 1: string}
     * @throws InvalidArgumentException
     */
    public function create(string $name, User $user, string $mode): array
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Give the token a name.');
        }

        if (!in_array($mode, [McpContext::MODE_READONLY, McpContext::MODE_PROPOSE, McpContext::MODE_FULL], true)) {
            throw new InvalidArgumentException('Unknown mode.');
        }

        $plain = self::PREFIX . StringHelper::randomString(40);
        $record = new Token();
        $record->name = trim($name);
        $record->userId = $user->id;
        $record->tokenHash = hash('sha256', $plain);
        $record->tokenPrefix = substr($plain, 0, 10);
        $record->mode = $mode;
        $record->save(false);

        return [$record, $plain];
    }

    /**
     * Finds the active token for a plain token string (and marks it as used).
     *
     * @param string $plain
     * @return Token|null
     */
    public function authenticate(string $plain): ?Token
    {
        if (!str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        /** @var Token|null $record */
        $record = Token::findOne(['tokenHash' => hash('sha256', $plain)]);

        if ($record === null) {
            return null;
        }

        $record->dateLastUsed = Db::prepareDateForDb(new DateTime());
        $record->save(false);

        return $record;
    }

    /**
     * Returns tokens, newest first (only the given user's, if set).
     *
     * @param int|null $userId
     * @return Token[]
     */
    public function all(?int $userId = null): array
    {
        $query = Token::find()->orderBy(['id' => SORT_DESC]);

        if ($userId !== null) {
            $query->where(['userId' => $userId]);
        }

        /** @var Token[] */
        return $query->all();
    }

    /**
     * Revokes (deletes) a token.
     *
     * @param int $id
     * @param User $by Only the token's owner or an admin may revoke it
     * @return bool
     */
    public function revoke(int $id, User $by): bool
    {
        $record = Token::findOne($id);

        if ($record === null || ($record->userId !== $by->id && !$by->admin)) {
            return false;
        }

        return (bool)$record->delete();
    }

    /**
     * Returns the user a token acts as.
     *
     * @param Token $token
     * @return User|null
     */
    public function user(Token $token): ?User
    {
        return Craft::$app->getUsers()->getUserById($token->userId);
    }
}

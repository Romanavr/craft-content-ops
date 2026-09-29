<?php

namespace romanavr\contentops\operators;

use Carbon\Carbon;
use Craft;
use craft\base\ElementInterface;
use craft\fields\Date;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use romanavr\contentops\models\Operation;
use romanavr\contentops\models\Target;
use yii\base\InvalidArgumentException;

/**
 * Date operator: Date fields and the `postDate`/`expiryDate` attributes.
 *
 * Values are UTC `Y-m-d H:i:s` strings (as Craft stores them); Date fields with time zones store
 * `['date' => …, 'tz' => …]`, and the time zone is kept.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class DateOperator extends BaseOperator
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function handle(): string
    {
        return 'date';
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Date';
    }

    /**
     * @inheritdoc
     */
    public function getOperations(): array
    {
        return [
            'set' => 'Set to',
            'clear' => 'Clear',
            'shift' => 'Shift by days',
        ];
    }

    /**
     * @inheritdoc
     */
    public function getInputs(string $operation, Target $target): array
    {
        return match ($operation) {
            'set' => [['name' => 'value', 'type' => 'datetime', 'label' => 'Date']],
            'shift' => [['name' => 'days', 'type' => 'number', 'label' => 'Days (negative to move back)']],
            default => [],
        };
    }

    /**
     * @inheritdoc
     */
    public function supports(Target $target): bool
    {
        return $target->field instanceof Date || in_array($target->attribute, ['postDate', 'expiryDate'], true);
    }

    /**
     * @inheritdoc
     */
    public function validateOperation(Operation $operation): void
    {
        parent::validateOperation($operation);

        if ($operation->operation === 'set' && DateTimeHelper::toDateTime($this->requireOption($operation, 'value')) === false) {
            throw new InvalidArgumentException('The "value" option must be a date, e.g. "2026-12-31 09:00" (in the system time zone) or ISO 8601.');
        }

        if ($operation->operation === 'shift' && !is_numeric($this->requireOption($operation, 'days'))) {
            throw new InvalidArgumentException('The "days" option must be a number.');
        }
    }

    /**
     * @inheritdoc
     */
    public function apply(mixed $value, Operation $operation, ?ElementInterface $element = null): mixed
    {
        $timeZone = is_array($value) ? ($value['tz'] ?? null) : null;
        $current = is_array($value) ? ($value['date'] ?? null) : $value;

        $new = match ($operation->operation) {
            // Input like "2026-12-31 09:00" is entered in the system time zone, like the CP date pickers.
            'set' => Db::prepareDateForDb(DateTimeHelper::toDateTime($operation->options['value'], assumeSystemTimeZone: true)),
            'clear' => null,
            // Shift in local time so wall-clock times survive DST changes (09:00 stays 09:00).
            'shift' => $current === null ? null : Db::prepareDateForDb(
                Carbon::parse($current, 'UTC')
                    ->setTimezone($timeZone ?? Craft::$app->getTimeZone())
                    ->addDays((int)$operation->options['days'])
                    ->setTimezone('UTC')
            ),
            default => throw new InvalidArgumentException("Unknown operation \"$operation->operation\"."),
        };

        if ($new === $current) {
            return $value;
        }

        if ($new !== null && $timeZone !== null) {
            return ['date' => $new, 'tz' => $timeZone];
        }

        return $new === null ? null : (is_array($value) ? ['date' => $new] : $new);
    }
}

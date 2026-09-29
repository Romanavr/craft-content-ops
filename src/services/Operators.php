<?php

namespace romanavr\contentops\services;

use craft\events\RegisterComponentTypesEvent;
use romanavr\contentops\models\Target;
use romanavr\contentops\operators\DateOperator;
use romanavr\contentops\operators\LightswitchOperator;
use romanavr\contentops\operators\MatrixOperator;
use romanavr\contentops\operators\NumberOperator;
use romanavr\contentops\operators\OperatorInterface;
use romanavr\contentops\operators\OptionsOperator;
use romanavr\contentops\operators\RelationOperator;
use romanavr\contentops\operators\TextOperator;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Registry of operators.
 *
 * @author Romanavr
 * @since 1.0.0
 */
class Operators extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @event RegisterComponentTypesEvent Lets plugins register operators (e.g. for third-party field types).
     *
     * ```php
     * Event::on(Operators::class, Operators::EVENT_REGISTER_OPERATORS, function(RegisterComponentTypesEvent $event) {
     *     $event->types[] = MyFieldOperator::class;
     * });
     * ```
     */
    public const EVENT_REGISTER_OPERATORS = 'registerOperators';

    // Private Properties
    // =========================================================================

    /**
     * @var array<string, OperatorInterface>|null Operators keyed by handle
     */
    private ?array $_operators = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns all registered operators, keyed by handle.
     *
     * @return array<string, OperatorInterface>
     */
    public function getAllOperators(): array
    {
        if ($this->_operators !== null) {
            return $this->_operators;
        }

        $event = new RegisterComponentTypesEvent([
            'types' => [
                TextOperator::class,
                NumberOperator::class,
                LightswitchOperator::class,
                OptionsOperator::class,
                DateOperator::class,
                RelationOperator::class,
                MatrixOperator::class,
            ],
        ]);
        $this->trigger(self::EVENT_REGISTER_OPERATORS, $event);

        $this->_operators = [];

        foreach ($event->types as $class) {
            /** @var class-string<OperatorInterface> $class */
            $this->_operators[$class::handle()] = new $class();
        }

        return $this->_operators;
    }

    /**
     * Returns an operator by its handle.
     *
     * @param string $handle
     * @return OperatorInterface
     * @throws InvalidArgumentException if no operator has that handle
     */
    public function getOperator(string $handle): OperatorInterface
    {
        $operators = $this->getAllOperators();

        if (!isset($operators[$handle])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown operator "%s". Valid: %s.',
                $handle,
                implode(', ', array_keys($operators)),
            ));
        }

        return $operators[$handle];
    }

    /**
     * Returns the operators that can edit a target.
     *
     * @param Target $target
     * @return OperatorInterface[]
     */
    public function getOperatorsForTarget(Target $target): array
    {
        return array_values(array_filter($this->getAllOperators(), fn(OperatorInterface $operator) => $operator->supports($target)));
    }
}

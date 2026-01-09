<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Domain\Search\Enums\ComparisonOperator;
use Pollora\MeiliScout\Domain\Search\Validators\EnumValidator;

/**
 * Builder for date-related query filters.
 * 
 * Handles the conversion of WordPress date_query parameters to MeiliSearch filter syntax.
 */
class DateQueryBuilder extends AbstractFilterBuilder
{
    /**
     * {@inheritdoc}
     */
    protected function getQueryKey(): string
    {
        return 'date_query';
    }

    /**
     * {@inheritdoc}
     * 
     * Builds a filter expression for a date query clause.
     */
    protected function buildSingleFilter(array $query): string
    {
        if (empty($query['column']) || ! isset($query['value'])) {
            return '';
        }

        $column = $query['column'];
        if (str_contains($column, '.') || str_ends_with($column, '_timestamp')) {
            $key = $column;
        } else {
            $key = "date.{$column}";
        }

        $value = is_array($query['value']) ? $this->formatArrayValues($query['value']) : $this->formatValue($query['value']);

        /** @var ComparisonOperator $operator */
        $operator = EnumValidator::getValidValueOrDefault(
            ComparisonOperator::class,
            $query['compare'] ?? ComparisonOperator::getDefault()->value,
            ComparisonOperator::getDefault()
        );

        // Check if the operator is allowed for date queries
        if (! in_array($operator, ComparisonOperator::getDateOperators(), true)) {
            return '';
        }

        return match ($operator) {
            ComparisonOperator::EQUALS,
            ComparisonOperator::NOT_EQUALS,
            ComparisonOperator::GREATER_THAN,
            ComparisonOperator::GREATER_THAN_OR_EQUALS,
            ComparisonOperator::LESS_THAN,
            ComparisonOperator::LESS_THAN_OR_EQUALS => "$key {$operator->value} $value",

            ComparisonOperator::IN,
            ComparisonOperator::NOT_IN => is_array($query['value']) ? "$key {$operator->value} [$value]" : '',

            ComparisonOperator::BETWEEN => is_array($query['value']) && count($query['value']) === 2
                ? "($key >= {$this->formatValue($query['value'][0])} AND $key <= {$this->formatValue($query['value'][1])})"
                : '',
                
            ComparisonOperator::NOT_BETWEEN => is_array($query['value']) && count($query['value']) === 2
                ? "($key < {$this->formatValue($query['value'][0])} OR $key > {$this->formatValue($query['value'][1])})"
                : '',

            default => '',
        };
    }
}

<?php

declare(strict_types=1);

namespace Belluga\Settings\Support\Conditions;

enum ConditionOperator: string
{
    case EQUALS = 'equals';
    case NOT_EQUALS = 'not_equals';
    case IN = 'in';
    case NOT_IN = 'not_in';
    case EXISTS = 'exists';
    case GT = 'gt';
    case GTE = 'gte';
    case LT = 'lt';
    case LTE = 'lte';

    public function expectsArrayValue(): bool
    {
        return in_array($this, [self::IN, self::NOT_IN], true);
    }

    public function expectsComparableValue(): bool
    {
        return in_array($this, [self::GT, self::GTE, self::LT, self::LTE], true);
    }
}


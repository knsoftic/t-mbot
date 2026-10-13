<?php

namespace App\Enums;

enum FailureBehavior: string
{
    use Concerns;

    case RetryThenContinue = 'retry_then_continue';
    case StopOnFailure = 'stop_on_failure';
    case Continue = 'continue';
    case RetryUntilSuccess = 'retry_until_success';

    public function label(): string
    {
        return match ($this) {
            self::RetryThenContinue => 'Retry, then continue',
            self::StopOnFailure => 'Stop campaign on failure',
            self::Continue => 'Continue after failure (no retry)',
            self::RetryUntilSuccess => 'Retry until successful',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::RetryThenContinue => 'Temporary errors are retried up to the maximum; then the item is marked failed and the campaign moves on.',
            self::StopOnFailure => 'Temporary errors are retried up to the maximum; if the item still fails the campaign is stopped.',
            self::Continue => 'No retries. A failed item is marked failed and the campaign moves on.',
            self::RetryUntilSuccess => 'The item is retried with growing delays until it succeeds or you skip it.',
        };
    }
}

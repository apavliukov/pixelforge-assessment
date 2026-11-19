<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Codes for failures before the provider accepted a generation. Once accepted, a generation's
 * error code is the provider's own, which is an open set and is stored as received.
 */
enum FailureCode: string
{
    /** The provider rejected the prompt at submission. */
    case ContentPolicyViolation = 'content_policy_violation';

    /** Still not accepted by the provider after the wait limit. */
    case WaitTimeout = 'wait_timeout';

    /** The provider kept answering 5xx or could not be reached. */
    case ProviderUnavailable = 'provider_unavailable';

    /** The provider's daily generation limit for the whole app is used up. */
    case DailyLimitExceeded = 'daily_limit_exceeded';

    /** The provider rejected the request for a reason that is a defect of this app. */
    case SubmissionRejected = 'submission_rejected';
}

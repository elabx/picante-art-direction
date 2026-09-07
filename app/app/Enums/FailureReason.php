<?php

namespace App\Enums;

enum FailureReason: string
{
    case SubmissionUnknown = 'submission_unknown';
    case PollTimeout = 'poll_timeout';
    case ProviderFailed = 'provider_failed';
    case InvalidResult = 'invalid_result';
    case DeliveryDimensions = 'delivery_dimensions';
    case DownloadFailed = 'download_failed';
}

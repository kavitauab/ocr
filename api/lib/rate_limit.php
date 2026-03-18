<?php

/**
 * Check if a company is within its rate limits.
 *
 * @param string $companyId
 * @return array ['allowed' => bool, 'reason' => string, 'limits' => [...]]
 */
function checkRateLimit($companyId) {
    $db = getDBConnection();

    // Get company rate limits from subscriptions
    $hourlyLimit = null;
    $dailyLimit = null;

    try {
        $stmt = $db->prepare("SELECT rate_limit_per_hour, rate_limit_per_day FROM subscriptions WHERE company_id = :companyId");
        $stmt->execute(['companyId' => $companyId]);
        $sub = $stmt->fetch();

        if ($sub) {
            $hourlyLimit = $sub['rate_limit_per_hour'] !== null ? (int)$sub['rate_limit_per_hour'] : null;
            $dailyLimit = $sub['rate_limit_per_day'] !== null ? (int)$sub['rate_limit_per_day'] : null;
        }
    } catch (\Throwable $e) {
        // If subscriptions table doesn't have these columns yet, no limits apply
        return ['allowed' => true, 'reason' => '', 'limits' => ['hourly' => null, 'daily' => null]];
    }

    // No limits configured = unlimited
    if ($hourlyLimit === null && $dailyLimit === null) {
        return [
            'allowed' => true,
            'reason' => '',
            'limits' => [
                'hourlyLimit' => null,
                'dailyLimit' => null,
                'hourlyUsed' => 0,
                'dailyUsed' => 0,
            ],
        ];
    }

    // Count invoices uploaded in the last hour and today
    $hourlyUsed = 0;
    $dailyUsed = 0;

    try {
        if ($hourlyLimit !== null) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM invoices WHERE company_id = :companyId AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $stmt->execute(['companyId' => $companyId]);
            $hourlyUsed = (int)$stmt->fetchColumn();
        }

        if ($dailyLimit !== null) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM invoices WHERE company_id = :companyId AND created_at >= CURDATE()");
            $stmt->execute(['companyId' => $companyId]);
            $dailyUsed = (int)$stmt->fetchColumn();
        }
    } catch (\Throwable $e) {
        // On error, allow the request
        return ['allowed' => true, 'reason' => '', 'limits' => ['hourlyLimit' => $hourlyLimit, 'dailyLimit' => $dailyLimit]];
    }

    $limits = [
        'hourlyLimit' => $hourlyLimit,
        'dailyLimit' => $dailyLimit,
        'hourlyUsed' => $hourlyUsed,
        'dailyUsed' => $dailyUsed,
        'hourlyRemaining' => $hourlyLimit !== null ? max(0, $hourlyLimit - $hourlyUsed) : null,
        'dailyRemaining' => $dailyLimit !== null ? max(0, $dailyLimit - $dailyUsed) : null,
    ];

    if ($hourlyLimit !== null && $hourlyUsed >= $hourlyLimit) {
        return [
            'allowed' => false,
            'reason' => "Hourly rate limit exceeded ($hourlyUsed/$hourlyLimit invoices per hour)",
            'limits' => $limits,
            'retryAfterSeconds' => 3600,
        ];
    }

    if ($dailyLimit !== null && $dailyUsed >= $dailyLimit) {
        return [
            'allowed' => false,
            'reason' => "Daily rate limit exceeded ($dailyUsed/$dailyLimit invoices per day)",
            'limits' => $limits,
            'retryAfterSeconds' => max(1, strtotime('tomorrow') - time()),
        ];
    }

    return [
        'allowed' => true,
        'reason' => '',
        'limits' => $limits,
    ];
}

/**
 * Send rate limit headers on the response.
 */
function sendRateLimitHeaders($limits) {
    if (isset($limits['hourlyRemaining']) && $limits['hourlyRemaining'] !== null) {
        header('X-RateLimit-Limit-Hour: ' . $limits['hourlyLimit']);
        header('X-RateLimit-Remaining-Hour: ' . $limits['hourlyRemaining']);
    }
    if (isset($limits['dailyRemaining']) && $limits['dailyRemaining'] !== null) {
        header('X-RateLimit-Limit-Day: ' . $limits['dailyLimit']);
        header('X-RateLimit-Remaining-Day: ' . $limits['dailyRemaining']);
    }
}

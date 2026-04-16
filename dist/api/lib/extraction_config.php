<?php
/**
 * Shared helpers for company-level extraction configuration.
 * Used by both the CLI cron queue worker and the email processor.
 *
 * This keeps the two extraction paths in lockstep — a change here applies to
 * both "new email arrives → inline extract" and "queued invoice → cron pickup".
 */

require_once __DIR__ . '/claude.php';

/**
 * Load the enabled-fields allow-list for a company.
 * Returns null when the company has no preference set (=> extract everything).
 * Returns a validated array when the company opted out of specific fields.
 *
 * Unknown/typoed field names in extraction_fields are filtered out — they
 * silently caused data loss before because extraction stripped the field
 * even though the user never disabled it intentionally.
 */
function loadCompanyExtractionFields(PDO $db, string $companyId): ?array {
    $stmt = $db->prepare("SELECT extraction_fields FROM companies WHERE id = :id");
    $stmt->execute(['id' => $companyId]);
    $row = $stmt->fetch();
    if (!$row || empty($row['extraction_fields'])) {
        return null;
    }

    $raw = $row['extraction_fields'];
    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($decoded) || empty($decoded)) {
        return null;
    }

    $allowed = array_keys(getAllExtractionFields());
    $validated = array_values(array_intersect($decoded, $allowed));
    if (empty($validated)) {
        // DB had some entries but none valid — treat as "all fields" rather
        // than stripping everything (safer default).
        return null;
    }
    return $validated;
}

/**
 * Remove fields the company has disabled from the extracted payload.
 * Mutates a copy and returns it. Safe to call when $enabledFields is null/empty
 * (returns the payload unchanged).
 */
function stripDisabledExtractionFields(array $extracted, ?array $enabledFields): array {
    if ($enabledFields === null || empty($enabledFields)) {
        return $extracted;
    }
    $allFieldKeys = array_keys(getAllExtractionFields());
    foreach ($allFieldKeys as $fk) {
        if (in_array($fk, $enabledFields, true)) continue;
        unset($extracted[$fk]);
        if (isset($extracted['confidence'][$fk])) {
            unset($extracted['confidence'][$fk]);
        }
    }
    return $extracted;
}

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

/**
 * Detect and auto-correct buyer ↔ vendor swap.
 *
 * Some invoice layouts (logo on the buyer side, issuer details on the right;
 * unusual party ordering; reverse-charge documents) confuse the model into
 * flipping who's the seller and who's the customer. We detect this by checking
 * the company's known VAT number and buyer keywords against the extracted
 * fields:
 *   - If extracted buyer matches the company → all good, no swap.
 *   - If extracted vendor matches the company AND extracted buyer does NOT →
 *     the model swapped them; flip name/address/VAT pairs.
 *
 * Returns true when a swap was applied. Mutates the extracted array in place.
 */
function detectAndSwapBuyerVendor(array &$extracted, array $company): bool {
    $normalizeVat = function ($v) {
        return strtoupper(preg_replace('/\s+/', '', (string)($v ?? '')));
    };
    $companyVat = $normalizeVat($company['vat_number'] ?? '');
    $kwRaw = trim((string)($company['buyer_keywords'] ?? ''));
    $keywords = [];
    if ($kwRaw !== '') {
        foreach (explode(',', $kwRaw) as $k) {
            $k = strtolower(trim($k));
            if ($k !== '') $keywords[] = $k;
        }
    }

    $buyerName  = strtolower((string)($extracted['buyerName'] ?? ''));
    $buyerVat   = $normalizeVat($extracted['buyerVatId'] ?? '');
    $vendorName = strtolower((string)($extracted['vendorName'] ?? ''));
    $vendorVat  = $normalizeVat($extracted['vendorVatId'] ?? '');

    $matchesCompany = function ($name, $vat) use ($companyVat, $keywords) {
        if ($companyVat !== '' && $vat !== '' && $vat === $companyVat) return true;
        if (!empty($keywords) && $name !== '') {
            foreach ($keywords as $k) {
                if (strpos($name, $k) !== false) return true;
            }
        }
        return false;
    };

    $buyerOk  = $matchesCompany($buyerName, $buyerVat);
    $vendorOk = $matchesCompany($vendorName, $vendorVat);

    if (!$buyerOk && $vendorOk) {
        // Swap: vendor → buyer, buyer → vendor.
        $tmpName  = $extracted['vendorName']    ?? null;
        $tmpAddr  = $extracted['vendorAddress'] ?? null;
        $tmpVat   = $extracted['vendorVatId']   ?? null;

        $extracted['vendorName']    = $extracted['buyerName']    ?? null;
        $extracted['vendorAddress'] = $extracted['buyerAddress'] ?? null;
        $extracted['vendorVatId']   = $extracted['buyerVatId']   ?? null;

        $extracted['buyerName']    = $tmpName;
        $extracted['buyerAddress'] = $tmpAddr;
        $extracted['buyerVatId']   = $tmpVat;

        // Confidence map (per-field) — swap the confidence pairs too so the UI
        // shows the right per-field colour after the correction.
        if (isset($extracted['confidence']) && is_array($extracted['confidence'])) {
            foreach (['Name', 'Address', 'VatId'] as $suffix) {
                $vKey = 'vendor' . $suffix;
                $bKey = 'buyer'  . $suffix;
                $vConf = $extracted['confidence'][$vKey] ?? null;
                $bConf = $extracted['confidence'][$bKey] ?? null;
                $extracted['confidence'][$vKey] = $bConf;
                $extracted['confidence'][$bKey] = $vConf;
            }
        }

        return true;
    }

    return false;
}

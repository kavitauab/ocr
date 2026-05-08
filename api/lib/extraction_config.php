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
 * Returns true when the given party (name + VAT + company code) matches the
 * company. Any one of the three identifiers is sufficient — name keyword
 * (substring), VAT number (case-insensitive exact), or registration code
 * (alphanumeric exact). Designed to be the SINGLE source of truth for
 * "is this party us?" across swap detection, buyer-mismatch validation, and
 * any future identity check.
 */
function partyMatchesCompany(?string $name, ?string $vat, ?string $code, array $company): bool {
    $normalizeVat  = static fn($v) => strtoupper(preg_replace('/\s+/', '', (string)($v ?? '')));
    $normalizeCode = static fn($v) => preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($v ?? '')));

    $companyVat  = $normalizeVat($company['vat_number'] ?? '');
    $companyCode = $normalizeCode($company['code'] ?? '');
    $partyVat    = $normalizeVat($vat);
    $partyCode   = $normalizeCode($code);
    $partyName   = strtolower((string)($name ?? ''));

    if ($companyVat  !== '' && $partyVat  !== '' && $partyVat  === $companyVat)  return true;
    if ($companyCode !== '' && $partyCode !== '' && $partyCode === $companyCode) return true;

    $kwRaw = trim((string)($company['buyer_keywords'] ?? ''));
    if ($kwRaw !== '' && $partyName !== '') {
        foreach (explode(',', $kwRaw) as $k) {
            $k = strtolower(trim($k));
            if ($k !== '' && strpos($partyName, $k) !== false) return true;
        }
    }
    return false;
}

/**
 * Detect and auto-correct buyer ↔ vendor swap.
 *
 * Some invoice layouts (logo on the buyer side, issuer details on the right;
 * unusual party ordering; reverse-charge documents) confuse the model into
 * flipping who's the seller and who's the customer. We detect this by checking
 * the company's known identifiers against the extracted party fields:
 *   - companies.vat_number   → matched against {vendor,buyer}VatId
 *   - companies.code         → matched against {vendor,buyer}CompanyCode
 *                              (Lithuanian "Įmonės kodas" — distinct from VAT)
 *   - companies.buyer_keywords → matched against {vendor,buyer}Name
 *
 * Logic:
 *   - If extracted buyer matches the company → all good, no swap.
 *   - If extracted vendor matches the company AND extracted buyer does NOT →
 *     the model swapped them; flip name/address/VAT/company-code pairs.
 *
 * Returns true when a swap was applied. Mutates the extracted array in place.
 */
function detectAndSwapBuyerVendor(array &$extracted, array $company): bool {
    $buyerOk  = partyMatchesCompany(
        $extracted['buyerName']        ?? null,
        $extracted['buyerVatId']       ?? null,
        $extracted['buyerCompanyCode'] ?? null,
        $company
    );
    $vendorOk = partyMatchesCompany(
        $extracted['vendorName']        ?? null,
        $extracted['vendorVatId']       ?? null,
        $extracted['vendorCompanyCode'] ?? null,
        $company
    );

    if (!$buyerOk && $vendorOk) {
        // Swap: vendor → buyer, buyer → vendor.
        $tmpName  = $extracted['vendorName']        ?? null;
        $tmpAddr  = $extracted['vendorAddress']     ?? null;
        $tmpVat   = $extracted['vendorVatId']       ?? null;
        $tmpCode  = $extracted['vendorCompanyCode'] ?? null;

        $extracted['vendorName']        = $extracted['buyerName']        ?? null;
        $extracted['vendorAddress']     = $extracted['buyerAddress']     ?? null;
        $extracted['vendorVatId']       = $extracted['buyerVatId']       ?? null;
        $extracted['vendorCompanyCode'] = $extracted['buyerCompanyCode'] ?? null;

        $extracted['buyerName']        = $tmpName;
        $extracted['buyerAddress']     = $tmpAddr;
        $extracted['buyerVatId']       = $tmpVat;
        $extracted['buyerCompanyCode'] = $tmpCode;

        // Confidence map (per-field) — swap the confidence pairs too so the UI
        // shows the right per-field colour after the correction.
        if (isset($extracted['confidence']) && is_array($extracted['confidence'])) {
            foreach (['Name', 'Address', 'VatId', 'CompanyCode'] as $suffix) {
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

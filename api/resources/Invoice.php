<?php
require_once __DIR__ . '/../lib/file_storage.php';
require_once __DIR__ . '/../lib/claude.php';
require_once __DIR__ . '/../lib/usage.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/vecticum.php';

class Invoice extends BaseResource {
    protected $tableName = 'invoices';
    protected $searchColumns = ['invoice_number', 'vendor_name', 'buyer_name', 'original_filename'];
    protected $allowedOrderColumns = ['id', 'created_at', 'updated_at', 'invoice_date', 'vendor_name', 'total_amount', 'status', 'ocr_sent_at', 'ocr_returned_at'];

    private function formatInvoice($row) {
        if (!$row) return $row;
        if (isset($row['confidence_scores'])) $row['confidence_scores'] = json_decode($row['confidence_scores'], true);
        if (isset($row['raw_extraction'])) $row['raw_extraction'] = json_decode($row['raw_extraction'], true);
        // Convert snake_case to camelCase for frontend
        $result = [];
        foreach ($row as $key => $value) {
            $camelKey = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
            $result[$camelKey] = $value;
        }
        return $result;
    }

    private $allowedTypes = ['application/pdf', 'image/png', 'image/jpeg'];
    private $contentTypes = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];

    private function csvSafeValue($value) {
        if ($value === null) return '';
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $value = (string)$value;
        // Prevent spreadsheet formula injection when opening CSV files.
        if ($value !== '' && preg_match('/^[=\-+@]/', $value)) {
            return "'" . $value;
        }
        return $value;
    }

    public function list() {
        $user = getAuthUser();
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? '';
        $companyId = $_GET['companyId'] ?? '';
        $lifecycle = $_GET['lifecycle'] ?? '';
        $sentFrom = $_GET['sentFrom'] ?? '';
        $sentTo = $_GET['sentTo'] ?? '';
        $returnedFrom = $_GET['returnedFrom'] ?? '';
        $returnedTo = $_GET['returnedTo'] ?? '';
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(100, max(1, intval($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $conditions = [];
        $params = [];

        if ($companyId) {
            requireCompanyAccess($user, $companyId);
            $conditions[] = "i.company_id = :companyId";
            $params['companyId'] = $companyId;
        } elseif ($user['role'] !== 'superadmin') {
            $companies = getUserCompanies($user);
            $ids = array_column($companies, 'id');
            if (empty($ids)) {
                sendJSON(['invoices' => [], 'total' => 0, 'page' => $page, 'totalPages' => 0]);
            }
            $placeholders = [];
            foreach ($ids as $idx => $cid) {
                $placeholders[] = ":cid$idx";
                $params["cid$idx"] = $cid;
            }
            $conditions[] = "i.company_id IN (" . implode(',', $placeholders) . ")";
        }

        if ($search) {
            $conditions[] = "(i.invoice_number LIKE :search OR i.vendor_name LIKE :search2 OR i.buyer_name LIKE :search3 OR i.original_filename LIKE :search4)";
            $params['search'] = "%$search%";
            $params['search2'] = "%$search%";
            $params['search3'] = "%$search%";
            $params['search4'] = "%$search%";
        }

        if ($status) {
            $conditions[] = "i.status = :status";
            $params['status'] = $status;
        }

        if ($lifecycle === 'sent') {
            $conditions[] = "i.ocr_sent_at IS NOT NULL";
        } elseif ($lifecycle === 'not-sent') {
            $conditions[] = "i.ocr_sent_at IS NULL";
        } elseif ($lifecycle === 'returned') {
            $conditions[] = "i.ocr_returned_at IS NOT NULL";
        } elseif ($lifecycle === 'pending-return') {
            $conditions[] = "i.ocr_sent_at IS NOT NULL AND i.ocr_returned_at IS NULL";
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sentFrom)) {
            $conditions[] = "i.ocr_sent_at >= :sentFrom";
            $params['sentFrom'] = $sentFrom . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sentTo)) {
            $conditions[] = "i.ocr_sent_at <= :sentTo";
            $params['sentTo'] = $sentTo . ' 23:59:59';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnedFrom)) {
            $conditions[] = "i.ocr_returned_at >= :returnedFrom";
            $params['returnedFrom'] = $returnedFrom . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnedTo)) {
            $conditions[] = "i.ocr_returned_at <= :returnedTo";
            $params['returnedTo'] = $returnedTo . ' 23:59:59';
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $countSql = "SELECT COUNT(*) FROM invoices i $where";
        $stmt = $this->db->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $sql = "SELECT i.*, c.name as company_name, c.code as company_code FROM invoices i LEFT JOIN companies c ON c.id = i.company_id $where ORDER BY i.created_at DESC LIMIT $limit OFFSET $offset";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        $formatted = array_map([$this, 'formatInvoice'], $items);

        sendJSON(['invoices' => $formatted, 'total' => $total, 'page' => $page, 'totalPages' => max(1, (int)ceil($total / $limit))]);
    }

    public function create() {
        set_time_limit(120); // Claude API can take up to 60s for extraction
        $user = getAuthUser();
        $companyId = $_POST['companyId'] ?? '';

        if (empty($_FILES['file'])) sendJSON(['error' => 'No file provided'], 400);
        if (!$companyId) sendJSON(['error' => 'Company is required'], 400);

        requireCompanyAccess($user, $companyId, 'manager');

        $file = $_FILES['file'];
        if (!in_array($file['type'], $this->allowedTypes)) {
            sendJSON(['error' => 'Invalid file type. Accepted: PDF, PNG, JPG'], 400);
        }

        $fileData = file_get_contents($file['tmp_name']);
        $saved = saveFile($fileData, $file['name'], $companyId);
        $id = generateId();

        $stmt = $this->db->prepare("INSERT INTO invoices (id, company_id, source, original_filename, stored_filename, file_type, file_size, status) VALUES (:id, :companyId, 'upload', :originalFilename, :storedFilename, :fileType, :fileSize, 'processing')");
        $stmt->execute([
            'id' => $id, 'companyId' => $companyId,
            'originalFilename' => $file['name'],
            'storedFilename' => $saved['storedFilename'],
            'fileType' => $saved['fileType'],
            'fileSize' => $file['size'],
        ]);

        // Extract with Claude
        $ocrJobId = null;
        $ocrRequestStarted = false;
        $ocrUsage = ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514'];
        try {
            // Load company extraction field preferences
            $companyStmt = $this->db->prepare("SELECT extraction_fields FROM companies WHERE id = :id");
            $companyStmt->execute(['id' => $companyId]);
            $companyRow = $companyStmt->fetch();
            $enabledFields = null;
            if ($companyRow && $companyRow['extraction_fields']) {
                $enabledFields = json_decode($companyRow['extraction_fields'], true);
            }

            $filePath = getFilePath($saved['storedFilename']);
            $ocrJobId = startOcrJob($id, $companyId, 'anthropic', 'claude-sonnet-4-20250514');
            try {
                $this->db->prepare("UPDATE invoices SET ocr_sent_at = NOW(), updated_at = NOW() WHERE id = :id")
                    ->execute(['id' => $id]);
            } catch (\Throwable $e) {
                // Allow processing to continue on DBs not yet migrated with ocr_sent_at.
            }

            $ocrRequestStarted = true;
            $extractionResult = extractInvoiceData($filePath, $saved['fileType'], $enabledFields, true);
            $extracted = $extractionResult['data'] ?? $extractionResult;
            if (isset($extractionResult['usage']) && is_array($extractionResult['usage'])) {
                $ocrUsage = $extractionResult['usage'];
            }

            $invoiceUpdateParams = [
                'documentType' => $extracted['documentType'] ?? null,
                'invoiceNumber' => $extracted['invoiceNumber'] ?? null,
                'invoiceDate' => $extracted['invoiceDate'] ?? null,
                'dueDate' => $extracted['dueDate'] ?? null,
                'vendorName' => $extracted['vendorName'] ?? null,
                'vendorAddress' => $extracted['vendorAddress'] ?? null,
                'vendorVatId' => $extracted['vendorVatId'] ?? null,
                'buyerName' => $extracted['buyerName'] ?? null,
                'buyerAddress' => $extracted['buyerAddress'] ?? null,
                'buyerVatId' => $extracted['buyerVatId'] ?? null,
                'totalAmount' => isset($extracted['totalAmount']) ? (string)$extracted['totalAmount'] : null,
                'currency' => $extracted['currency'] ?? null,
                'taxAmount' => isset($extracted['taxAmount']) ? (string)$extracted['taxAmount'] : null,
                'subtotalAmount' => isset($extracted['subtotalAmount']) ? (string)$extracted['subtotalAmount'] : null,
                'poNumber' => $extracted['poNumber'] ?? null,
                'paymentTerms' => $extracted['paymentTerms'] ?? null,
                'bankDetails' => $extracted['bankDetails'] ?? null,
                'confidence' => json_encode($extracted['confidence'] ?? []),
                'raw' => json_encode($extracted),
                'id' => $id,
            ];

            try {
                $stmt = $this->db->prepare("UPDATE invoices SET status = 'completed',
                    document_type = :documentType,
                    invoice_number = :invoiceNumber, invoice_date = :invoiceDate, due_date = :dueDate,
                    vendor_name = :vendorName, vendor_address = :vendorAddress, vendor_vat_id = :vendorVatId,
                    buyer_name = :buyerName, buyer_address = :buyerAddress, buyer_vat_id = :buyerVatId,
                    total_amount = :totalAmount, currency = :currency, tax_amount = :taxAmount,
                    subtotal_amount = :subtotalAmount, po_number = :poNumber, payment_terms = :paymentTerms,
                    bank_details = :bankDetails, confidence_scores = :confidence, raw_extraction = :raw,
                    ocr_returned_at = NOW(), updated_at = NOW() WHERE id = :id");
                $stmt->execute($invoiceUpdateParams);
            } catch (\Throwable $e) {
                // Fallback for DBs that do not have ocr_returned_at yet.
                $stmt = $this->db->prepare("UPDATE invoices SET status = 'completed',
                    document_type = :documentType,
                    invoice_number = :invoiceNumber, invoice_date = :invoiceDate, due_date = :dueDate,
                    vendor_name = :vendorName, vendor_address = :vendorAddress, vendor_vat_id = :vendorVatId,
                    buyer_name = :buyerName, buyer_address = :buyerAddress, buyer_vat_id = :buyerVatId,
                    total_amount = :totalAmount, currency = :currency, tax_amount = :taxAmount,
                    subtotal_amount = :subtotalAmount, po_number = :poNumber, payment_terms = :paymentTerms,
                    bank_details = :bankDetails, confidence_scores = :confidence, raw_extraction = :raw,
                    updated_at = NOW() WHERE id = :id");
                $stmt->execute($invoiceUpdateParams);
            }

            completeOcrJob($ocrJobId, $ocrUsage);
            trackInvoiceProcessed($companyId, $file['size'], $ocrUsage);
        } catch (\Throwable $e) {
            try {
                try {
                    $this->db->prepare("UPDATE invoices
                        SET status = 'failed',
                            processing_error = :error,
                            ocr_returned_at = CASE WHEN ocr_sent_at IS NULL THEN ocr_returned_at ELSE NOW() END,
                            updated_at = NOW()
                        WHERE id = :id")
                        ->execute(['error' => $e->getMessage(), 'id' => $id]);
                } catch (\Throwable $e3) {
                    $this->db->prepare("UPDATE invoices SET status = 'failed', processing_error = :error, updated_at = NOW() WHERE id = :id")
                        ->execute(['error' => $e->getMessage(), 'id' => $id]);
                }

                if ($ocrRequestStarted) {
                    failOcrJob($ocrJobId, $e->getMessage(), $ocrUsage);
                    trackApiCall($companyId, $ocrUsage ?? ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514']);
                }
            } catch (\Throwable $e2) {
                error_log("Invoice $id catch failed: " . $e2->getMessage());
            }
        }

        try {
            logAction(['userId' => $user['id'], 'companyId' => $companyId, 'action' => 'upload', 'resourceType' => 'invoice', 'resourceId' => $id]);
        } catch (\Throwable $e) {
            // non-critical
        }

        $stmt = $this->db->prepare("SELECT * FROM invoices WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $invoice = $stmt->fetch();

        sendJSON(['invoice' => $this->formatInvoice($invoice)], 201);
    }

    public function get($id) {
        if ($id === 'export') {
            $this->export();
            return;
        }

        $user = getAuthUser();
        $stmt = $this->db->prepare("SELECT * FROM invoices WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $invoice = $stmt->fetch();
        if (!$invoice) sendJSON(['error' => 'Invoice not found'], 404);

        if ($invoice['company_id']) requireCompanyAccess($user, $invoice['company_id']);

        sendJSON(['invoice' => $this->formatInvoice($invoice)]);
    }

    public function export($id = null) {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendJSON(['error' => 'Method not allowed'], 405);

        $user = getAuthUser();
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? '';
        $companyId = $_GET['companyId'] ?? '';
        $lifecycle = $_GET['lifecycle'] ?? '';
        $sentFrom = $_GET['sentFrom'] ?? '';
        $sentTo = $_GET['sentTo'] ?? '';
        $returnedFrom = $_GET['returnedFrom'] ?? '';
        $returnedTo = $_GET['returnedTo'] ?? '';

        $conditions = [];
        $params = [];

        if ($companyId) {
            requireCompanyAccess($user, $companyId);
            $conditions[] = "i.company_id = :companyId";
            $params['companyId'] = $companyId;
        } elseif ($user['role'] !== 'superadmin') {
            $companies = getUserCompanies($user);
            $ids = array_column($companies, 'id');
            if (empty($ids)) {
                $filename = 'invoices-export-' . date('Ymd-His') . '.csv';
                header('Content-Type: text/csv; charset=UTF-8');
                header("Content-Disposition: attachment; filename=\"$filename\"");
                header('Pragma: no-cache');
                header('Expires: 0');
                $out = fopen('php://output', 'w');
                fputcsv($out, [
                    'company', 'source', 'status', 'document type', 'createdAt', 'ocrSentAt', 'ocrReturnedAt', 'processingSeconds',
                    'invoiceNumber', 'invoiceDate', 'dueDate', 'vendorName', 'vendorAddress', 'vendorVatId',
                    'buyerName', 'buyerAddress', 'buyerVatId', 'subtotalAmount', 'taxAmount', 'totalAmount',
                    'currency', 'poNumber', 'paymentTerms', 'bankDetails', 'originalFilename', 'id'
                ]);
                fclose($out);
                exit;
            }
            $placeholders = [];
            foreach ($ids as $idx => $cid) {
                $placeholders[] = ":cid$idx";
                $params["cid$idx"] = $cid;
            }
            $conditions[] = "i.company_id IN (" . implode(',', $placeholders) . ")";
        }

        if ($search) {
            $conditions[] = "(i.invoice_number LIKE :search OR i.vendor_name LIKE :search2 OR i.buyer_name LIKE :search3 OR i.original_filename LIKE :search4)";
            $params['search'] = "%$search%";
            $params['search2'] = "%$search%";
            $params['search3'] = "%$search%";
            $params['search4'] = "%$search%";
        }

        if ($status) {
            $conditions[] = "i.status = :status";
            $params['status'] = $status;
        }

        if ($lifecycle === 'sent') {
            $conditions[] = "i.ocr_sent_at IS NOT NULL";
        } elseif ($lifecycle === 'not-sent') {
            $conditions[] = "i.ocr_sent_at IS NULL";
        } elseif ($lifecycle === 'returned') {
            $conditions[] = "i.ocr_returned_at IS NOT NULL";
        } elseif ($lifecycle === 'pending-return') {
            $conditions[] = "i.ocr_sent_at IS NOT NULL AND i.ocr_returned_at IS NULL";
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sentFrom)) {
            $conditions[] = "i.ocr_sent_at >= :sentFrom";
            $params['sentFrom'] = $sentFrom . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sentTo)) {
            $conditions[] = "i.ocr_sent_at <= :sentTo";
            $params['sentTo'] = $sentTo . ' 23:59:59';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnedFrom)) {
            $conditions[] = "i.ocr_returned_at >= :returnedFrom";
            $params['returnedFrom'] = $returnedFrom . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnedTo)) {
            $conditions[] = "i.ocr_returned_at <= :returnedTo";
            $params['returnedTo'] = $returnedTo . ' 23:59:59';
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "SELECT i.*, c.name as company_name, TIMESTAMPDIFF(SECOND, i.ocr_sent_at, i.ocr_returned_at) as processing_seconds
            FROM invoices i
            LEFT JOIN companies c ON c.id = i.company_id
            $where
            ORDER BY i.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $format = $_GET['format'] ?? 'csv';

        if ($format === 'json') {
            $jsonRows = [];
            foreach ($rows as $row) {
                $jsonRows[] = [
                    'id' => $row['id'] ?? null,
                    'company' => $row['company_name'] ?? null,
                    'source' => $row['source'] ?? null,
                    'status' => $row['status'] ?? null,
                    'documentType' => $row['document_type'] ?? null,
                    'createdAt' => $row['created_at'] ?? null,
                    'ocrSentAt' => $row['ocr_sent_at'] ?? null,
                    'ocrReturnedAt' => $row['ocr_returned_at'] ?? null,
                    'processingSeconds' => $row['processing_seconds'] !== null ? (int)$row['processing_seconds'] : null,
                    'invoiceNumber' => $row['invoice_number'] ?? null,
                    'invoiceDate' => $row['invoice_date'] ?? null,
                    'dueDate' => $row['due_date'] ?? null,
                    'vendorName' => $row['vendor_name'] ?? null,
                    'vendorAddress' => $row['vendor_address'] ?? null,
                    'vendorVatId' => $row['vendor_vat_id'] ?? null,
                    'buyerName' => $row['buyer_name'] ?? null,
                    'buyerAddress' => $row['buyer_address'] ?? null,
                    'buyerVatId' => $row['buyer_vat_id'] ?? null,
                    'subtotalAmount' => $row['subtotal_amount'] ?? null,
                    'taxAmount' => $row['tax_amount'] ?? null,
                    'totalAmount' => $row['total_amount'] ?? null,
                    'currency' => $row['currency'] ?? null,
                    'poNumber' => $row['po_number'] ?? null,
                    'paymentTerms' => $row['payment_terms'] ?? null,
                    'bankDetails' => $row['bank_details'] ?? null,
                    'originalFilename' => $row['original_filename'] ?? null,
                ];
            }
            $filename = 'invoices-export-' . date('Ymd-His') . '.json';
            header('Content-Type: application/json; charset=UTF-8');
            header("Content-Disposition: attachment; filename=\"$filename\"");
            header('Pragma: no-cache');
            header('Expires: 0');
            echo json_encode($jsonRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $filename = 'invoices-export-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"$filename\"");
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'company', 'source', 'status', 'document type', 'createdAt', 'ocrSentAt', 'ocrReturnedAt', 'processingSeconds',
            'invoiceNumber', 'invoiceDate', 'dueDate', 'vendorName', 'vendorAddress', 'vendorVatId',
            'buyerName', 'buyerAddress', 'buyerVatId', 'subtotalAmount', 'taxAmount', 'totalAmount',
            'currency', 'poNumber', 'paymentTerms', 'bankDetails', 'originalFilename', 'id'
        ]);

        foreach ($rows as $row) {
            fputcsv($out, array_map([$this, 'csvSafeValue'], [
                $row['company_name'] ?? '',
                $row['source'] ?? '',
                $row['status'] ?? '',
                $row['document_type'] ?? '',
                $row['created_at'] ?? '',
                $row['ocr_sent_at'] ?? '',
                $row['ocr_returned_at'] ?? '',
                $row['processing_seconds'] !== null ? (int)$row['processing_seconds'] : '',
                $row['invoice_number'] ?? '',
                $row['invoice_date'] ?? '',
                $row['due_date'] ?? '',
                $row['vendor_name'] ?? '',
                $row['vendor_address'] ?? '',
                $row['vendor_vat_id'] ?? '',
                $row['buyer_name'] ?? '',
                $row['buyer_address'] ?? '',
                $row['buyer_vat_id'] ?? '',
                $row['subtotal_amount'] ?? '',
                $row['tax_amount'] ?? '',
                $row['total_amount'] ?? '',
                $row['currency'] ?? '',
                $row['po_number'] ?? '',
                $row['payment_terms'] ?? '',
                $row['bank_details'] ?? '',
                $row['original_filename'] ?? '',
                $row['id'] ?? '',
            ]));
        }

        fclose($out);
        exit;
    }

    public function update($id) {
        $user = getAuthUser();
        $stmt = $this->db->prepare("SELECT * FROM invoices WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $existing = $stmt->fetch();
        if (!$existing) sendJSON(['error' => 'Invoice not found'], 404);

        if ($existing['company_id']) requireCompanyAccess($user, $existing['company_id'], 'manager');

        $data = $this->getRequestBody();
        $allowed = ['document_type', 'invoice_number', 'invoice_date', 'due_date', 'vendor_name', 'vendor_address', 'vendor_vat_id', 'buyer_name', 'buyer_address', 'buyer_vat_id', 'total_amount', 'tax_amount', 'subtotal_amount', 'currency', 'po_number', 'payment_terms', 'bank_details', 'status'];

        // Also handle camelCase from frontend
        $camelMap = [
            'documentType' => 'document_type',
            'invoiceNumber' => 'invoice_number', 'invoiceDate' => 'invoice_date', 'dueDate' => 'due_date',
            'vendorName' => 'vendor_name', 'vendorAddress' => 'vendor_address', 'vendorVatId' => 'vendor_vat_id',
            'buyerName' => 'buyer_name', 'buyerAddress' => 'buyer_address', 'buyerVatId' => 'buyer_vat_id',
            'totalAmount' => 'total_amount', 'taxAmount' => 'tax_amount', 'subtotalAmount' => 'subtotal_amount',
            'poNumber' => 'po_number', 'paymentTerms' => 'payment_terms', 'bankDetails' => 'bank_details',
        ];

        $updates = ['updated_at' => date('Y-m-d H:i:s')];
        foreach ($data as $key => $value) {
            $dbKey = $camelMap[$key] ?? $key;
            if (in_array($dbKey, $allowed)) {
                $updates[$dbKey] = $value;
            }
        }

        $sets = [];
        $params = [];
        foreach ($updates as $key => $value) {
            $paramKey = str_replace('.', '_', $key);
            $sets[] = "`$key` = :$paramKey";
            $params[$paramKey] = $value;
        }
        $params['_id'] = $id;

        $sql = "UPDATE invoices SET " . implode(', ', $sets) . " WHERE id = :_id";
        $this->db->prepare($sql)->execute($params);

        $this->get($id);
    }

    public function delete($id) {
        $user = getAuthUser();
        $stmt = $this->db->prepare("SELECT * FROM invoices WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $existing = $stmt->fetch();
        if (!$existing) sendJSON(['error' => 'Invoice not found'], 404);

        if ($existing['company_id']) requireCompanyAccess($user, $existing['company_id'], 'admin');

        $this->db->prepare("DELETE FROM invoices WHERE id = :id")->execute(['id' => $id]);
        logAction(['userId' => $user['id'], 'companyId' => $existing['company_id'], 'action' => 'delete', 'resourceType' => 'invoice', 'resourceId' => $id]);

        sendJSON(['success' => true]);
    }

    public function stats($id = null) {
        $user = getAuthUser();
        $companyId = $_GET['companyId'] ?? '';
        $conditions = [];
        $params = [];

        if ($companyId) {
            requireCompanyAccess($user, $companyId);
            $conditions[] = "company_id = :companyId";
            $params['companyId'] = $companyId;
        } elseif ($user['role'] !== 'superadmin') {
            $companies = getUserCompanies($user);
            $ids = array_column($companies, 'id');
            if (empty($ids)) {
                sendJSON(['totalInvoices' => 0, 'completedCount' => 0, 'processingCount' => 0, 'failedCount' => 0, 'totalAmountSum' => 0]);
            }
            $placeholders = [];
            foreach ($ids as $idx => $cid) {
                $placeholders[] = ":cid$idx";
                $params["cid$idx"] = $cid;
            }
            $conditions[] = "company_id IN (" . implode(',', $placeholders) . ")";
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "SELECT COUNT(*) as total, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN status='processing' THEN 1 ELSE 0 END) as processing, SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed, SUM(CASE WHEN status='completed' THEN COALESCE(total_amount,0) ELSE 0 END) as total_amount FROM invoices $where";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        $result = [
            'totalInvoices' => (int)($row['total'] ?? 0),
            'completedCount' => (int)($row['completed'] ?? 0),
            'processingCount' => (int)($row['processing'] ?? 0),
            'failedCount' => (int)($row['failed'] ?? 0),
            'totalAmountSum' => (float)($row['total_amount'] ?? 0),
        ];

        // Superadmin: add per-company breakdown when viewing all
        if ($user['role'] === 'superadmin' && !$companyId) {
            // Per-company invoice stats
            try {
                $companySql = "SELECT c.id, c.name, c.code,
                    COALESCE(s.plan, 'free') as plan,
                    COALESCE(s.status, 'active') as billing_status,
                    COUNT(i.id) as total_invoices,
                    SUM(CASE WHEN i.status='completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN i.status='failed' THEN 1 ELSE 0 END) as failed,
                    MAX(i.created_at) as last_activity,
                    MAX(i.ocr_sent_at) as last_ocr_sent_at,
                    MAX(i.ocr_returned_at) as last_ocr_returned_at
                    FROM companies c
                    LEFT JOIN subscriptions s ON s.company_id = c.id
                    LEFT JOIN invoices i ON i.company_id = c.id
                    GROUP BY c.id, c.name, c.code, s.plan, s.status
                    ORDER BY last_activity DESC";
                $companyRows = $this->db->query($companySql)->fetchAll();
            } catch (\Throwable $e) {
                // Fallback for DBs without OCR lifecycle columns.
                $companySql = "SELECT c.id, c.name, c.code,
                    COALESCE(s.plan, 'free') as plan,
                    COALESCE(s.status, 'active') as billing_status,
                    COUNT(i.id) as total_invoices,
                    SUM(CASE WHEN i.status='completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN i.status='failed' THEN 1 ELSE 0 END) as failed,
                    MAX(i.created_at) as last_activity
                    FROM companies c
                    LEFT JOIN subscriptions s ON s.company_id = c.id
                    LEFT JOIN invoices i ON i.company_id = c.id
                    GROUP BY c.id, c.name, c.code, s.plan, s.status
                    ORDER BY last_activity DESC";
                $companyRows = $this->db->query($companySql)->fetchAll();
                foreach ($companyRows as &$companyRow) {
                    $companyRow['last_ocr_sent_at'] = null;
                    $companyRow['last_ocr_returned_at'] = null;
                }
                unset($companyRow);
            }

            // Usage logs aggregated per company
            try {
                $usageSql = "SELECT
                    company_id,
                    SUM(invoices_processed) as processed,
                    SUM(api_calls_count) as api_calls,
                    SUM(storage_used_bytes) as storage,
                    SUM(ocr_input_tokens) as ocr_input_tokens,
                    SUM(ocr_output_tokens) as ocr_output_tokens,
                    SUM(ocr_total_tokens) as ocr_total_tokens,
                    SUM(ocr_cost_usd) as ocr_cost_usd
                    FROM usage_logs
                    GROUP BY company_id";
                $usageRows = $this->db->query($usageSql)->fetchAll();
            } catch (\Throwable $e) {
                // Fallback for DBs without token/cost usage columns.
                $usageSql = "SELECT
                    company_id,
                    SUM(invoices_processed) as processed,
                    SUM(api_calls_count) as api_calls,
                    SUM(storage_used_bytes) as storage
                    FROM usage_logs
                    GROUP BY company_id";
                $usageRows = $this->db->query($usageSql)->fetchAll();
                foreach ($usageRows as &$usageRow) {
                    $usageRow['ocr_input_tokens'] = 0;
                    $usageRow['ocr_output_tokens'] = 0;
                    $usageRow['ocr_total_tokens'] = 0;
                    $usageRow['ocr_cost_usd'] = 0;
                }
                unset($usageRow);
            }
            $usageMap = [];
            $totalInputTokens = 0;
            $totalOutputTokens = 0;
            $totalTokens = 0;
            $totalOcrCostUsd = 0.0;
            foreach ($usageRows as $u) {
                $usageMap[$u['company_id']] = $u;
                $totalInputTokens += (int)($u['ocr_input_tokens'] ?? 0);
                $totalOutputTokens += (int)($u['ocr_output_tokens'] ?? 0);
                $totalTokens += (int)($u['ocr_total_tokens'] ?? 0);
                $totalOcrCostUsd += (float)($u['ocr_cost_usd'] ?? 0);
            }

            $companies = [];
            foreach ($companyRows as $c) {
                $usage = $usageMap[$c['id']] ?? [];
                $companies[] = [
                    'companyId' => $c['id'],
                    'companyName' => $c['name'],
                    'companyCode' => $c['code'],
                    'plan' => $c['plan'],
                    'billingStatus' => $c['billing_status'],
                    'totalInvoices' => (int)($c['total_invoices'] ?? 0),
                    'completedCount' => (int)($c['completed'] ?? 0),
                    'failedCount' => (int)($c['failed'] ?? 0),
                    'apiCalls' => (int)($usage['api_calls'] ?? 0),
                    'storageUsedBytes' => (int)($usage['storage'] ?? 0),
                    'inputTokens' => (int)($usage['ocr_input_tokens'] ?? 0),
                    'outputTokens' => (int)($usage['ocr_output_tokens'] ?? 0),
                    'totalTokens' => (int)($usage['ocr_total_tokens'] ?? 0),
                    'tokenUsage' => (int)($usage['ocr_total_tokens'] ?? 0),
                    'ocrCostUsd' => (float)($usage['ocr_cost_usd'] ?? 0),
                    'costUsd' => (float)($usage['ocr_cost_usd'] ?? 0),
                    'lastActivity' => $c['last_activity'],
                    'lastOcrSentAt' => $c['last_ocr_sent_at'],
                    'lastOcrReturnedAt' => $c['last_ocr_returned_at'],
                    'lastSentAt' => $c['last_ocr_sent_at'],
                    'lastReturnedAt' => $c['last_ocr_returned_at'],
                ];
            }
            $result['companies'] = $companies;
            $result['tokenUsageTotals'] = [
                'inputTokens' => $totalInputTokens,
                'outputTokens' => $totalOutputTokens,
                'totalTokens' => $totalTokens,
                'ocrCostUsd' => round($totalOcrCostUsd, 6),
            ];
        }

        sendJSON($result);
    }

    public function file($id) {
        $user = getAuthUser();
        $stmt = $this->db->prepare("SELECT * FROM invoices WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $invoice = $stmt->fetch();
        if (!$invoice) sendJSON(['error' => 'Invoice not found'], 404);

        if ($invoice['company_id']) requireCompanyAccess($user, $invoice['company_id']);

        $filePath = getFilePath($invoice['stored_filename']);
        if (!file_exists($filePath)) sendJSON(['error' => 'File not found'], 404);

        $contentType = $this->contentTypes[$invoice['file_type']] ?? 'application/octet-stream';
        header("Content-Type: $contentType");
        header("Content-Disposition: inline; filename=\"{$invoice['original_filename']}\"");
        readfile($filePath);
        exit;
    }

    public function metadata($id) {
        $user = getAuthUser();
        $stmt = $this->db->prepare("SELECT * FROM invoices WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $invoice = $stmt->fetch();
        if (!$invoice) sendJSON(['error' => 'Invoice not found'], 404);
        if ($invoice['company_id']) requireCompanyAccess($user, $invoice['company_id']);

        $meta = [
            'documentType' => $invoice['document_type'],
            'invoiceNumber' => $invoice['invoice_number'],
            'invoiceDate' => $invoice['invoice_date'],
            'dueDate' => $invoice['due_date'],
            'vendorName' => $invoice['vendor_name'],
            'vendorAddress' => $invoice['vendor_address'],
            'vendorVatId' => $invoice['vendor_vat_id'],
            'buyerName' => $invoice['buyer_name'],
            'buyerAddress' => $invoice['buyer_address'],
            'buyerVatId' => $invoice['buyer_vat_id'],
            'totalAmount' => $invoice['total_amount'] !== null ? (float)$invoice['total_amount'] : null,
            'taxAmount' => $invoice['tax_amount'] !== null ? (float)$invoice['tax_amount'] : null,
            'subtotalAmount' => $invoice['subtotal_amount'] !== null ? (float)$invoice['subtotal_amount'] : null,
            'currency' => $invoice['currency'],
            'poNumber' => $invoice['po_number'],
            'paymentTerms' => $invoice['payment_terms'],
            'bankDetails' => $invoice['bank_details'],
        ];

        // Remove null values for cleaner output
        $meta = array_filter($meta, fn($v) => $v !== null);

        if ($invoice['confidence_scores']) {
            $meta['confidenceScores'] = json_decode($invoice['confidence_scores'], true);
        }

        $filename = ($invoice['invoice_number'] ?: 'invoice-' . $id) . '.json';
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        header('Content-Type: application/json');
        header("Content-Disposition: attachment; filename=\"$filename\"");
        echo json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function vecticum($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendJSON(['error' => 'Method not allowed'], 405);

        $user = getAuthUser();
        $stmt = $this->db->prepare("SELECT * FROM invoices WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $invoice = $stmt->fetch();
        if (!$invoice) sendJSON(['error' => 'Invoice not found'], 404);
        if (!$invoice['company_id']) sendJSON(['error' => 'Invoice has no company'], 400);

        requireCompanyAccess($user, $invoice['company_id'], 'manager');

        $stmt = $this->db->prepare("SELECT * FROM companies WHERE id = :id");
        $stmt->execute(['id' => $invoice['company_id']]);
        $company = $stmt->fetch();
        if (!$company) sendJSON(['error' => 'Company not found'], 404);
        if (!$company['vecticum_enabled']) sendJSON(['error' => 'Vecticum not enabled for this company'], 400);

        $result = uploadToVecticum($company, [
            'invoiceNumber' => $invoice['invoice_number'],
            'invoiceDate' => $invoice['invoice_date'],
            'dueDate' => $invoice['due_date'],
            'vendorName' => $invoice['vendor_name'],
            'vendorVatId' => $invoice['vendor_vat_id'],
            'subtotalAmount' => $invoice['subtotal_amount'],
            'taxAmount' => $invoice['tax_amount'],
            'totalAmount' => $invoice['total_amount'],
            'currency' => $invoice['currency'],
        ]);

        if ($result['success']) {
            sendJSON(['success' => true, 'externalId' => $result['externalId'], 'message' => "Invoice sent to Vecticum (ID: {$result['externalId']})"]);
        }
        sendJSON(['error' => $result['error'] ?? 'Failed to upload to Vecticum'], 500);
    }
}

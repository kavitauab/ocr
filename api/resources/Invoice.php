<?php
require_once __DIR__ . '/../lib/file_storage.php';
require_once __DIR__ . '/../lib/claude.php';
require_once __DIR__ . '/../lib/usage.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/vecticum.php';

class Invoice extends BaseResource {
    protected $tableName = 'invoices';
    protected $searchColumns = ['invoice_number', 'vendor_name', 'buyer_name'];
    protected $allowedOrderColumns = ['id', 'created_at', 'updated_at', 'invoice_date', 'vendor_name', 'total_amount', 'status'];

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

    public function list() {
        $user = getAuthUser();
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? '';
        $companyId = $_GET['companyId'] ?? '';
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
            $conditions[] = "(i.invoice_number LIKE :search OR i.vendor_name LIKE :search2 OR i.buyer_name LIKE :search3)";
            $params['search'] = "%$search%";
            $params['search2'] = "%$search%";
            $params['search3'] = "%$search%";
        }

        if ($status) {
            $conditions[] = "i.status = :status";
            $params['status'] = $status;
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $countSql = "SELECT COUNT(*) FROM invoices i $where";
        $stmt = $this->db->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $sql = "SELECT i.* FROM invoices i $where ORDER BY i.created_at DESC LIMIT $limit OFFSET $offset";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        $formatted = array_map([$this, 'formatInvoice'], $items);

        sendJSON(['invoices' => $formatted, 'total' => $total, 'page' => $page, 'totalPages' => max(1, (int)ceil($total / $limit))]);
    }

    public function create() {
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
        try {
            $filePath = getFilePath($saved['storedFilename']);
            $extracted = extractInvoiceData($filePath, $saved['fileType']);

            $stmt = $this->db->prepare("UPDATE invoices SET status = 'completed',
                invoice_number = :invoiceNumber, invoice_date = :invoiceDate, due_date = :dueDate,
                vendor_name = :vendorName, vendor_address = :vendorAddress, vendor_vat_id = :vendorVatId,
                buyer_name = :buyerName, buyer_address = :buyerAddress, buyer_vat_id = :buyerVatId,
                total_amount = :totalAmount, currency = :currency, tax_amount = :taxAmount,
                subtotal_amount = :subtotalAmount, po_number = :poNumber, payment_terms = :paymentTerms,
                bank_details = :bankDetails, confidence_scores = :confidence, raw_extraction = :raw,
                updated_at = NOW() WHERE id = :id");
            $stmt->execute([
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
            ]);

            trackInvoiceProcessed($companyId, $file['size']);
        } catch (\Throwable $e) {
            try {
                $this->db->prepare("UPDATE invoices SET status = 'failed', processing_error = :error, updated_at = NOW() WHERE id = :id")
                    ->execute(['error' => $e->getMessage(), 'id' => $id]);
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
        $user = getAuthUser();
        $stmt = $this->db->prepare("SELECT * FROM invoices WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $invoice = $stmt->fetch();
        if (!$invoice) sendJSON(['error' => 'Invoice not found'], 404);

        if ($invoice['company_id']) requireCompanyAccess($user, $invoice['company_id']);

        sendJSON(['invoice' => $this->formatInvoice($invoice)]);
    }

    public function update($id) {
        $user = getAuthUser();
        $stmt = $this->db->prepare("SELECT * FROM invoices WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $existing = $stmt->fetch();
        if (!$existing) sendJSON(['error' => 'Invoice not found'], 404);

        if ($existing['company_id']) requireCompanyAccess($user, $existing['company_id'], 'manager');

        $data = $this->getRequestBody();
        $allowed = ['invoice_number', 'invoice_date', 'due_date', 'vendor_name', 'vendor_address', 'vendor_vat_id', 'buyer_name', 'buyer_address', 'buyer_vat_id', 'total_amount', 'tax_amount', 'subtotal_amount', 'currency', 'po_number', 'payment_terms', 'bank_details', 'status'];

        // Also handle camelCase from frontend
        $camelMap = [
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

        sendJSON([
            'totalInvoices' => (int)($row['total'] ?? 0),
            'completedCount' => (int)($row['completed'] ?? 0),
            'processingCount' => (int)($row['processing'] ?? 0),
            'failedCount' => (int)($row['failed'] ?? 0),
            'totalAmountSum' => (float)($row['total_amount'] ?? 0),
        ]);
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

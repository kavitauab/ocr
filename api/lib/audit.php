<?php

function logAction($params) {
    $db = getDBConnection();
    $stmt = $db->prepare("INSERT INTO audit_log (id, user_id, company_id, action, resource_type, resource_id, metadata) VALUES (:id, :userId, :companyId, :action, :resourceType, :resourceId, :metadata)");
    $stmt->execute([
        'id' => generateId(),
        'userId' => $params['userId'] ?? null,
        'companyId' => $params['companyId'] ?? null,
        'action' => $params['action'],
        'resourceType' => $params['resourceType'],
        'resourceId' => $params['resourceId'] ?? null,
        'metadata' => isset($params['metadata']) ? json_encode($params['metadata']) : null,
    ]);
}

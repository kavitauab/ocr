<?php

function saveFile($fileData, $originalName, $companyId = null) {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $filename = generateId() . '.' . $ext;

    $subdir = $companyId ? UPLOAD_DIR . '/' . $companyId : UPLOAD_DIR;
    if (!is_dir($subdir)) {
        mkdir($subdir, 0777, true);
        chmod($subdir, 0777);
    }
    // Ensure writable even if created by a different user (cron vs web)
    if (!is_writable($subdir)) {
        @chmod($subdir, 0777);
    }

    $storedFilename = $companyId ? $companyId . '/' . $filename : $filename;
    $filePath = UPLOAD_DIR . '/' . $storedFilename;

    file_put_contents($filePath, $fileData);

    return ['storedFilename' => $storedFilename, 'fileType' => $ext];
}

function getFilePath($storedFilename) {
    return UPLOAD_DIR . '/' . $storedFilename;
}

function readStoredFile($storedFilename) {
    $filePath = getFilePath($storedFilename);
    if (!file_exists($filePath)) {
        throw new Exception('File not found');
    }
    return file_get_contents($filePath);
}

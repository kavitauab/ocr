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

    // Compress large images before saving (JPG/PNG only, not PDFs)
    $origSize = strlen($fileData);
    if (in_array($ext, ['jpg', 'jpeg', 'png']) && $origSize > 500 * 1024) {
        $compressed = compressImage($fileData, $ext);
        if ($compressed !== null && strlen($compressed) < $origSize) {
            error_log("[saveFile] Compressed $originalName: " . round($origSize/1024) . "KB -> " . round(strlen($compressed)/1024) . "KB");
            $fileData = $compressed;
            // Normalize extension to jpg after compression
            if ($ext === 'png') {
                $ext = 'jpg';
                $filename = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
            }
        }
    }

    $storedFilename = $companyId ? $companyId . '/' . $filename : $filename;
    $filePath = UPLOAD_DIR . '/' . $storedFilename;

    file_put_contents($filePath, $fileData);

    return ['storedFilename' => $storedFilename, 'fileType' => $ext];
}

/**
 * Compress an image by downscaling to max 2000px on longest side and re-encoding as JPEG.
 * Returns compressed binary data or null on failure.
 */
function compressImage($imageData, $ext) {
    if (!function_exists('imagecreatefromstring')) {
        return null; // GD extension not available
    }

    $img = @imagecreatefromstring($imageData);
    if ($img === false) return null;

    $width = imagesx($img);
    $height = imagesy($img);
    $maxDim = 2000;

    // Resize if larger than max dimension
    if ($width > $maxDim || $height > $maxDim) {
        if ($width > $height) {
            $newWidth = $maxDim;
            $newHeight = (int)($height * $maxDim / $width);
        } else {
            $newHeight = $maxDim;
            $newWidth = (int)($width * $maxDim / $height);
        }
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        // Preserve white background for PNG transparency
        imagefill($resized, 0, 0, imagecolorallocate($resized, 255, 255, 255));
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($img);
        $img = $resized;
    }

    // Encode as JPEG with 100% quality (most savings come from resize, not quality loss)
    ob_start();
    $ok = imagejpeg($img, null, 100);
    $result = ob_get_clean();
    imagedestroy($img);

    return $ok ? $result : null;
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

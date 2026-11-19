<?php
/** Shared filesystem and deployment URL settings. */
const APP_ROOT = __DIR__ . '/..';
date_default_timezone_set('Asia/Ho_Chi_Minh');

// Set REDLINE_BASE_URL for a deployment with a reverse proxy or custom URL prefix.
$baseUrl = getenv('REDLINE_BASE_URL');
if ($baseUrl === false) {
    $baseUrl = '';
    $scriptFile = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    $appDirectory = str_replace('\\', '/', realpath(APP_ROOT));
    if (str_starts_with(strtolower($scriptFile), strtolower($appDirectory . '/'))) {
        $relativeScript = substr($scriptFile, strlen($appDirectory) + 1);
        $scriptUrl = $_SERVER['SCRIPT_NAME'] ?? '';
        if ($relativeScript !== '' && str_ends_with($scriptUrl, $relativeScript)) {
            $baseUrl = substr($scriptUrl, 0, -strlen($relativeScript));
        }
    }
}
define('BASE_URL', rtrim($baseUrl, '/'));
unset($baseUrl, $scriptFile, $appDirectory, $relativeScript, $scriptUrl);

/** Keep database paths saved before uploads were consolidated readable. */
function normalize_upload_path(string $path): string
{
    return str_starts_with($path, 'public/uploads/') ? substr($path, 7) : $path;
}

function app_url(string $path = ''): string
{
    $normalized = normalize_upload_path(ltrim($path, '/'));
    // Uploaded customer/vehicle/workshop images are never publicly accessible.
    // Always resolve authorization in media.php before opening the file.
    if (str_starts_with($normalized, 'uploads/')) {
        return BASE_URL . '/media.php?path=' . rawurlencode($normalized);
    }
    return BASE_URL . '/' . $normalized;
}

require_once __DIR__ . '/constants.php';

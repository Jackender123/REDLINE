<?php
/** P0 regression checks, PHP CLI only. No database required. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$uploadRules = file_get_contents($root . '/uploads/.htaccess');
$rootRules = file_get_contents($root . '/.htaccess');
$workspace = file_get_contents($root . '/employees/index.php');

function checkP0(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: $message\n";
}

checkP0($uploadRules !== false && preg_match('/^\s*Require\s+all\s+denied\s*$/mi', $uploadRules) === 1,
    'Apache blocks direct HTTP access to private uploads');
checkP0($rootRules !== false && str_contains($rootRules, 'RewriteRule ^(?:public/)?uploads(?:/|$) - [F,L,NC]'),
    'Apache also blocks direct and legacy upload URLs from the document root');
checkP0(strpos($rootRules, 'RewriteRule ^(?:public/)?uploads(?:/|$)') < strpos($rootRules, 'RewriteRule ^public/uploads/(.*)$'),
    'The deny rule is evaluated before the old public/uploads redirect');

$css = 'employees.css';
checkP0(is_file($root . '/assets/css/' . $css), 'Employee stylesheet exists');
checkP0($workspace !== false && str_contains($workspace, "../assets/css/$css?v="),
    'Employee workspace loads its existing shared stylesheet');
checkP0(!str_contains($workspace, 'employees_command_center.css'),
    'Employee workspace no longer loads the missing stylesheet');
checkP0(is_file($root . '/assets/css/technician_full.css'),
    'Technician-specific stylesheet is preserved');

checkP0(is_file($root . '/media/view.php') && !is_file($root . '/media.php'),
    'Private media controller lives in its dedicated media/ directory');
checkP0(str_contains($rootRules, 'RewriteRule ^media\.php$ media/view.php [L,NC]'),
    'Existing media.php links remain compatible via an internal Apache rewrite');

require_once $root . '/config/config.php';
checkP0(str_starts_with(app_url('uploads/vehicles/demo.jpg'), BASE_URL . '/media/view.php?path='),
    'Uploaded images still render using their authenticated media endpoint');

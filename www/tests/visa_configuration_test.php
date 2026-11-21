<?php
/** Deterministic configuration checks: synthetic keys, no database or Stripe requests. */
$configurationNames = [
    'REDLINE_STRIPE_MODE', 'REDLINE_STRIPE_SECRET_KEY',
    'REDLINE_STRIPE_WEBHOOK_SECRET', 'REDLINE_PUBLIC_URL',
];
$testSecret = 'sk_test_' . str_repeat('A', 32);
$liveSecret = 'sk_live_' . str_repeat('B', 32);
$signingSecret = 'whsec_' . str_repeat('C', 32);

// Separate processes prevent the private-file static cache from leaking between fixtures.
if (($argv[1] ?? '') === '--fixture') {
    $case = json_decode(base64_decode($argv[2] ?? '', true), true, 512, JSON_THROW_ON_ERROR);
    $_SERVER['DOCUMENT_ROOT'] = $case['document_root'];
    foreach ($configurationNames as $name) putenv($name);
    foreach ($case['environment'] ?? [] as $name => $value) putenv($name . '=' . $value);
    require_once __DIR__ . '/../includes/visa_checkout.php';
    echo json_encode([
        'private_loaded' => rl_visa_private_settings() !== [],
        'matches_expected_secret' => rl_visa_setting('REDLINE_STRIPE_SECRET_KEY') === $case['expected_secret'],
        'matches_expected_signing' => rl_visa_setting('REDLINE_STRIPE_WEBHOOK_SECRET') === $case['expected_signing'],
        'mode' => rl_visa_mode(),
    ], JSON_THROW_ON_ERROR);
    exit;
}

$_SERVER['DOCUMENT_ROOT'] = '';
foreach ($configurationNames as $name) putenv($name);
require_once __DIR__ . '/../includes/visa_checkout.php';
$failures = 0;
function configuration_check(bool $passed, string $label): void
{
    global $failures;
    echo ($passed ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$passed) $failures++;
}

configuration_check(rl_visa_valid_api_key($testSecret, 'test'), 'test key accepted in test mode');
configuration_check(rl_visa_valid_api_key($liveSecret, 'live'), 'live key accepted in live mode');
configuration_check(!rl_visa_valid_api_key($testSecret, 'live'), 'test key rejected in live mode');
configuration_check(!rl_visa_valid_api_key($liveSecret, 'test'), 'live key rejected in test mode');
foreach (['', 'redline-stripe.private.php', 'sk_test_replace_me', 'pk_test_' . str_repeat('A', 32), 'sk_test_short', $testSecret . "\n"] as $invalid) {
    configuration_check(!rl_visa_valid_api_key($invalid, 'test'), 'malformed or placeholder API key rejected');
}
configuration_check(rl_visa_valid_webhook_secret($signingSecret), 'synthetic signing secret accepted');
foreach (['', 'whsec_replace_me', $testSecret, 'whsec_' . str_repeat('C', 32) . "\n"] as $invalid) {
    configuration_check(!rl_visa_valid_webhook_secret($invalid), 'malformed or placeholder signing secret rejected');
}

$urlCases = [
    ['test', 'http://localhost:8080/redline/', 'http://localhost:8080/redline'],
    ['test', 'http://127.0.0.1:8080', 'http://127.0.0.1:8080'],
    ['test', 'http://[::1]:8080', 'http://[::1]:8080'],
    ['test', 'http://example.com', null],
    ['live', 'http://localhost:8080', null],
    ['live', 'http://example.com', null],
    ['live', 'https://example.com/garage/', 'https://example.com/garage'],
    ['live', 'HTTPS://example.com', 'HTTPS://example.com'],
    ['live', 'https://user:password@example.com', null],
    ['live', 'https://example.com?token=anything', null],
    ['live', 'https://example.com#fragment', null],
    ['live', '//example.com', null],
    ['live', 'javascript:alert(1)', null],
];
foreach ($urlCases as [$mode, $url, $expected]) {
    putenv('REDLINE_STRIPE_MODE=' . $mode);
    putenv('REDLINE_PUBLIC_URL=' . $url);
    configuration_check(rl_visa_public_base() === $expected, $mode . ' public URL rules: ' . $url);
}
putenv('REDLINE_STRIPE_MODE=live');
putenv('REDLINE_STRIPE_SECRET_KEY=' . $testSecret);
putenv('REDLINE_STRIPE_WEBHOOK_SECRET=' . $signingSecret);
putenv('REDLINE_PUBLIC_URL=https://example.com');
configuration_check(!rl_visa_configured(), 'mismatched live mode never enables checkout');

$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'redline_visa_config_' . bin2hex(random_bytes(6));
$privateDir = $fixture . '/private';
$publicOnlyDir = $fixture . '/public-only';
$invalidDir = $fixture . '/invalid';
$createdFiles = [];
$createdDirs = [$fixture, $privateDir, $privateDir . '/public', $publicOnlyDir, $publicOnlyDir . '/public', $invalidDir, $invalidDir . '/public'];
try {
    foreach ($createdDirs as $directory) {
        if (!mkdir($directory, 0700)) throw new RuntimeException('Could not create isolated configuration fixture.');
    }
    $settings = [
        'REDLINE_STRIPE_MODE' => 'test',
        'REDLINE_STRIPE_SECRET_KEY' => $testSecret,
        'REDLINE_STRIPE_WEBHOOK_SECRET' => $signingSecret,
        'REDLINE_PUBLIC_URL' => 'https://example.com',
    ];
    $files = [
        $privateDir . '/redline-stripe.private.php' => '<?php return ' . var_export($settings, true) . ';',
        $publicOnlyDir . '/public/redline-stripe.private.php' => '<?php return ' . var_export($settings, true) . ';',
        $invalidDir . '/redline-stripe.private.php' => '<?php return "invalid configuration";',
    ];
    foreach ($files as $path => $contents) {
        if (file_put_contents($path, $contents) === false) throw new RuntimeException('Could not write isolated configuration fixture.');
        $createdFiles[] = $path;
    }
    $cases = [
        'private settings outside document root load' => [
            'document_root' => $privateDir . '/public', 'expected_secret' => $testSecret,
            'expected_signing' => $signingSecret, 'expected_private' => true, 'expected_mode' => 'test',
        ],
        'trimmed environment overrides private file; blank environment falls back' => [
            'document_root' => $privateDir . '/public',
            'environment' => ['REDLINE_STRIPE_SECRET_KEY' => '  ' . $liveSecret . '  ', 'REDLINE_STRIPE_MODE' => ' live ', 'REDLINE_STRIPE_WEBHOOK_SECRET' => '   '],
            'expected_secret' => $liveSecret, 'expected_signing' => $signingSecret,
            'expected_private' => true, 'expected_mode' => 'live',
        ],
        'configuration inside public directory is not loaded' => [
            'document_root' => $publicOnlyDir . '/public', 'expected_secret' => '',
            'expected_signing' => '', 'expected_private' => false, 'expected_mode' => 'test',
        ],
        'private file returning non-array is ignored' => [
            'document_root' => $invalidDir . '/public', 'expected_secret' => '',
            'expected_signing' => '', 'expected_private' => false, 'expected_mode' => 'test',
        ],
        'without trusted document root only environment is used' => [
            'document_root' => '',
            'environment' => ['REDLINE_STRIPE_SECRET_KEY' => $testSecret, 'REDLINE_STRIPE_WEBHOOK_SECRET' => $signingSecret],
            'expected_secret' => $testSecret, 'expected_signing' => $signingSecret,
            'expected_private' => false, 'expected_mode' => 'test',
        ],
    ];
    foreach ($cases as $label => $case) {
        $command = [PHP_BINARY];
        if (php_ini_loaded_file()) array_push($command, '-c', php_ini_loaded_file());
        array_push($command, __FILE__, '--fixture', base64_encode(json_encode($case, JSON_THROW_ON_ERROR)));
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('Could not start isolated configuration check.');
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $result = json_decode($output, true);
        configuration_check($status === 0 && $error === '' && is_array($result)
            && $result['private_loaded'] === $case['expected_private']
            && $result['matches_expected_secret'] && $result['matches_expected_signing']
            && $result['mode'] === $case['expected_mode'], $label);
    }
} finally {
    foreach ($createdFiles as $path) unlink($path);
    foreach (array_reverse($createdDirs) as $directory) if (is_dir($directory)) rmdir($directory);
}
exit($failures === 0 ? 0 : 1);

<?php
/** Exercise the real Stripe transport with synthetic credentials and a fake cURL boundary. */
if (($argv[1] ?? '') !== '--mock-curl') {
    $process = proc_open([PHP_BINARY, '-n', __FILE__, '--mock-curl'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('Could not start isolated transport test.');
    echo stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($error !== '') fwrite(STDERR, $error);
    exit($status);
}

if (extension_loaded('curl')) throw new RuntimeException('Transport fixture must run with php -n.');
foreach (['CURLOPT_RETURNTRANSFER', 'CURLOPT_CUSTOMREQUEST', 'CURLOPT_HTTPHEADER',
    'CURLOPT_CONNECTTIMEOUT', 'CURLOPT_TIMEOUT', 'CURLOPT_SSL_VERIFYPEER',
    'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_FOLLOWLOCATION', 'CURLOPT_HEADERFUNCTION',
    'CURLOPT_POSTFIELDS', 'CURLINFO_HTTP_CODE'] as $index => $constant) define($constant, $index + 1);

// Declared conditionally so the parent process can use its normal PHP extensions.
if (!extension_loaded('curl')) {
    function curl_init(string $url): object { return (object)['url' => $url, 'options' => []]; }
    function curl_setopt_array(object $handle, array $options): bool { $handle->options = $options; return true; }
    function curl_setopt(object $handle, int $option, mixed $value): bool { $handle->options[$option] = $value; return true; }
    function curl_exec(object $handle): string|false {
        global $response, $request;
        $request = $handle;
        foreach ($response['headers'] as $line) {
            $consumed = $handle->options[CURLOPT_HEADERFUNCTION]($handle, $line);
            if ($consumed !== strlen($line)) throw new RuntimeException('Header callback consumed the wrong length.');
        }
        return $response['body'];
    }
    function curl_getinfo(object $handle, int $option): int { global $response; return $response['status']; }
    function curl_errno(object $handle): int { global $response; return $response['errno']; }
}

$_SERVER['DOCUMENT_ROOT'] = '';
$syntheticKey = 'sk_test_' . str_repeat('A', 32);
putenv('REDLINE_STRIPE_MODE=test');
putenv('REDLINE_STRIPE_SECRET_KEY=' . $syntheticKey);
putenv('REDLINE_STRIPE_WEBHOOK_SECRET=whsec_' . str_repeat('B', 32));
putenv('REDLINE_PUBLIC_URL=https://example.com');
require_once __DIR__ . '/../includes/visa_checkout.php';
function transport_check(bool $passed, string $label): void {
    if (!$passed) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . PHP_EOL;
}
$logFile = tempnam(sys_get_temp_dir(), 'redline_visa_transport_');
if ($logFile === false) throw new RuntimeException('Could not create isolated diagnostic log.');
ini_set('error_log', $logFile);
$response = ['status' => 200, 'errno' => 0, 'headers' => ["Request-Id: req_success123\r\n"],
    'body' => '{"id":"cs_test_mock123"}'];
try {
    $data = ['mode' => 'payment', 'allowed_payment_method_types' => ['card'],
        'line_items' => [['price_data' => ['currency' => 'usd', 'unit_amount' => 12500,
            'product_data' => ['name' => 'Invoice & service']],'quantity' => 1]],
        'success_url' => 'https://example.com/return?session_id={CHECKOUT_SESSION_ID}'];
    $result = rl_visa_request('POST', '/checkout/sessions', $data, 'redline-visa-synthetic');
    transport_check($result === ['id' => 'cs_test_mock123'], 'successful JSON response returned');
    transport_check($request->url === 'https://api.stripe.com/v1/checkout/sessions'
        && $request->options[CURLOPT_CUSTOMREQUEST] === 'POST', 'request reaches fixed Stripe HTTPS endpoint');
    $headers = $request->options[CURLOPT_HTTPHEADER];
    transport_check(in_array('Stripe-Version: 2026-11-18.endive', $headers, true), 'Checkout API version is pinned on the request');
    transport_check(in_array('Idempotency-Key: redline-visa-synthetic', $headers, true)
        && in_array('Authorization: Bearer ' . $syntheticKey, $headers, true), 'idempotency and server authorization headers sent');
    parse_str($request->options[CURLOPT_POSTFIELDS], $encoded);
    transport_check($encoded['allowed_payment_method_types'] === ['card']
        && !isset($encoded['payment_method_types']) && $encoded['line_items'][0]['price_data']['product_data']['name'] === 'Invoice & service'
        && $encoded['success_url'] === $data['success_url']
        && in_array('Content-Type: application/x-www-form-urlencoded', $headers, true), 'card payload uses current field and nested form encoding');
    transport_check($request->options[CURLOPT_SSL_VERIFYPEER] === true
        && $request->options[CURLOPT_SSL_VERIFYHOST] === 2 && $request->options[CURLOPT_FOLLOWLOCATION] === false,
        'TLS verification enabled and redirects disabled');
    transport_check(file_get_contents($logFile) === '', 'successful response does not write error diagnostics');

    rl_visa_request('GET', '/checkout/sessions/cs_test_mock123');
    transport_check($request->options[CURLOPT_CUSTOMREQUEST] === 'GET'
        && !isset($request->options[CURLOPT_POSTFIELDS])
        && count(array_filter($request->options[CURLOPT_HTTPHEADER], fn($header) => str_starts_with($header, 'Idempotency-Key:'))) === 0,
        'GET retrieval sends neither form body nor idempotency key');

    $unsafeMessage = 'Raw customer marker and credential ' . $syntheticKey;
    $cases = [
        'HTTP 400 schema error' => ['status' => 400, 'errno' => 0, 'headers' => ["rEqUeSt-Id: req_schema123\r\n"],
            'body' => json_encode(['error' => ['type' => 'invalid_request_error', 'code' => 'parameter_unknown',
                'param' => 'payment_method_types', 'message' => $unsafeMessage]])],
        'malicious error fields' => ['status' => 400, 'errno' => 0, 'headers' => ["Request-Id: req_bad\nInjected: unsafe\r\n"],
            'body' => json_encode(['error' => ['type' => "invalid_request_error\nInjected", 'code' => $syntheticKey,
                'param' => str_repeat('x', 121), 'message' => $unsafeMessage]])],
        'network failure' => ['status' => 0, 'errno' => 28, 'headers' => [], 'body' => false],
        'invalid JSON success response' => ['status' => 200, 'errno' => 0, 'headers' => [], 'body' => $unsafeMessage],
    ];
    foreach ($cases as $label => $response) {
        file_put_contents($logFile, '');
        $message = '';
        try { rl_visa_request('POST', '/checkout/sessions', $data); }
        catch (RuntimeException $error) { $message = $error->getMessage(); }
        transport_check($message === 'Card payment provider is temporarily unavailable. Please try again.', $label . ' gives stable generic customer error');
        $log = file_get_contents($logFile);
        $marker = 'REDLINE Stripe request failed: ';
        $offset = strpos($log, $marker);
        $details = $offset === false ? null : json_decode(trim(substr($log, $offset + strlen($marker))), true);
        transport_check(is_array($details) && $details['http_status'] === $response['status']
            && $details['curl_errno'] === $response['errno'], $label . ' records structured status and transport code');
        transport_check(!str_contains($log, $syntheticKey) && !str_contains($log, $unsafeMessage)
            && !str_contains($log, 'Authorization') && !array_key_exists('message', $details), $label . ' keeps credentials and raw body out of logs');
        if ($label === 'HTTP 400 schema error') transport_check($details['request_id'] === 'req_schema123'
            && $details['type'] === 'invalid_request_error' && $details['code'] === 'parameter_unknown'
            && $details['param'] === 'payment_method_types', 'safe Stripe request ID and schema fields retained');
        if ($label === 'malicious error fields') transport_check($details['request_id'] === ''
            && !array_key_exists('type', $details) && !array_key_exists('code', $details)
            && !array_key_exists('param', $details), 'unsafe, credential-like and oversized diagnostic fields rejected');
    }
} finally {
    unlink($logFile);
}

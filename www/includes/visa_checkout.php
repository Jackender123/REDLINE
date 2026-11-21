<?php
/**
 * REDLINE / Stripe-hosted Visa + card checkout.
 * Never accepts or stores PAN, CVV or cardholder security data.
 * All ledger writes require server-to-server verified Stripe payment sessions.
 */
require_once __DIR__ . '/../config/config.php';

// Keep Checkout's request schema independent of the account's Dashboard default.
const RL_VISA_STRIPE_API_VERSION = '2026-11-18.endive';

/**
 * Stripe settings are read from host environment variables first.
 * On shared hosting without environment-variable support, REDLINE also accepts
 * a private PHP config file one directory above the actual DOCUMENT_ROOT:
 *   dirname($_SERVER['DOCUMENT_ROOT']) . '/redline-stripe.private.php'
 * The private file MUST be outside public_html / the web-accessible directory.
 * Never put Stripe secrets inside www, admin Settings, JavaScript or a database.
 */
function rl_visa_private_settings(): array
{
    static $settings = null;
    if ($settings !== null) return $settings;
    $settings = [];

    $documentRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $realWebRoot = $documentRoot !== '' ? realpath($documentRoot) : false;
    // Fail closed: without a trusted document root, only server env vars work.
    if ($realWebRoot === false || !is_dir($realWebRoot)) return $settings;

    $file = dirname($realWebRoot) . DIRECTORY_SEPARATOR . 'redline-stripe.private.php';
    $realFile = realpath($file);
    if ($realFile === false || !is_file($realFile) || !is_readable($realFile)) return $settings;
    // Refuse symlinks resolving back into public web content.
    $webPrefix = rtrim($realWebRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($realFile === $realWebRoot || str_starts_with($realFile, $webPrefix)) return $settings;

    try {
        $loaded = require $realFile;
        if (is_array($loaded)) $settings = $loaded;
    } catch (Throwable $e) {
        error_log('REDLINE Stripe private configuration could not be loaded.');
    }
    return $settings;
}

function rl_visa_setting(string $name): string
{
    $environment = getenv($name);
    if (is_string($environment) && trim($environment) !== '') return trim($environment);
    $private = rl_visa_private_settings();
    $value = $private[$name] ?? '';
    return is_string($value) ? trim($value) : '';
}

/** Test keys must have Stripe-like syntax; filenames/placeholders must not activate checkout. */
function rl_visa_valid_api_key(string $key, string $mode): bool
{
    $prefix = $mode === 'live' ? 'sk_live_' : 'sk_test_';
    return str_starts_with($key, $prefix)
        && preg_match('/^[A-Za-z0-9]{16,}$/D', substr($key, strlen($prefix))) === 1;
}

function rl_visa_valid_webhook_secret(string $secret): bool
{
    return preg_match('/^whsec_[A-Za-z0-9]{16,}$/D', $secret) === 1;
}

/** Admin-only, sanitized diagnostic: never displays paths or secret values. */
function rl_visa_private_file_diagnostic(): string
{
    $documentRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $realWebRoot = $documentRoot !== '' ? realpath($documentRoot) : false;
    if ($realWebRoot === false || !is_dir($realWebRoot)) return 'Document root not available. Use server environment variables.';
    $candidate = dirname($realWebRoot) . DIRECTORY_SEPARATOR . 'redline-stripe.private.php';
    if (!file_exists($candidate)) return 'Private file not found outside document root.';
    if (!is_file($candidate) || !is_readable($candidate)) return 'Private file exists but is not readable by PHP.';
    $realFile = realpath($candidate);
    $webPrefix = rtrim($realWebRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($realFile === false || $realFile === $realWebRoot || str_starts_with($realFile, $webPrefix))
        return 'Private file path is unsafe or cannot be resolved.';
    return rl_visa_private_settings() ? 'Private file loaded.' : 'Private file exists but does not return usable settings.';
}

/** Non-sensitive status flags, safe for the Admin Settings screen. */
function rl_visa_configuration_checks(?PDO $db = null): array
{
    $mode = rl_visa_mode();
    $key = rl_visa_setting('REDLINE_STRIPE_SECRET_KEY');
    $signing = rl_visa_setting('REDLINE_STRIPE_WEBHOOK_SECRET');
    $checks = [
        'PHP cURL extension enabled' => function_exists('curl_init'),
        'Stripe secret key for ' . strtoupper($mode) . ' mode' => rl_visa_valid_api_key($key, $mode),
        'Stripe webhook signing secret' => rl_visa_valid_webhook_secret($signing),
        'Valid public HTTPS website URL' => rl_visa_public_base() !== null,
    ];
    if ($db !== null) $checks['Card checkout database table installed'] = rl_visa_storage_ready($db);
    return $checks;
}

/** Read-only readiness check; migrations run separately, never during checkout. */
function rl_visa_storage_ready(PDO $db): bool
{
    try {
        $db->query('SELECT id, invoice_id, customer_id, request_key, stripe_session_id,
            stripe_payment_intent_id, session_url, amount, status, expires_at
            FROM visa_checkout_attempts LIMIT 0');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function rl_visa_mode(): string
{
    return strtolower((string)(rl_visa_setting('REDLINE_STRIPE_MODE') ?: 'test')) === 'live' ? 'live' : 'test';
}

function rl_visa_public_base(): ?string
{
    $url = rtrim(trim((string)(rl_visa_setting('REDLINE_PUBLIC_URL') ?: '')), '/');
    $parts = $url !== '' ? parse_url($url) : false;
    $scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
    if (!is_array($parts) || empty($parts['host']) || !in_array($scheme, ['http', 'https'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return null;
    $host = trim(strtolower((string)$parts['host']), '[]');
    if ($scheme !== 'https' && !(rl_visa_mode() === 'test' && in_array($host, ['localhost', '127.0.0.1', '::1'], true))) return null;
    return $url;
}

function rl_visa_configured(?PDO $db = null): bool
{
    return !in_array(false, rl_visa_configuration_checks($db), true);
}

function rl_visa_public_url(string $path): string
{
    $base = rl_visa_public_base();
    if ($base === null) throw new RuntimeException('The public HTTPS URL is not configured.');
    return $base . '/' . ltrim($path, '/');
}

function rl_visa_cents(string|float|int $amount): int
{
    if (!is_numeric((string)$amount)) throw new RuntimeException('Invalid payment amount.');
    return (int) round(((float)$amount) * 100);
}

function rl_visa_customer_invoice(PDO $db, int $invoiceId, int $customerId, bool $lock = false): ?array
{
    $sql = "SELECT i.id, i.invoice_code, i.grand_total, i.payment_status,
            COALESCE((SELECT SUM(CASE WHEN t.transaction_type='payment' THEN t.amount ELSE -t.amount END)
              FROM cash_transactions t WHERE t.invoice_id=i.id AND t.status='completed'),0) AS paid
            FROM invoices i JOIN repair_orders ro ON ro.id=i.order_id
            LEFT JOIN vehicles v ON v.id=ro.vehicle_id
            WHERE i.id=? AND COALESCE(ro.customer_id,v.customer_id)=?";
    if ($lock) $sql .= ' FOR UPDATE';
    $q=$db->prepare($sql); $q->execute([$invoiceId,$customerId]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

function rl_visa_balance_cents(array $invoice): int
{
    return max(0,rl_visa_cents((string)$invoice['grand_total'])-rl_visa_cents((string)$invoice['paid']));
}

/** Stripe v1 API, no external SDK required. JSON decoded response is never trusted without validation. */
function rl_visa_request(string $method,string $path,?array $data=null,?string $idempotencyKey=null): array
{
    if (!rl_visa_configured()) throw new RuntimeException('Visa/card payments are not configured.');
    if (!preg_match('~^/(?:checkout/sessions(?:/cs_[A-Za-z0-9_]+)?)$~D',$path)) {
        throw new RuntimeException('Invalid checkout API endpoint.');
    }
    if (!in_array($method,['GET','POST'],true)) throw new RuntimeException('Invalid Stripe API method.');
    $headers=['Authorization: Bearer '.rl_visa_setting('REDLINE_STRIPE_SECRET_KEY'),'Accept: application/json',
        'Stripe-Version: '.RL_VISA_STRIPE_API_VERSION];
    if ($idempotencyKey !== null) $headers[]='Idempotency-Key: '.$idempotencyKey;
    $requestId='';
    $h=curl_init('https://api.stripe.com/v1'.$path);
    curl_setopt_array($h,[
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>35, CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_HEADERFUNCTION=>static function ($handle,string $line) use (&$requestId): int {
            if (preg_match('/^Request-Id:\s*(req_[A-Za-z0-9]+)\s*$/iD',trim($line),$match)) $requestId=$match[1];
            return strlen($line);
        },
    ]);
    if ($data !== null) {
        curl_setopt($h,CURLOPT_POSTFIELDS,http_build_query($data,'','&',PHP_QUERY_RFC1738));
        $headers[]='Content-Type: application/x-www-form-urlencoded';
        curl_setopt($h,CURLOPT_HTTPHEADER,$headers);
    }
    $raw=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);$curlCode=curl_errno($h);unset($h);
    $decoded=is_string($raw)?json_decode($raw,true):null;
    if ($status<200 || $status>=300 || !is_array($decoded)) {
        // Error messages/bodies can contain credentials or customer input. Log only
        // bounded machine-readable fields and Stripe's request ID for diagnosis.
        $details=['http_status'=>$status,'curl_errno'=>$curlCode,'request_id'=>$requestId];
        foreach (['type','code','param'] as $field) {
            $value=$decoded['error'][$field]??null;
            if (is_string($value) && preg_match('/^[A-Za-z0-9_.\[\]-]{1,120}$/D',$value)
                && !preg_match('/(?:sk|rk|pk)_(?:test|live)_|whsec_/',$value)) $details[$field]=$value;
        }
        error_log('REDLINE Stripe request failed: '.json_encode($details));
        throw new RuntimeException('Card payment provider is temporarily unavailable. Please try again.');
    }
    return $decoded;
}

function rl_visa_checkout_url(array $session): ?string
{
    $url=(string)($session['url']??'');
    $parts=$url!==''?parse_url($url):false;
    if (!is_array($parts) || strtolower((string)($parts['scheme']??''))!=='https'
        || strtolower((string)($parts['host']??''))!=='checkout.stripe.com'
        || isset($parts['user']) || isset($parts['pass'])) return null;
    return $url;
}

/** Exact amount + ownership + merchant reference + provider payment state required. */
function rl_visa_validate_session(array $session,array $attempt): ?string
{
    if ((string)($session['id']??'')!==(string)$attempt['stripe_session_id']) return null;
    if (($session['mode']??'')!=='payment' || ($session['status']??'')!=='complete' || ($session['payment_status']??'')!=='paid') return null;
    if (strtolower((string)($session['currency']??''))!=='usd') return null;
    if (!is_numeric($session['amount_total']??null) || (int)$session['amount_total']!==rl_visa_cents((string)$attempt['amount'])) return null;
    if ((string)($session['client_reference_id']??'')!=='redline-invoice-'.(int)$attempt['invoice_id']) return null;
    if ((string)($session['metadata']['invoice_id']??'')!==(string)(int)$attempt['invoice_id']
        || (string)($session['metadata']['customer_id']??'')!==(string)(int)$attempt['customer_id']
        || (string)($session['metadata']['attempt_key']??'')!==(string)$attempt['request_key']) return null;
    $intent=(string)($session['payment_intent']??'');
    return preg_match('/^pi_[A-Za-z0-9]+$/D',$intent)?$intent:null;
}

/** Stripe verifies payment; DB transaction verifies original outstanding balance and deduplicates. */
function rl_visa_reconcile_session(PDO $db,array $session): string
{
    $sessionId=(string)($session['id']??'');
    if (!preg_match('/^cs_(test|live)_[A-Za-z0-9_]+$/D',$sessionId)) throw new RuntimeException('Invalid checkout session.');
    $db->beginTransaction();
    try {
        // Lookup attempt; payment write locks invoice first to match the Admin payment lock order.
        $q=$db->prepare('SELECT invoice_id,customer_id FROM visa_checkout_attempts WHERE stripe_session_id=?');
        $q->execute([$sessionId]);$info=$q->fetch(PDO::FETCH_ASSOC);
        if (!$info) throw new RuntimeException('Payment record was not found. Contact the workshop.');
        $invoice=rl_visa_customer_invoice($db,(int)$info['invoice_id'],(int)$info['customer_id'],true);
        if (!$invoice) throw new RuntimeException('Invoice ownership could not be verified.');
        $q=$db->prepare('SELECT * FROM visa_checkout_attempts WHERE stripe_session_id=? FOR UPDATE');
        $q->execute([$sessionId]);$attempt=$q->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) throw new RuntimeException('Checkout attempt no longer exists.');
        if ($attempt['status']==='completed') {$db->commit();return 'completed';}
        if ($attempt['status']==='needs_review') {$db->commit();return 'needs_review';}
        $intent=rl_visa_validate_session($session,$attempt);
        if ($intent===null) throw new RuntimeException('Stripe has not verified the expected USD invoice payment.');
        if (!in_array($attempt['status'],['created','creating','failed'],true)) throw new RuntimeException('Payment needs workshop review.');
        if (!in_array($invoice['payment_status'],['unpaid','partial'],true)
            || rl_visa_balance_cents($invoice)!==rl_visa_cents((string)$attempt['amount'])) {
            $db->prepare("UPDATE visa_checkout_attempts SET status='needs_review',stripe_payment_intent_id=? WHERE id=?")
                ->execute([$intent,(int)$attempt['id']]);
            $db->commit();
            error_log('REDLINE VISA: Captured card payment requires manual refund/reconciliation; session='.$sessionId);
            return 'needs_review';
        }
        $code='TX-ST-'.strtoupper(substr(hash('sha256',$intent),0,24));
        $db->prepare("INSERT INTO cash_transactions
            (transaction_code,invoice_id,transaction_type,amount,payment_method,note,created_by)
            VALUES (?,?,'payment',?,'card',?,?)")
            ->execute([$code,(int)$attempt['invoice_id'],$attempt['amount'],'Stripe online card payment (intent '.$intent.')',(int)$attempt['customer_id']]);
        $next=rl_visa_balance_cents($invoice)===rl_visa_cents((string)$attempt['amount'])?'paid':'partial';
        $db->prepare("UPDATE invoices SET payment_status=?,payment_method='card',updated_at=NOW() WHERE id=?")
            ->execute([$next,(int)$attempt['invoice_id']]);
        $db->prepare("UPDATE visa_checkout_attempts SET status='completed',stripe_payment_intent_id=? WHERE id=?")
            ->execute([$intent,(int)$attempt['id']]);
        $db->commit();return 'completed';
    } catch(Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** Webhook signature verification; Stripe v1 HMAC-SHA256 with 5-minute replay window. */
function rl_visa_verify_webhook(string $payload,string $header,string $secret,?int $now=null): bool
{
    if ($secret==='' || $header==='' || strlen($payload)>1000000) return false;
    $timestamp=null;$sigs=[];
    foreach (explode(',',$header) as $part) {
        $pair=explode('=',trim($part),2);
        if(count($pair)!==2) continue;
        if($pair[0]==='t' && ctype_digit($pair[1])) $timestamp=(int)$pair[1];
        if($pair[0]==='v1' && preg_match('/^[a-f0-9]{64}$/iD',$pair[1])) $sigs[]=$pair[1];
    }
    if($timestamp===null || abs(($now??time())-$timestamp)>300) return false;
    $valid=hash_hmac('sha256',$timestamp.'.'.$payload,$secret);
    foreach($sigs as $sig) if(hash_equals($valid,$sig)) return true;
    return false;
}

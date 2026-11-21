<?php
/** Customer return does not prove payment. Retrieve the Stripe session server-side. */
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/visa_checkout.php';
$user=require_login();header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');
$key=(string)($_GET['attempt']??'');$sid=(string)($_GET['session_id']??'');
$error='';$done=false;$cancel=isset($_GET['cancel']);
$back=app_url('customer/index.php').'?page=invoices';$attempt=null;
if(!preg_match('/^[a-f0-9]{32}$/D',$key)) {$error='Invalid checkout reference.';http_response_code(400);}
else {
    try {
        $q=db()->prepare('SELECT * FROM visa_checkout_attempts WHERE request_key=? AND customer_id=? LIMIT 1');
        $q->execute([$key,(int)$user['id']]);$attempt=$q->fetch(PDO::FETCH_ASSOC)?:null;
        if(!$attempt){$error='Payment session not found for your account.';http_response_code(404);}
        else {
            $back.='&invoice='.(int)$attempt['invoice_id'].'#invoice-detail';
            $done=$attempt['status']==='completed';
            if(!$done && !$cancel && $sid!=='') {
                if(!preg_match('/^cs_(test|live)_[A-Za-z0-9_]+$/D',$sid)||!hash_equals((string)$attempt['stripe_session_id'],$sid)) {
                    $error='The Stripe session does not match this invoice.';
                } elseif(!rl_visa_configured()) {
                    $error='Card payment verification is unavailable. Please contact the workshop.';
                } else {
                    $session=rl_visa_request('GET','/checkout/sessions/'.rawurlencode($sid));
                    $result=rl_visa_reconcile_session(db(),$session);
                    if($result==='completed') {$done=true;header('Location: '.app_url('customer/index.php').'?page=invoices&invoice='.(int)$attempt['invoice_id'].'&payment=success#invoice-detail',true,303);exit;}
                    $error='The provider received a payment, but the invoice requires workshop reconciliation. Do not pay again.';
                }
            }
        }
    }catch(Throwable $e){error_log('REDLINE Visa return: '.$e->getMessage());$error='Card payment confirmation is pending. Do not retry payment until the workshop verifies it.';}
}
function rl_visa_html(string $v): string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Visa card checkout / REDLINE</title><style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#09111e;color:#f8fafc;font-family:Arial,Helvetica,sans-serif;padding:20px}
main{width:min(520px,100%);background:#101c2c;border:1px solid #374b63;border-radius:18px;padding:30px;box-shadow:0 28px 60px #0006}
h1{font-size:26px}.brand{font-weight:800;color:#ff5972;letter-spacing:.15em;font-size:12px}p{color:#c8d4e3;line-height:1.65}.notice{padding:14px;border-radius:9px;background:#433021;border:1px solid #b27c31;color:#ffe4ad}.ok{background:#113d33;border-color:#00a47b;color:#c9ffeb}
a{display:block;background:#ef4057;border-radius:9px;padding:14px;color:white;text-align:center;text-decoration:none;font-weight:bold;margin-top:22px}
</style></head><body><main><div class="brand">REDLINE / VISA &amp; CARD CHECKOUT</div>
<h1><?= $done?'Payment verified':($cancel?'Payment not completed':'Payment verification') ?></h1>
<?php if($attempt):?><p>Invoice #<?= (int)$attempt['invoice_id'] ?> · $<?= rl_visa_html(number_format((float)$attempt['amount'],2)) ?> USD</p><?php endif; ?>
<?php if($error):?><p class="notice" role="alert"><?= rl_visa_html($error) ?></p><?php endif;?>
<?php if($done):?><p class="notice ok">Your card payment was verified and the invoice balance updated.</p>
<?php elseif($cancel):?><p>Checkout was cancelled. No new payment has been recorded in REDLINE. If your card was charged, contact the workshop before retrying.</p>
<?php else: ?><p>Only a completed charge verified with Stripe can mark your invoice as paid. If you just paid, allow the workshop to check the transaction.</p><?php endif; ?>
<a href="<?= rl_visa_html($back) ?>">Return to Invoices</a></main></body></html>

<?php
/** Start Stripe-hosted USD card checkout for an invoice owned by the signed-in customer. */
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/visa_checkout.php';
$user=require_login();
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD']!=='POST') {http_response_code(405);header('Allow: POST');exit('Method not allowed.');}
$invoiceId=filter_var($_POST['invoice_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
$back=app_url('customer/index.php').'?page=invoices'.($invoiceId?'&invoice='.$invoiceId:'').'#invoice-detail';
function rl_visa_start_fail(string $message,string $url): never
{
    $_SESSION['visa_checkout_error']=$message;
    header('Location: '.$url,true,303);exit;
}
if (!csrf_valid($_POST['csrf']??null)) rl_visa_start_fail('Your session expired. Reload the invoice and try again.',$back);
if (!$invoiceId) rl_visa_start_fail('Choose a valid invoice.',$back);
if (!rl_visa_configured()) rl_visa_start_fail('Card checkout is not configured by the workshop.',$back);
$key=null;
try {
    $db=db();
    if (!rl_visa_storage_ready($db)) throw new RuntimeException('Card checkout setup is incomplete. Please contact the workshop.');
    $db->beginTransaction();
    $invoice=rl_visa_customer_invoice($db,$invoiceId,(int)$user['id'],true);
    if (!$invoice) throw new RuntimeException('Invoice not found for your account.');
    if (!in_array($invoice['payment_status'],['unpaid','partial'],true)) throw new RuntimeException('This invoice cannot be paid online.');
    $amount=rl_visa_balance_cents($invoice);
    if($amount<=0) throw new RuntimeException('Your invoice has no outstanding balance.');
    // Do not offer a second live payment link while the previous 30-minute checkout is active.
    $db->prepare("UPDATE visa_checkout_attempts SET status='failed' WHERE invoice_id=? AND status IN ('creating','created') AND expires_at<NOW()")
       ->execute([$invoiceId]);
    $q=$db->prepare("SELECT id,status,stripe_session_id,session_url FROM visa_checkout_attempts
        WHERE invoice_id=? AND status IN ('creating','created') AND expires_at>=NOW() ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $q->execute([$invoiceId]);$existing=$q->fetch(PDO::FETCH_ASSOC);
    if($existing) {
        $db->commit();
        // Resume a still-open checkout instead of opening a second charge for the same invoice.
        $existingId=(string)($existing['stripe_session_id']??'');
        if($existing['status']==='created' && preg_match('/^cs_(test|live)_[A-Za-z0-9_]+$/D',$existingId)) {
            $old=rl_visa_request('GET','/checkout/sessions/'.rawurlencode($existingId));
            $oldUrl=rl_visa_checkout_url($old);
            $oldMeta=$old['metadata']??[];
            if(($old['status']??'')==='open' && $oldUrl!==null
                && strtolower((string)($old['currency']??''))==='usd'
                && (int)($old['amount_total']??0)===$amount
                && (string)($old['client_reference_id']??'')==='redline-invoice-'.$invoiceId
                && (string)($oldMeta['customer_id']??'')===(string)(int)$user['id']) {
                header('Location: '.$oldUrl,true,303);exit;
            }
            if(($old['status']??'')==='complete' && ($old['payment_status']??'')==='paid') {
                $result=rl_visa_reconcile_session($db,$old);
                if($result==='completed') {header('Location: '.app_url('customer/index.php').'?page=invoices&invoice='.$invoiceId.'&payment=success#invoice-detail',true,303);exit;}
            }
        }
        throw new RuntimeException('A previous checkout is still being confirmed. Contact the workshop before paying again.');
    }
    $key=bin2hex(random_bytes(16));
    $db->prepare("INSERT INTO visa_checkout_attempts (invoice_id,customer_id,request_key,amount,status,expires_at)
        VALUES (?,?,?,?,'creating',DATE_ADD(NOW(),INTERVAL 2 MINUTE))")
        ->execute([$invoiceId,(int)$user['id'],$key,number_format($amount/100,2,'.','')]);
    $db->commit();
    $returnBase=rl_visa_public_url('customer/visa_return.php').'?attempt='.$key;
    $params=[
        'mode'=>'payment', 'allowed_payment_method_types'=>['card'],
        'client_reference_id'=>'redline-invoice-'.$invoiceId,
        'line_items'=>[[
            'price_data'=>['currency'=>'usd','unit_amount'=>$amount,'product_data'=>['name'=>'REDLINE Repair Invoice '.$invoice['invoice_code']]],
            'quantity'=>1,
        ]],
        'metadata'=>['invoice_id'=>(string)$invoiceId,'customer_id'=>(string)(int)$user['id'],'attempt_key'=>$key],
        'payment_intent_data'=>['metadata'=>['invoice_id'=>(string)$invoiceId,'attempt_key'=>$key]],
        'success_url'=>$returnBase.'&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'=>$returnBase.'&cancel=1',
        'expires_at'=>time()+3600,
    ];
    $session=rl_visa_request('POST','/checkout/sessions',$params,'redline-visa-'.$key);
    $id=(string)($session['id']??'');$url=rl_visa_checkout_url($session);
    if(!preg_match('/^cs_(test|live)_[A-Za-z0-9_]+$/D',$id)||$url===null) throw new RuntimeException('Stripe did not return a verified checkout link.');
    $stmt=$db->prepare("UPDATE visa_checkout_attempts SET stripe_session_id=?,session_url=?,status='created',expires_at=FROM_UNIXTIME(?)
        WHERE request_key=? AND status='creating'");
    $stmt->execute([$id,$url,(int)($session['expires_at']??(time()+3600)),$key]);
    if($stmt->rowCount()!==1) throw new RuntimeException('Could not save the checkout session. Contact the workshop.');
    header('Location: '.$url,true,303);exit;
} catch(Throwable $e) {
    if(isset($db)&&$db->inTransaction()) $db->rollBack();
    if($key!==null&&isset($db)) try {$db->prepare("UPDATE visa_checkout_attempts SET status='failed' WHERE request_key=? AND status='creating'")->execute([$key]);}catch(Throwable $ignore){}
    error_log('REDLINE Visa checkout start: '.$e->getMessage());
    rl_visa_start_fail($e instanceof RuntimeException?$e->getMessage():'Could not start the card checkout. Please try again.',$back);
}

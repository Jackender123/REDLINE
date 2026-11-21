<?php
/** Pure tests; do not use real Stripe credentials or MySQL. */
require_once __DIR__.'/../includes/visa_checkout.php';
function ensure(bool $expr,string $label): void {if(!$expr) throw new RuntimeException('FAILED: '.$label);echo "PASS $label\n";}
$key='0123456789abcdef0123456789abcdef';
$attempt=['stripe_session_id'=>'cs_test_abcd1','amount'=>'170.00','invoice_id'=>12,'customer_id'=>8,'request_key'=>$key];
$session=['id'=>'cs_test_abcd1','mode'=>'payment','status'=>'complete','payment_status'=>'paid','currency'=>'usd','amount_total'=>17000,'client_reference_id'=>'redline-invoice-12','metadata'=>['invoice_id'=>'12','customer_id'=>'8','attempt_key'=>$key],'payment_intent'=>'pi_test1234'];
ensure(rl_visa_validate_session($session,$attempt)==='pi_test1234','verified USD session / same customer / same invoice');
foreach([
 'wrong currency'=>['currency'=>'vnd'],
 'wrong amount'=>['amount_total'=>17001],
 'not paid'=>['payment_status'=>'unpaid'],
 'not completed'=>['status'=>'open'],
 'wrong checkout ID'=>['id'=>'cs_test_other'],
 'wrong invoice ref'=>['client_reference_id'=>'redline-invoice-2'],
 'wrong customer'=>['metadata'=>['invoice_id'=>'12','customer_id'=>'9','attempt_key'=>$key]],
 'wrong attempt'=>['metadata'=>['invoice_id'=>'12','customer_id'=>'8','attempt_key'=>'other']],
 'missing payment intent'=>['payment_intent'=>''],
] as $label=>$change){ensure(rl_visa_validate_session(array_replace($session,$change),$attempt)===null,'reject '.$label);}
ensure(rl_visa_checkout_url(['url'=>'https://checkout.stripe.com/c/pay/abc'])!==null,'accept secure Stripe Checkout URL');
foreach(['https://checkout.stripe.com.evil.example/path','https://stripe.com/pay','http://checkout.stripe.com/c/pay','javascript:alert(1)'] as $url) ensure(rl_visa_checkout_url(['url'=>$url])===null,'block non-provider redirect');
$raw='{"id":"evt_card_test","type":"checkout.session.completed"}';$stamp=time();
$signature=hash_hmac('sha256',$stamp.'.'.$raw,'whsec_testkey');
ensure(rl_visa_verify_webhook($raw,"t=$stamp,v1=$signature",'whsec_testkey',$stamp),'valid signed Stripe webhook');
ensure(!rl_visa_verify_webhook($raw." ","t=$stamp,v1=$signature",'whsec_testkey',$stamp),'reject tampered signed payload');
ensure(!rl_visa_verify_webhook($raw,"t=$stamp,v1=$signature",'whsec_wrongkey',$stamp),'reject wrong signing secret');
ensure(!rl_visa_verify_webhook($raw,"t=$stamp,v1=$signature",'whsec_testkey',$stamp+400),'reject expired signed payload');
ensure(rl_visa_cents('170.00')===17000 && rl_visa_balance_cents(['grand_total'=>'170.00','paid'=>'50.00'])===12000,'server-side USD cents and balance');
ensure(!rl_visa_configured(),'gateway disabled without merchant keys');

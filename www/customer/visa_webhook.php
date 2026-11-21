<?php
/** Signed Stripe webhook; the browser does not call this endpoint. */
require_once __DIR__.'/../includes/visa_checkout.php';
require_once __DIR__.'/../config/database.php';
header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='POST') {http_response_code(405);exit('Method not allowed');}
$raw=file_get_contents('php://input');
if(!is_string($raw)||!rl_visa_verify_webhook($raw,(string)($_SERVER['HTTP_STRIPE_SIGNATURE']??''),(string)(rl_visa_setting('REDLINE_STRIPE_WEBHOOK_SECRET')?:''))) {
    http_response_code(400);exit('Invalid signature');
}
$event=json_decode($raw,true);
if(!is_array($event)||empty($event['type'])) {http_response_code(400);exit('Invalid event');}
if(in_array((string)$event['type'],['checkout.session.completed','checkout.session.async_payment_succeeded'],true)) {
    $object=$event['data']['object']??[];
    $sid=is_array($object)?(string)($object['id']??''):'';
    if(!preg_match('/^cs_(test|live)_[A-Za-z0-9_]+$/D',$sid)){http_response_code(400);exit('Invalid session');}
    try {
        // Fetch provider-confirmed current state; never trust redirect/query values.
        $session=rl_visa_request('GET','/checkout/sessions/'.rawurlencode($sid));
        if(($session['payment_status']??'')==='paid') rl_visa_reconcile_session(db(),$session);
    } catch(Throwable $e) {
        error_log('REDLINE Visa webhook reconcile: '.$e->getMessage());
        http_response_code(500);exit('Reconciliation pending');
    }
}
http_response_code(200);exit('OK');

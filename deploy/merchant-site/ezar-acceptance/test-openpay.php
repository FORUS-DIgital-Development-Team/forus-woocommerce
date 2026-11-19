<?php
// Standalone contract-safety tests; no network, wallet or database access.
define('ABSPATH', __DIR__);
function is_wp_error($value) { return $value instanceof Exception; }
function wp_remote_retrieve_response_code($r) { return $r['response']['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
require __DIR__.'/openpay-client.php';
$passed=0;
function check($ok,$label) { global $passed; if(!$ok) throw new Exception('FAIL: '.$label); $passed++; }
function rejects($fn,$label) { $thrown=false; try{$fn();}catch(Throwable $e){$thrown=true;} check($thrown,$label); }
$config=array('environment'=>'sbx','api_key'=>'test-key','api_secret'=>'test-secret','account_id'=>'11111111-1111-1111-1111-111111111111','token_id'=>'22222222-2222-2222-2222-222222222222');
$calls=array();
$transport=function($url,$args)use(&$calls){$calls[]=array($url,$args);return array('response'=>array('code'=>200),'body'=>json_encode(array('success'=>true,'result'=>array('id'=>'33333333-3333-3333-3333-333333333333'))));};
foreach(array('prod','production','','sandbox') as $env) { $bad=$config; $bad['environment']=$env; rejects(function()use($bad,$transport){new FORUS_Ezar_OpenPay_Client($bad,$transport);},'Only sbx is accepted'); }
check(count($calls)===0,'Production rejected before I/O');
$client=new FORUS_Ezar_OpenPay_Client($config,$transport);
foreach(array('0','-1','1.001','1e2','1,00','01.00','10000000',1.23,'NaN') as $amount) rejects(function()use($amount){FORUS_Ezar_OpenPay_Client::amount_minor($amount);},'Invalid amount refused');
check(FORUS_Ezar_OpenPay_Client::amount_minor('149.99')===14999,'Exact decimal amount');
rejects(function()use($client){$client->create_invoice('1','unsafe customer reference');},'References restricted');
rejects(function()use($client){$client->create_invoice('1','FORUS-test','https://example.com/callback');},'Unverified callbacks refused');
$client->create_invoice('149.99','FORUS-test-order');
list($url,$args)=$calls[0];
$path='/sbx/openpay/payment?accountId='.$config['account_id'];
check($url==='https://wowapi.api.cloudgate.dev'.$path,'Bound receiving account and trusted origin');
$body=json_decode($args['body'],true);
check($body['amount']===149.99 && $body['tokenId']===$config['token_id'] && $body['rewardsEarnedPercentage']===0,'Exact invoice with no rewards');
check($args['redirection']===0 && $args['sslverify']===true,'No redirects or insecure TLS');
check(preg_match('/^\d{13}$/D',$args['headers']['X-Timestamp'])===1,'Milliseconds timestamp');
check(hash_equals(hash_hmac('sha512',$args['headers']['X-Timestamp'].'POST'.$path.$args['body'],'test-secret'),$args['headers']['X-Authentication-Signature']),'Signs exact path query and body');
check(!isset($args['headers']['Authorization']) && !in_array('test-secret',$args['headers'],true),'No bearer or raw signing secret transmitted');
$invoice=array('id'=>'33333333-3333-3333-3333-333333333333','accountId'=>$config['account_id'],'tokenId'=>$config['token_id'],'amount'=>'149.99','description'=>'FORUS-test-order','status'=>'paid');
check($client->assess_invoice($invoice,$invoice)===array('paid'=>false,'reason'=>'provider_settlement_contract_pending'),'Undocumented paid status cannot settle');
foreach(array('accountId','tokenId','id','description') as $field){$tampered=$invoice;$tampered[$field]='wrong';check($client->assess_invoice($tampered,$invoice)['reason']==='binding_mismatch','Mismatched binding refused');}
$tampered=$invoice;$tampered['amount']='1.00';check($client->assess_invoice($tampered,$invoice)['reason']==='amount_mismatch','Amount mismatch refused');
check($client->assess_invoice(array('status'=>'paid'),$invoice)['reason']==='missing_evidence','Callback alone cannot settle');
$n=0;$timeout=new FORUS_Ezar_OpenPay_Client($config,function()use(&$n){$n++;return new Exception('timeout');});
rejects(function()use($timeout){$timeout->create_invoice('1','FORUS-timeout');},'Uncertain invoice reported');check($n===1,'Uncertain creation never retried');
$failed=new FORUS_Ezar_OpenPay_Client($config,function(){return array('response'=>array('code'=>200),'body'=>'{"success":false,"result":{"status":"paid"}}');});
rejects(function()use($failed){$failed->read_invoice('33333333-3333-3333-3333-333333333333');},'Provider error inside HTTP 200 refused');
rejects(function()use($client){$client->read_invoice('../send');},'Path injection refused');
// Actual sandbox failure on 2026-11-19: HTTP 302, application/json, "Found Found - ".
// Even a JSON-looking redirect response must not be accepted as an invoice.
foreach(array('Found Found - ', '{"success":true,"result":{"id":"33333333-3333-3333-3333-333333333333","status":"paid"}}') as $redirect_body) {
    $redirect_calls=0;
    $redirect=new FORUS_Ezar_OpenPay_Client($config,function()use(&$redirect_calls,$redirect_body){
        $redirect_calls++;
        return array('response'=>array('code'=>302),'headers'=>array('location'=>'https://example.com/checkout'),'body'=>$redirect_body);
    });
    rejects(function()use($redirect){$redirect->create_invoice('1','FORUS-redirect');},'Redirect is not an accepted invoice');
    rejects(function()use($redirect){$redirect->read_invoice('33333333-3333-3333-3333-333333333333');},'Redirect is not settlement evidence');
    check($redirect_calls===2,'Redirect responses are never followed or retried');
}
echo "PASS: $passed Open Pay contract-safety checks; no provider calls.\n";

<?php
defined('ABSPATH') || exit;

/** Receiving-side API only. No payer debit, send, withdrawal or refund methods. */
final class FORUS_Ezar_OpenPay_Client {
    private $api_key;
    private $api_secret;
    private $account_id;
    private $token_id;
    private $transport;
    public function __construct($config, $transport = null) {
        if (($config['environment'] ?? '') !== 'sbx') throw new InvalidArgumentException('Only explicitly configured sandbox credentials are supported.');
        foreach (array('api_key','api_secret','account_id','token_id') as $field) {
            if (empty($config[$field]) || !is_string($config[$field])) throw new InvalidArgumentException('Incomplete sandbox configuration.');
        }
        if (!self::guid($config['account_id']) || !self::guid($config['token_id'])) throw new InvalidArgumentException('Invalid receiving account or token.');
        $this->api_key=$config['api_key']; $this->api_secret=$config['api_secret'];
        $this->account_id=strtolower($config['account_id']); $this->token_id=strtolower($config['token_id']);
        $this->transport=$transport ?: 'wp_remote_request';
    }
    private static function guid($value) {
        return is_string($value) && 1===preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value);
    }
    public static function amount_minor($amount) {
        if (!is_string($amount) || !preg_match('/^(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?$/D', $amount, $m)) throw new InvalidArgumentException('A decimal amount with at most two places is required.');
        $minor=(int)$m[1]*100+(int)str_pad($m[2]??'', 2, '0');
        if ($minor<=0) throw new InvalidArgumentException('Amount must be positive.');
        return $minor;
    }
    public static function signature($secret, $timestamp, $method, $path, $body='') {
        return hash_hmac('sha512', $timestamp.strtoupper($method).$path.$body, $secret);
    }
    private function request($method, $relative, $body=null) {
        $path='/sbx'.$relative;
        $json=$body===null?'':json_encode($body, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $timestamp=(string)(int)floor(microtime(true)*1000);
        $response=call_user_func($this->transport, 'https://wowapi.api.cloudgate.dev'.$path, array(
            'method'=>$method, 'timeout'=>20, 'redirection'=>0, 'sslverify'=>true,
            'headers'=>array('Content-Type'=>'application/json','X-Api-Key'=>$this->api_key,'X-Timestamp'=>$timestamp,'X-Authentication-Signature'=>self::signature($this->api_secret,$timestamp,$method,$path,$json)),
            'body'=>$json,
        ));
        // Do not retry invoice creation after timeout: provider idempotency is not yet established.
        if (is_wp_error($response)) throw new RuntimeException('CloudGate request outcome is unknown; reconcile before retrying.');
        $status=wp_remote_retrieve_response_code($response);
        if ($status<200 || $status>=300) throw new RuntimeException('CloudGate request rejected (HTTP '.(int)$status.').');
        $raw=json_decode(wp_remote_retrieve_body($response),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($raw) || ($raw['success']??null)!==true || !isset($raw['result']) || !is_array($raw['result'])) throw new RuntimeException('CloudGate response contract is unverified.');
        return $raw['result'];
    }
    /** Creates an unpaid sandbox invoice only. Account and token cannot be supplied by a shopper. */
    public function create_invoice($amount, $reference, $webhook_url='') {
        $minor=self::amount_minor($amount);
        if (!is_string($reference) || !preg_match('/^FORUS-[A-Za-z0-9-]{1,80}$/D',$reference)) throw new InvalidArgumentException('A shop-owned invoice reference is required.');
        // No callback is sent until its verification contract has been tested.
        if ($webhook_url!=='') throw new InvalidArgumentException('Webhooks remain disabled pending verification.');
        return $this->request('POST','/openpay/payment?accountId='.rawurlencode($this->account_id),array('description'=>$reference,'amount'=>$minor/100,'tokenId'=>$this->token_id,'rewardTokenId'=>'','rewardsEarnedPercentage'=>0,'webhookUrl'=>''));
    }
    public function read_invoice($invoice_id) {
        if (!self::guid($invoice_id)) throw new InvalidArgumentException('Invalid invoice ID.');
        return $this->request('GET','/openpay/'.rawurlencode($invoice_id));
    }
    /** Never treats a return URL, callback payload or undocumented status as settlement evidence. */
    public function assess_invoice($invoice, $expected) {
        foreach (array('id','accountId','tokenId','amount','description') as $key) {
            if (!array_key_exists($key,$invoice) || !array_key_exists($key,$expected)) return array('paid'=>false,'reason'=>'missing_evidence');
        }
        if (strtolower((string)$invoice['id'])!==strtolower((string)$expected['id']) || strtolower((string)$invoice['accountId'])!==$this->account_id || strtolower((string)$expected['accountId'])!==$this->account_id || strtolower((string)$invoice['tokenId'])!==$this->token_id || strtolower((string)$expected['tokenId'])!==$this->token_id || $invoice['description']!==$expected['description']) return array('paid'=>false,'reason'=>'binding_mismatch');
        try { if (self::amount_minor((string)$invoice['amount'])!==self::amount_minor((string)$expected['amount'])) return array('paid'=>false,'reason'=>'amount_mismatch'); }
        catch (InvalidArgumentException $e) { return array('paid'=>false,'reason'=>'invalid_amount'); }
        return array('paid'=>false,'reason'=>'provider_settlement_contract_pending');
    }
}

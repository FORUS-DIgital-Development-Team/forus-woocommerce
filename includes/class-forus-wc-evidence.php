<?php
/** Pure payment evidence validation. */
final class FORUS_WC_Evidence {
 public static function minor(string $amount): int {
  if (!preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,2}))?$/D', $amount, $m)) { throw new InvalidArgumentException('Unsupported amount precision or range.'); }
  return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
 }
 public static function signature(string $body, string $signature, string $secret): bool {
  return '' !== $secret && strlen($body) <= 65536 && 1 === preg_match('/^[a-f0-9]{64}$/D', $signature) && hash_equals(hash_hmac('sha256', $body, $secret), $signature);
 }
 public static function payment(array $data, array $expected): bool {
  foreach (array('id','reference','currency','merchant_id') as $key) {
   if (!isset($data[$key], $expected[$key]) || !is_string($data[$key]) || '' === $expected[$key] || !hash_equals($expected[$key], $data[$key])) { return false; }
  }
  return isset($data['amount']) && is_int($data['amount']) && $data['amount'] === $expected['amount'];
 }
 public static function redirect($url, array $hosts): bool {
  if (!is_string($url) || preg_match('/[\x00-\x20]/', $url) || false !== strpos($url, chr(92))) { return false; }
  $parts = parse_url($url);
  return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['pass']) && (!isset($parts['port']) || 443 === $parts['port']) && in_array(strtolower($parts['host'] ?? ''), $hosts, true);
 }
}

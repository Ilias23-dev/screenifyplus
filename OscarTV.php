<?php
/**
 * مولد ترويسات Iron - نسخة PHP
 * يطبع JSON جاهز
 */

class IronHeadersGenerator {
    private $cert;
    private $pkg;
    private $fingerprint;
    
    private $GUARD_1 = "OscarTVIronGuard";
    private $GUARD_2 = "IronGuard";
    
    public function __construct($cert_sha256, $pkg_name = "com.drama.mp4") {
        $this->cert = $cert_sha256;
        $this->pkg = $pkg_name;
        $this->fingerprint = substr($cert_sha256, 0, 8);
    }
    
    private function deriveKey() {
        $raw = $this->cert . "|" . $this->pkg;
        $data = $raw;
        $length = strlen($data);
        
        // Stage 1: XOR modulo 7
        $result1 = '';
        for ($i = 0; $i < $length; $i++) {
            $result1 .= chr(ord($data[$i]) ^ ord($this->GUARD_1[$i % 7]));
        }
        
        // Stage 2: Reverse
        $result2 = strrev($result1);
        
        // Stage 3: XOR modulo 9
        $result3 = '';
        for ($i = 0; $i < $length; $i++) {
            $result3 .= chr(ord($result2[$i]) ^ ord($this->GUARD_2[$i % 9]));
        }
        
        // Stage 4: 3x SHA-256
        $pass1 = hash('sha256', $result3, true);
        $pass2 = hash('sha256', $pass1, true);
        return hash('sha256', $pass2, true);
    }
    
    public function generate($url) {
        // استخراج المسار فقط
        $path = parse_url($url, PHP_URL_PATH);
        
        // توليد timestamp و nonce
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(4));
        
        // اشتقاق المفتاح وحساب التوقيع
        $key = $this->deriveKey();
        $payload = $path . "|" . $timestamp . "|" . $nonce;
        $sig = hash_hmac('sha256', $payload, $key);
        
        return [
            'Host' => 'ostvapp.cam',
            'x-iron-sig' => $sig,
            'x-iron-ts' => $timestamp,
            'x-iron-nonce' => $nonce,
            'x-iron-diag' => "f={$this->fingerprint} p={$this->fingerprint} h=0",
            'accept-encoding' => 'gzip',
            'user-agent' => 'okhttp/4.12.0',
            'connection' => 'keep-alive'
        ];
    }
}

// استخدام المولد
$cert = "6e2fcda8631eb49ebcba4ca8ef4c597abe84654c7d3e8096db32bdd21ecf763f";
$gen = new IronHeadersGenerator($cert);

$headers = $gen->generate($_POST['link']);

// طباعة JSON جاهز
header('Content-Type: application/json; charset=utf-8');
echo json_encode($headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>


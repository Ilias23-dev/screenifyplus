<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow your HTML to read it

 $endpoint = isset($_GET['q']) ? $_GET['q'] : '';
if (!$endpoint) {
    http_response_code(400);
    echo json_encode(["error" => "No endpoint provided"]);
    exit;
}

// Strip leading slash if provided
 $endpoint = ltrim($endpoint, '/');
 $url = "https://def.yacinelive.com/api/" . $endpoint;

 $ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "User-Agent: okhttp/4.12.0",
    "Accept: application/json",
    "api_url: http://ver3.yacinelive.com"
]);
// We need the response headers to extract the 't' header
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

 $response = curl_exec($ch);
 $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
 $headers = substr($response, 0, $header_size);
 $body = substr($response, $header_size);
curl_close($ch);

// Extract 't' header
 $t_header = "";
preg_match('/^t:\s*(.+)$/mi', $headers, $matches);
if (!empty($matches[1])) {
    $t_header = trim($matches[1]);
}
if (empty($t_header)) {
    $t_header = (string)time();
}

// Base64 decode
 $decoded = base64_decode(trim($body));
if ($decoded === false) {
    http_response_code(500);
    echo json_encode(["error" => "Base64 decode failed", "raw" => $body]);
    exit;
}

// XOR Decryption
 $key = "c!xZj+N9&G@Ev@vw" . $t_header;
 $key_len = strlen($key);
 $plain = "";

for ($i = 0; $i < strlen($decoded); $i++) {
    $plain .= chr(ord($decoded[$i]) ^ ord($key[$i % $key_len]));
}

// Fix escaped slashes and output
 $plain = str_replace('\/', '/', $plain);
echo $plain;
?>

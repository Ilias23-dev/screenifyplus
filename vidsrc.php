<?php
/**
 * VidSrc PHP Stream Resolver Script
 * Translated from https://github.com/cool-dev-guy/vidsrc.ts
 * 
 * Usage:
 *   GET /vidsrc.php?tmdb=123&type=movie
 *   GET /vidsrc.php?tmdb=456&type=tv&season=1&episode=2
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$tmdbId = $_GET['tmdb'] ?? null;
$type = $_GET['type'] ?? 'movie'; // 'movie' or 'tv'
$season = isset($_GET['season']) ? (int)$_GET['season'] : null;
$episode = isset($_GET['episode']) ? (int)$_GET['episode'] : null;

if (!$tmdbId) {
    echo json_encode(["error" => "Missing 'tmdb' parameter."]);
    exit;
}

global $BASEDOM;
$BASEDOM = "https://whisperingauroras.com";

try {
    $result = tmdbScrape($tmdbId, $type, $season, $episode);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}

// --- Main Scraper function ---
function tmdbScrape($tmdbId, $type, $season, $episode) {
    global $BASEDOM;

    if ($season && $episode && $type === "movie") {
        throw new Exception("Invalid Data: Movies do not have seasons or episodes.");
    }

    $embedUrl = ($type === "movie")
        ? "https://vidsrc.net/embed/{$type}?tmdb={$tmdbId}"
        : "https://vidsrc.net/embed/{$type}?tmdb={$tmdbId}&season={$season}&episode={$episode}";

    // 1. Fetch main embed page
    $embedHtml = httpGet($embedUrl, ["User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)"]);
    if (!$embedHtml) {
        throw new Exception("Failed to fetch embed page.");
    }

    // Parse Title
    $title = "";
    if (preg_match('/<title>([^<]*)</title>/i', $embedHtml, $titleMatch)) {
        $title = trim($titleMatch[1]);
    }

    // Parse base Iframe Source for BASEDOM
    if (preg_match('/<iframe[^>]+src="([^"]+)"/i', $embedHtml, $iframeMatch)) {
        $iframeSrc = $iframeMatch[1];
        if (substr($iframeSrc, 0, 2) === "//") {
            $iframeSrc = "https:" . $iframeSrc;
        }
        $parsedUrl = parse_url($iframeSrc);
        if (isset($parsedUrl['scheme']) && isset($parsedUrl['host'])) {
            $BASEDOM = $parsedUrl['scheme'] . "://" . $parsedUrl['host'];
        }
    }

    // Parse servers: Find class="server" data-hash="..."
    $servers = [];
    if (preg_match_all('/class="server"[^>]*data-hash="([^"]+)"[^>]*>([^<]+)/i', $embedHtml, $serverMatches, PREG_SET_ORDER)) {
        foreach ($serverMatches as $srv) {
            $servers[] = [
                "name" => trim($srv[2]),
                "dataHash" => $srv[1]
            ];
        }
    }

    // 2. Fetch RCP hashes (Multi-curl request pattern for speed)
    $rcpResponses = multiHttpGet($servers, $embedUrl);

    // 3. Extract target script/endpoint from RCP handlers
    $apiResponses = [];
    foreach ($rcpResponses as $hash => $htmlContent) {
        if (preg_match("/src:\s*'([^']*)'/i", $htmlContent, $srcMatch)) {
            $dataPath = $srcMatch[1];
            if (substr($dataPath, 0, 8) === "/prorcp/") {
                $prorcpHash = str_replace("/prorcp/", "", $dataPath);
                $resolvedStream = PRORCPhandler($prorcpHash);
                if ($resolvedStream) {
                    $serverName = "";
                    foreach ($servers as $s) {
                        if ($s['dataHash'] === $hash) {
                            $serverName = $s['name'];
                            break;
                        }
                    }
                    $apiResponses[] = [
                        "name" => $serverName ?: $title,
                        "mediaId" => $tmdbId,
                        "stream" => $resolvedStream,
                        "referer" => $BASEDOM
                    ];
                }
            }
        }
    }

    return $apiResponses;
}

// Handles dynamic script extraction and decryption
function PRORCPhandler($prorcp) {
    global $BASEDOM;

    $prorcpUrl = "{$BASEDOM}/prorcp/{$prorcp}";
    $prorcpResponse = httpGet($prorcpUrl, [
        "Referer: {$BASEDOM}/",
        "User-Agent: Mozilla/5.0"
    ]);

    if (!$prorcpResponse) return null;

    // Find JS file strings
    $scripts = [];
    if (preg_match_all('/<script\s+src="/([^"]*\.js)\?_=([^"]*)"></script>/i', $prorcpResponse, $scriptMatches, PREG_SET_ORDER)) {
        foreach ($scriptMatches as $sMatch) {
            $scripts[] = "{$sMatch[1]}?_={$sMatch[2]}";
        }
    }

    if (empty($scripts)) return null;

    // Pick target decryption JS script
    $chosenScript = $scripts[count($scripts) - 1];
    if (strpos($chosenScript, "cpt.js") !== false && count($scripts) > 1) {
        $chosenScript = $scripts[count($scripts) - 2];
    }

    // Fetch the script
    $jsCode = httpGet("{$BASEDOM}/{$chosenScript}", [
        "Referer: {$BASEDOM}/",
        "User-Agent: Mozilla/5.0"
    ]);

    if (!$jsCode) return null;

    // Regex match key and decryption function name
    if (!preg_match('/\{\}\}window\[([^"]+)\("([^"]+)"\)/i', $jsCode, $decryptMatches)) {
        return null;
    }

    $fnName = trim($decryptMatches[1]);
    $dynamicKey = trim($decryptMatches[2]);

    // Decode element ID
    $elementId = decrypt($dynamicKey, $fnName);
    if (!$elementId) return null;

    // Extract ciphertext inside element container
    $dataRegex = '/id="' . preg_quote($elementId, '/') . '">([^<]*)</i';
    if (!preg_match($dataRegex, $prorcpResponse, $dataMatch)) {
        return null;
    }

    $cipherText = trim($dataMatch[1]);

    // Decrypt the streaming manifest link
    return decrypt($cipherText, $dynamicKey);
}

// --- Multi-Algorithm Decrypt Routing ---
function decrypt($param, $type) {
    switch ($type) {
        Case "LXVUMCoAHJ": return LXVUMCoAHJ($param);
        Case "GuxKGDsA2T": return GuxKGDsA2T($param);
        Case "laM1dAi3vO": return laM1dAi3vO($param);
        Case "nZlUnj2VSo": return nZlUnj2VSo($param);
        Case "Iry9MQXnLs": return Iry9MQXnLs($param);
        Case "IGLImMhWrI": return IGLImMhWrI($param);
        Case "GTAxQyTyBx": return GTAxQyTyBx($param);
        Case "C66jPHx8qu": return C66jPHx8qu($param);
        Case "MyL1IRSfHe": return MyL1IRSfHe($param);
        Case "detdj7JHiK": return detdj7JHiK($param);
        Default: return null;
    }
}

// --- Individual Decryption Routines ---

function LXVUMCoAHJ($str) {
    $reversed = strrev($str);
    $safeBase64 = str_replace(['-', '_'], ['+', '/'], $reversed);
    $decoded = base64_decode($safeBase64);
    $res = "";
    for ($i = 0; $i < strlen($decoded); $i++) {
        $res .= chr(ord($decoded[$i]) - 3);
    }
    return $res;
}

function GuxKGDsA2T($str) {
    $reversed = strrev($str);
    $safeBase64 = str_replace(['-', '_'], ['+', '/'], $reversed);
    $decoded = base64_decode($safeBase64);
    $res = "";
    for ($i = 0; $i < strlen($decoded); $i++) {
        $res .= chr(ord($decoded[$i]) - 7);
    }
    return $res;
}

function laM1dAi3vO($str) {
    $reversed = strrev($str);
    $safeBase64 = str_replace(['-', '_'], ['+', '/'], $reversed);
    $decoded = base64_decode($safeBase64);
    $res = "";
    for ($i = 0; $i < strlen($decoded); $i++) {
        $res .= chr(ord($decoded[$i]) - 5);
    }
    return $res;
}

function nZlUnj2VSo($str) {
    $map = [
        'x' => 'a', 'y' => 'b', 'z' => 'c', 'a' => 'd', 'b' => 'e', 'c' => 'f', 'd' => 'g', 'e' => 'h', 'f' => 'i', 'g' => 'j',
        'h' => 'k', 'i' => 'l', 'j' => 'm', 'k' => 'n', 'l' => 'o', 'm' => 'p', 'n' => 'q', 'o' => 'r', 'p' => 's', 'q' => 't',
        'r' => 'u', 's' => 'v', 't' => 'w', 'u' => 'x', 'v' => 'y', 'w' => 'z',
        'X' => 'A', 'Y' => 'B', 'Z' => 'C', 'A' => 'D', 'B' => 'E', 'C' => 'F', 'D' => 'G', 'E' => 'H', 'F' => 'I', 'G' => 'J',
        'H' => 'K', 'I' => 'L', 'J' => 'M', 'K' => 'N', 'L' => 'O', 'M' => 'P', 'N' => 'Q', 'O' => 'R', 'P' => 'S', 'Q' => 'T',
        'R' => 'U', 'S' => 'V', 'T' => 'W', 'U' => 'X', 'V' => 'Y', 'W' => 'Z'
    ];
    return preg_replace_callback('/[xyzabcdefghijklmnopqrstuvwXYZABCDEFGHIJKLMNOPQRSTUVW]/', function($m) use ($map) {
        return $map[$m[0]] ?? $m[0];
    }, $str);
}

function Iry9MQXnLs($str) {
    $key = "pWB9V)[*4I`nJpp?ozyB~dbr9yt!_n4u";
    preg_match_all('/.{1,2}/', $str, $hexPairs);
    $chars = "";
    foreach ($hexPairs[0] as $hex) {
        $chars .= chr(hexdec($hex));
    }
    $xored = "";
    $kLen = strlen($key);
    for ($i = 0; $i < strlen($chars); $i++) {
        $xored .= chr(ord($chars[$i]) ^ ord($key[$i % $kLen]));
    }
    $res = "";
    for ($i = 0; $i < strlen($xored); $i++) {
        $res .= chr(ord($xored[$i]) - 3);
    }
    return base64_decode($res);
}

function IGLImMhWrI($str) {
    $reversed = strrev($str);
    $rot13 = str_rot13($reversed);
    return base64_decode(strrev($rot13));
}

function GTAxQyTyBx($str) {
    $reversed = strrev($str);
    $skipped = "";
    for ($i = 0; $i < strlen($reversed); $i += 2) {
        $skipped .= $reversed[$i];
    }
    return base64_decode($skipped);
}

function C66jPHx8qu($str) {
    $reversed = strrev($str);
    $key = "X9a(O;FMV2-7VO5x;Ao\x05:dN1NoFs?j,";
    preg_match_all('/.{1,2}/', $reversed, $hexPairs);
    $chars = "";
    foreach ($hexPairs[0] as $hex) {
        $chars .= chr(hexdec($hex));
    }
    $res = "";
    $kLen = strlen($key);
    for ($i = 0; $i < strlen($chars); $i++) {
        $res .= chr(ord($chars[$i]) ^ ord($key[$i % $kLen]));
    }
    return $res;
}

function MyL1IRSfHe($str) {
    $reversed = strrev($str);
    $shifted = "";
    for ($i = 0; $i < strlen($reversed); $i++) {
        $shifted .= chr(ord($reversed[$i]) - 1);
    }
    $res = "";
    for ($i = 0; $i < strlen($shifted); $i += 2) {
        $res .= chr(hexdec(substr($shifted, $i, 2)));
    }
    return $res;
}

function detdj7JHiK($str) {
    $sliced = substr($str, 10, -16);
    $key = "3SAY~#%Y(V%>5d/Yg"\$G[Lh1rK4a;7ok";
    $decoded = base64_decode($sliced);
    $decodedLen = strlen($decoded);
    $keyRepeated = str_pad("", $decodedLen, $key);
    $res = "";
    for ($i = 0; $i < $decodedLen; $i++) {
        $res .= chr(ord($decoded[$i]) ^ ord($keyRepeated[$i]));
    }
    return $res;
}

// --- Network Helper Methods ---

function httpGet($url, $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function multiHttpGet($servers, $referer) {
    global $BASEDOM;
    $mh = curl_multi_init();
    $handles = [];
    $results = [];

    foreach ($servers as $srv) {
        $hash = $srv['dataHash'];
        $url = "{$BASEDOM}/rcp/{$hash}";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Referer: {$referer}",
            "User-Agent: Mozilla/5.0"
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$hash] = $ch;
    }

    $active = null;
    do {
        $mrc = curl_multi_exec($mh, $active);
    } while ($mrc == CURLM_CALL_MULTI_PERFORM);

    while ($active && $mrc == CURLM_OK) {
        if (curl_multi_select($mh) != -1) {
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }
    }

    foreach ($handles as $hash => $ch) {
        $results[$hash] = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
    return $results;
}
?>

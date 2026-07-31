<?php
header('Content-Type: application/json; charset=utf-8');
ignore_user_abort(true);
set_time_limit(30);

function extract_m3u8($tmdb_id, $type = 'movie', $season = 1, $episode = 1) {
    // بناء الرابط الأساسي لـ vidsrc.domains
    if ($type == 'movie') {
        $embed_url = "https://vidsrc.domains/embed/movie/{$tmdb_id}";
    } else {
        $embed_url = "https://vidsrc.domains/embed/tv/{$tmdb_id}/{$season}/{$episode}";
    }

    // دالة مساعدة لجلب المحتوى مع هيدرز قوية
    $fetch = function($url, $referer = null) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'Sec-Fetch-Dest: iframe',
                'Sec-Fetch-Mode: navigate',
                'Upgrade-Insecure-Requests: 1'
            ],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36'
        ]);
        if ($referer) curl_setopt($ch, CURLOPT_REFERER, $referer);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    };

    // الخطوة 1: جلب صفحة الـ Embed للعثور على رابط الـ iframe الداخلي
    $html = $fetch($embed_url);
    if (!$html) return null;

    // البحث عن iframe (يدعم src و data-lazy-src)
    preg_match('/<(?:iframe)[^>]*(?:data-lazy-src|src)=["\']([^"\']*)["\'][^>]*>/i', $html, $iframe_match);
    if (!isset($iframe_match[1])) return null;
    
    $iframe_src = $iframe_match[1];
    if (strpos($iframe_src, '//') === 0) $iframe_src = 'https:' . $iframe_src;
    if (strpos($iframe_src, 'http') !== 0) $iframe_src = 'https://' . ltrim($iframe_src, '/');

    // الخطوة 2: جلب صفحة المشغل (Player) الداخلية
    $player_html = $fetch($iframe_src, $embed_url);
    if (!$player_html) return null;

    // --- استخراج الرابط (خوارزمية اختراق حقيقية) ---

    // أولاً: البحث عن كائن playerConfig (الأحدث والأكثر دقة)
    if (preg_match('/playerConfig\s*=\s*({[^;]*});/i', $player_html, $config_match)) {
        $config = json_decode($config_match[1], true);
        if ($config && isset($config['sources'][0]['src'])) {
            return $config['sources'][0]['src'];
        }
        if ($config && isset($config['file'])) {
            return $config['file'];
        }
    }

    // ثانياً: البحث عن كائن sources في JSON آخر
    if (preg_match('/sources\s*:\s*(\[[^\]]*\])/i', $player_html, $src_match)) {
        $sources = json_decode($src_match[1], true);
        if ($sources && isset($sources[0]['file'])) {
            return $sources[0]['file'];
        }
        if ($sources && isset($sources[0]['src'])) {
            return $sources[0]['src'];
        }
    }

    // ثالثاً: البحث المباشر عن الأنماط النصية للملفات
    $patterns = [
        '/"file"\s*:\s*"([^"]*\.m3u8[^"]*)"/i',
        "/'file'\s*:\s*'([^']*\.m3u8[^']*)'/i",
        '/hls_url\s*[:=]\s*["\']([^"\']*\.m3u8[^"\']*)["\']/i',
        '/source\s*:\s*["\']([^"\']*\.m3u8[^"\']*)["\']/i',
        '/src\s*:\s*["\']([^"\']*\.m3u8[^"\']*)["\']/i'
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $player_html, $match)) {
            $link = $match[1];
            // تنظيف الرابط من أي أحرف شاذة
            $link = str_replace(['\/', '\\'], ['/', ''], $link);
            if (filter_var($link, FILTER_VALIDATE_URL)) return $link;
        }
    }

    return null;
}

// --- معالجة طلب المستخدم (API Endpoint) ---
$tmdb = $_GET['tmdb'] ?? $_GET['id'] ?? null;
$type = $_GET['type'] ?? 'movie';
$season = intval($_GET['season'] ?? 1);
$episode = intval($_GET['episode'] ?? 1);

if (!$tmdb) {
    die(json_encode([
        'status' => false,
        'error' => 'يجب إرسال معرف TMDB. مثال: ?tmdb=550&type=movie'
    ]));
}

$m3u8_link = extract_m3u8($tmdb, $type, $season, $episode);

if ($m3u8_link) {
    echo json_encode([
        'status' => true,
        'data' => [
            'tmdb_id' => $tmdb,
            'type' => $type,
            'season' => ($type == 'tv') ? $season : null,
            'episode' => ($type == 'tv') ? $episode : null,
            'm3u8_direct_link' => $m3u8_link,
            'playable_in_vlc' => true
        ]
    ]);
} else {
    echo json_encode([
        'status' => false,
        'error' => 'فشل الاستخراج. قد يكون المعرف خاطئاً، أو أن السيرفر غير متوافق مع PHP (تأكد من استضافة PHP فعلية).'
    ]);
}
?>
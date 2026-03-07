<?php
// ═══════════════════════════════════════════════════
//  GoProxy  v3.5.1-vercel
// ═══════════════════════════════════════════════════

// ── 配置区 ──────────────────────────────────────────
const PASSWORD         = '123456';
const CONNECT_TIMEOUT  = 8;
const TRANSFER_TIMEOUT = 25;
const BUFSIZE          = 524288;    // 512KB
const HOSTS_DENY       = [];

// ── 全局状态 ─────────────────────────────────────────
$S = [
    'content_type' => 'image/gif',
    'content'      => '',
    'chunked'      => 0,
    'trailer'      => 0,
];

$XOR_KEY = PASSWORD[0];

// ── 预编译正则 ────────────────────────────────────────
const RE_MEDIA = '@^Content-Type:\s*(audio/|image/|video/|application/octet-stream'
               . '|application/dash\+xml|application/x-mpegurl'
               . '|application/vnd\.apple\.mpegurl)@i';
const RE_CHUNK = '@^Transfer-Encoding:\s*chunked@i';

// ────────────────────────────────────────────────────
// 请求解码
// ────────────────────────────────────────────────────
function decode_request(string $data): array {
    $hlen         = unpack('n', $data)[1];
    $headers_data = gzinflate(substr($data, 2, $hlen));
    $body         = substr($data, 2 + $hlen);

    $nl     = strpos($headers_data, "\r\n");
    $rl     = explode(' ', substr($headers_data, 0, $nl), 3);
    $method = $rl[0];
    $url    = $rl[1];

    $headers = $kwargs = [];
    $prefix  = 'X-URLFETCH-';
    $plen    = 11;

    foreach (explode("\r\n", substr($headers_data, $nl + 2)) as $line) {
        if ($line === '') continue;
        $pos = strpos($line, ':');
        if ($pos === false) continue;
        $key   = substr($line, 0, $pos);
        $value = ltrim(substr($line, $pos + 1));

        if (strncasecmp($key, $prefix, $plen) === 0) {
            $kwargs[strtolower(substr($key, $plen))] = $value;
        } else {
            $headers[str_replace(' ', '-', ucwords(str_replace('-', ' ', $key)))] = $value;
        }
    }

    if (isset($headers['Content-Encoding'])) {
        $enc  = strtolower($headers['Content-Encoding']);
        $body = $enc === 'deflate' ? gzinflate($body) : gzdecode($body);
        unset($headers['Content-Encoding']);
        $headers['Content-Length'] = (string)strlen($body);
    }

    return [$method, $url, $headers, $kwargs, $body];
}

// ────────────────────────────────────────────────────
// 输出
// ────────────────────────────────────────────────────
function echo_content(string $content): void {
    global $S, $XOR_KEY;

    if ($S['chunked'] === 1)
        $chunk = $S['content'] === ''
            ? sprintf("%x\r\n%s\r\n", strlen($content), $content)
            : $content;
    else
        $chunk = $content;

    if ($S['content_type'] === 'image/gif')
        $chunk ^= str_repeat($XOR_KEY, strlen($chunk));

    echo $chunk;
    if (!function_exists('fastcgi_finish_request')) flush();
}

// ────────────────────────────────────────────────────
// cURL 响应头回调
// ────────────────────────────────────────────────────
function curl_header_cb($ch, string $header): int {
    global $S;

    $pos = strpos($header, ':');
    $S['content'] .= $pos !== false
        ? str_replace(' ', '-', ucwords(str_replace('-', ' ', substr($header, 0, $pos)))) . substr($header, $pos)
        : $header;

    if (preg_match(RE_MEDIA, $header))
        $S['content_type'] = 'image/x-png';

    if (rtrim($header) === '')
        header('Content-Type: ' . $S['content_type']);

    if (preg_match(RE_CHUNK, $header))
        $S['chunked'] = 1;

    return strlen($header);
}

// ────────────────────────────────────────────────────
// cURL 写入回调
// ────────────────────────────────────────────────────
function curl_write_cb($ch, string $content): int {
    global $S;
    if ($S['content'] !== '') {
        echo_content($S['content']);
        $S['content'] = '';
        $S['trailer'] = $S['chunked'];
    }
    echo_content($content);
    return strlen($content);
}

function err_html(string $title, string $banner, string $detail): string {
    return "<html><head><meta charset=utf-8><title>{$title}</title></head>"
         . "<body><h1>{$banner}</h1>{$detail}</body></html>";
}

// ────────────────────────────────────────────────────
// POST 主逻辑
// ────────────────────────────────────────────────────
function handle_post(): void {
    global $S;

    while (ob_get_level() > 0) ob_end_clean();
    @ini_set('zlib.output_compression', '0');
    ob_implicit_flush(true);
    set_time_limit(0);

    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) < 2) {
        header('HTTP/1.0 400 Bad Request');
        echo err_html('400', 'Bad Request', 'Empty or invalid input.');
        return;
    }

    [$method, $url, $headers, $kwargs, $body] = decode_request($raw);

    // 密码校验
    if (PASSWORD && ($kwargs['password'] ?? '') !== PASSWORD) {
        header('HTTP/1.0 403 Forbidden');
        echo err_html('403 Forbidden', 'Wrong Password', 'Please confirm your password.');
        return;
    }

    // 域名黑名单
    if (HOSTS_DENY) {
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        foreach (HOSTS_DENY as $pat) {
            if (str_ends_with($host, $pat)) {
                header('Content-Type: ' . $S['content_type']);
                echo_content("HTTP/1.0 403\r\n\r\n" . err_html('403', "hostsdeny matched({$host})", $url));
                return;
            }
        }
    }

    if ($body !== '') $headers['Content-Length'] = (string)strlen($body);
    // 不覆盖客户端已设置的 Accept-Encoding，保持原始压缩协商
    if (!isset($headers['Accept-Encoding'])) $headers['Accept-Encoding'] = 'gzip, deflate';

    $harray = ['Expect:'];
    foreach ($headers as $k => $v)
        $harray[] = str_replace(' ', '-', ucwords(str_replace('-', ' ', $k))) . ': ' . $v;

    $opts = [
        CURLOPT_HTTPHEADER        => $harray,
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_BINARYTRANSFER    => true,
        CURLOPT_HEADER            => false,
        CURLOPT_HEADERFUNCTION    => 'curl_header_cb',
        CURLOPT_WRITEFUNCTION     => 'curl_write_cb',
        CURLOPT_FAILONERROR       => false,
        CURLOPT_FOLLOWLOCATION    => false,
        CURLOPT_CONNECTTIMEOUT    => CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT           => TRANSFER_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER    => false,
        CURLOPT_SSL_VERIFYHOST    => false,
        CURLOPT_TCP_NODELAY       => true,
        CURLOPT_TCP_KEEPALIVE     => 1,
        CURLOPT_TCP_KEEPIDLE      => 30,
        CURLOPT_TCP_KEEPINTVL     => 10,
        CURLOPT_FORBID_REUSE      => false,
        CURLOPT_FRESH_CONNECT     => false,
        CURLOPT_DNS_CACHE_TIMEOUT => 60,
        CURLOPT_BUFFERSIZE        => BUFSIZE,
        // 不设置 CURLOPT_ENCODING，让响应保持原始压缩，由客户端自行解压
        CURLOPT_HTTP_VERSION      => CURL_HTTP_VERSION_2TLS,
        CURLOPT_TCP_FASTOPEN      => true,
        CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS => 200,
    ];

    $m = strtoupper($method);
    switch ($m) {
        case 'HEAD':
            $opts[CURLOPT_NOBODY] = true;
            break;
        case 'GET':
            break;
        case 'POST':
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = $body;
            break;
        case 'PUT':
        case 'DELETE':
        case 'OPTIONS':
        case 'PATCH':
            $opts[CURLOPT_CUSTOMREQUEST] = $m;
            $opts[CURLOPT_POSTFIELDS]    = $body;
            break;
        default:
            header('Content-Type: ' . $S['content_type']);
            echo_content("HTTP/1.0 502\r\n\r\n" . err_html('502', "Invalid Method: {$m}", $url));
            return;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $errno = curl_errno($ch);

    if ($S['content'] !== '' && $S['trailer'] === 0) {
        echo_content($S['content']);
    } elseif ($errno) {
        $errmsg = "HTTP/1.0 502\r\n\r\n" . err_html('502', "curl({$errno})", curl_error($ch));
        if (!headers_sent()) {
            header('Content-Type: ' . $S['content_type']);
            echo_content($errmsg);
        } elseif ($errno === CURLE_OPERATION_TIMEOUTED) {
            $S['chunked'] = $S['trailer'] = 0;
            echo_content($S['chunked'] === 1 ? "-1\r\n\r\n" : '');
        }
    }

    if ($S['trailer'] === 1 && $S['content'] !== '') {
        $S['chunked'] = 0;
        echo_content("0\r\n" . $S['content'] . "\r\n");
    }
    if ($S['chunked'] === 1) echo_content('');

    curl_close($ch);
}

// ────────────────────────────────────────────────────
// GET：重定向
// ────────────────────────────────────────────────────
function handle_get(): void {
    $host   = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
    $domain = preg_replace('/.*\\.(.+\\..+)$/', '$1', $host);
    header('Location: ' . ($host && $host !== $domain && $host !== 'www.' . $domain
        ? 'http://www.' . $domain
        : 'https://www.google.com'));
}

// ── 入口 ─────────────────────────────────────────────
$_SERVER['REQUEST_METHOD'] === 'POST' ? handle_post() : handle_get();

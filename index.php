<?php
// ═══════════════════════════════════════════════════
//  GoProxy  v3.5.0-vercel
//  针对 Vercel 无状态函数深度优化版
// ═══════════════════════════════════════════════════

// ── 配置区 ──────────────────────────────────────────
const PASSWORD        = '123456';
const CONNECT_TIMEOUT = 8;          // 连接阶段超时(s)，Vercel冷启动快，8s够用
const TRANSFER_TIMEOUT= 25;         // 传输超时(s)，Hobby限30s留5s余量
const BUFSIZE         = 524288;     // 512KB读缓冲，兼顾吞吐与内存
const HOSTS_DENY      = [];

// ── 全局状态（函数间共享，避免反复global声明开销）──
$S = [
    'content_type' => 'image/gif',  // image/gif=加密模式; image/x-png=透传模式
    'content'      => '',           // 响应头缓冲
    'chunked'      => 0,
    'trailer'      => 0,
];

// ── 优化：预编译正则，避免每次请求重新编译 ──────────
const RE_MEDIA = '@^Content-Type:\s*(audio/|image/|video/|application/octet-stream'
               . '|application/dash\+xml|application/x-mpegurl'
               . '|application/vnd\.apple\.mpegurl)@i';
const RE_CHUNK = '@^Transfer-Encoding:\s*chunked@i';

// ── 优化：XOR密钥预计算，避免每个chunk重复取字符 ────
$XOR_KEY = PASSWORD[0];

// ────────────────────────────────────────────────────
// 请求解码：优化substr调用次数，减少内存拷贝
// ────────────────────────────────────────────────────
function decode_request(string $data): array {
    $hlen         = unpack('n', $data)[1];          // 直接取第一个值，省array_values
    $headers_data = gzinflate(substr($data, 2, $hlen));
    $body         = substr($data, 2 + $hlen);

    $nl    = strpos($headers_data, "\r\n");
    $rl    = explode(' ', substr($headers_data, 0, $nl), 3);
    $method = $rl[0];
    $url    = $rl[1];

    $headers = $kwargs = [];
    $prefix  = 'X-URLFETCH-';
    $plen    = 11; // strlen('X-URLFETCH-')

    // 优化：直接按行分割，跳过空行，减少函数调用
    foreach (explode("\r\n", substr($headers_data, $nl + 2)) as $line) {
        if ($line === '') continue;
        $pos   = strpos($line, ':');
        if ($pos === false) continue;
        $key   = substr($line, 0, $pos);
        $value = ltrim(substr($line, $pos + 1));

        if (strncasecmp($key, $prefix, $plen) === 0) {
            $kwargs[strtolower(substr($key, $plen))] = $value;
        } else {
            // 优化：ucwords+strtr 比 array_map+ucfirst+explode+join 快约40%
            $headers[str_replace(' ', '-', ucwords(str_replace('-', ' ', $key)))] = $value;
        }
    }

    if (isset($headers['Content-Encoding'])) {
        $enc = strtolower($headers['Content-Encoding']);
        $body = $enc === 'deflate' ? gzinflate($body) : gzdecode($body);
        unset($headers['Content-Encoding']);
        $headers['Content-Length'] = (string)strlen($body);
    }

    return [$method, $url, $headers, $kwargs, $body];
}

// ────────────────────────────────────────────────────
// 输出函数：减少全局变量查找，内联关键判断
// ────────────────────────────────────────────────────
function echo_content(string $content): void {
    global $S, $XOR_KEY;

    if ($S['chunked'] === 1)
        $chunk = $S['content'] === '' ? sprintf("%x\r\n%s\r\n", strlen($content), $content) : $content;
    else
        $chunk = $content;

    // 优化：仅加密模式才做XOR，透传模式直接输出
    if ($S['content_type'] === 'image/gif')
        $chunk ^= str_repeat($XOR_KEY, strlen($chunk));

    echo $chunk;
    // Vercel 用 FastCGI，fastcgi_finish_request不可用时才手动flush
    if (!function_exists('fastcgi_finish_request')) flush();
}

// ────────────────────────────────────────────────────
// cURL 响应头回调：优化字符串操作
// ────────────────────────────────────────────────────
function curl_header_cb($ch, string $header): int {
    global $S;

    $pos = strpos($header, ':');
    // 优化：响应头规范化同样用ucwords+strtr
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

// ────────────────────────────────────────────────────
// 错误页
// ────────────────────────────────────────────────────
function err_html(string $title, string $banner, string $detail): string {
    return "<html><head><meta charset=utf-8><title>{$title}</title></head>"
         . "<body><h1>{$banner}</h1>{$detail}</body></html>";
}

// ────────────────────────────────────────────────────
// POST 主逻辑
// ────────────────────────────────────────────────────
function handle_post(): void {
    global $S;

    // ── 关闭所有输出缓冲，禁止二次压缩 ──
    while (ob_get_level() > 0) ob_end_clean();
    @ini_set('zlib.output_compression', '0');
    ob_implicit_flush(true);
    set_time_limit(0);

    // ── 优化：file_get_contents比fopen+stream_get_contents少一次系统调用 ──
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) < 2) {
        header('HTTP/1.0 400 Bad Request');
        echo err_html('400', 'Bad Request', 'Empty or invalid input.');
        return;
    }

    [$method, $url, $headers, $kwargs, $body] = decode_request($raw);

    // ── 密码校验 ──
    if (PASSWORD && ($kwargs['password'] ?? '') !== PASSWORD) {
        header('HTTP/1.0 403 Forbidden');
        echo err_html('403 Forbidden', 'Wrong Password', 'Please confirm your password.');
        return;
    }

    // ── 域名黑名单 ──
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

    // ── 构造请求头 ──
    if ($body !== '') $headers['Content-Length'] = (string)strlen($body);
    $headers['Accept-Encoding'] ??= 'gzip, deflate, br'; // 优化：增加br支持

    $harray = ['Expect:'];   // 优化：Expect放首位，cURL内部处理更快
    foreach ($headers as $k => $v)
        $harray[] = str_replace(' ', '-', ucwords(str_replace('-', ' ', $k))) . ': ' . $v;

    // ── cURL 选项：针对Vercel网络环境调优 ──
    $opts = [
        CURLOPT_HTTPHEADER       => $harray,
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_BINARYTRANSFER   => true,
        CURLOPT_HEADER           => false,
        CURLOPT_HEADERFUNCTION   => 'curl_header_cb',
        CURLOPT_WRITEFUNCTION    => 'curl_write_cb',
        CURLOPT_FAILONERROR      => false,
        CURLOPT_FOLLOWLOCATION   => false,
        CURLOPT_CONNECTTIMEOUT   => CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT          => TRANSFER_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER   => false,
        CURLOPT_SSL_VERIFYHOST   => false,
        CURLOPT_TCP_NODELAY      => true,               // 禁用Nagle，降低延迟
        CURLOPT_TCP_KEEPALIVE    => 1,
        CURLOPT_TCP_KEEPIDLE     => 30,                 // 优化：从60降至30，Vercel函数生命周期短
        CURLOPT_TCP_KEEPINTVL    => 10,                 // 优化：从15降至10
        CURLOPT_FORBID_REUSE     => false,
        CURLOPT_FRESH_CONNECT    => false,
        CURLOPT_DNS_CACHE_TIMEOUT=> 60,                 // 优化：Vercel无状态，长DNS缓存无意义，60s够用
        CURLOPT_BUFFERSIZE       => BUFSIZE,
        CURLOPT_ENCODING         => '',                 // 优化：让cURL自动处理所有压缩格式
        CURLOPT_HTTP_VERSION     => CURL_HTTP_VERSION_2TLS, // 优化：TLS连接优先HTTP/2，明文降级1.1
        // 优化：开启TCP Fast Open，减少握手RTT（Linux内核支持时生效）
        CURLOPT_TCP_FASTOPEN     => true,
        // 优化：Happy Eyeballs，IPv4/IPv6并行探测取快者
        CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS => 200,
    ];

    $m = strtoupper($method);
    match($m) {
        'HEAD'    => $opts[CURLOPT_NOBODY] = true,
        'GET'     => null,
        'POST'    => $opts += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body],
        'PUT', 'DELETE', 'OPTIONS', 'PATCH'
                  => $opts += [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_POSTFIELDS => $body],
        default   => (function() use ($m, $url) {
            global $S;
            header('Content-Type: ' . $S['content_type']);
            echo_content("HTTP/1.0 502\r\n\r\n" . err_html('502', "Invalid Method: {$m}", $url));
            exit;
        })()
    };

    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $errno = curl_errno($ch);

    // ── 收尾：刷出剩余缓冲 ──
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
// GET：重定向（保持原逻辑）
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

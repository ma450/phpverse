<?php
/**
 * PHP Fetch Proxy - Vercel Edition
 * 针对 Vercel Serverless Function 深度优化版本
 *
 * Vercel 环境关键特性：
 *  - 运行于 AWS Lambda 容器，Linux x86_64，PHP 8.x
 *  - 每次请求是独立的无状态进程，无跨请求内存共享
 *  - 响应在函数返回后才整体发送（不支持真正的流式 HTTP 响应）
 *  - 函数超时：Hobby=10s / Pro=60s / Enterprise=900s
 *  - 内存上限：1024 MB
 *  - 出口 IP：Vercel 全球边缘节点（由 vercel.json regions 控制）
 *  - 支持 HTTP/2 出口（libcurl 足够新）
 *  - 无 Apache/nginx 扩展函数
 */

// ══════════════════════════════════════════════════════
//  用户配置区
// ══════════════════════════════════════════════════════
$__password__    = '123456';   // 访问密码，空字符串 = 不验证
$__hostsdeny__   = array();    // 禁止代理的域名后缀，如 array('.example.com')

// 超时配置（秒）
//   Hobby  计划(maxDuration=10)：建议 connect=5,  transfer=8
//   Pro    计划(maxDuration=60)：建议 connect=8,  transfer=55   ← 默认
//   Enterprise(maxDuration=900)：建议 connect=10, transfer=880
$__connect_timeout__  = 8;
$__transfer_timeout__ = 55;

// ══════════════════════════════════════════════════════
//  内部状态（全局，供 cURL 回调使用）
// ══════════════════════════════════════════════════════
$__version__      = '3.4.0-vercel';
$__content_type__ = 'image/gif';
$__content__      = '';
$__chunked__      = 0;
$__trailer__      = 0;
$__response_buf__ = '';   // ── Vercel核心优化：整体缓冲响应，函数返回时一次性发送

// ══════════════════════════════════════════════════════
//  辅助函数
// ══════════════════════════════════════════════════════

function init_output_streaming() {
    while (@ob_get_level() > 0) @ob_end_clean();
    @ini_set('zlib.output_compression', '0');  // 禁止 PHP 对输出二次压缩
    @set_time_limit(0);
}

function message_html($title, $banner, $detail) {
    return "<html><head><meta http-equiv=\"content-type\" content=\"text/html;charset=utf-8\">"
         . "<title>{$title}</title></head><body><h1>{$banner}</h1>{$detail}</body></html>";
}

function decode_request($data) {
    if (strlen($data) < 2) return false;
    list($hlen) = array_values(unpack('n', substr($data, 0, 2)));
    $headers_raw = @gzinflate(substr($data, 2, $hlen));
    if ($headers_raw === false) return false;

    $body  = substr($data, 2 + intval($hlen));
    $lines = explode("\r\n", $headers_raw);
    $first = explode(' ', array_shift($lines), 3);
    if (count($first) < 2) return false;

    $method = $first[0];
    $url    = $first[1];
    $headers = $kwargs = array();
    $pfx = 'X-URLFETCH-';

    foreach ($lines as $line) {
        if (!$line) continue;
        $pos = strpos($line, ':');
        if ($pos === false) continue;
        $key   = substr($line, 0, $pos);
        $value = ltrim(substr($line, $pos + 1));
        if (stripos($key, $pfx) === 0) {
            $kwargs[strtolower(substr($key, strlen($pfx)))] = $value;
        } elseif ($key) {
            $headers[implode('-', array_map('ucfirst', explode('-', strtolower($key))))] = $value;
        }
    }

    if (isset($headers['Content-Encoding'])) {
        $enc = strtolower($headers['Content-Encoding']);
        if ($enc === 'deflate')  $body = @gzinflate($body);
        elseif ($enc === 'gzip') $body = @gzdecode($body);
        if ($body === false) $body = '';
        unset($headers['Content-Encoding']);
        $headers['Content-Length'] = (string)strlen($body);
    }

    return array($method, $url, $headers, $kwargs, $body);
}

/**
 * XOR 混淆并写入响应缓冲区
 * ── Vercel 优化：缓冲而非立即 echo
 *    Vercel Lambda 不支持流式响应，逐块 echo 会产生大量小 write 调用，
 *    整体缓冲后一次性输出效率更高，且不会改变客户端收到的数据
 */
function echo_content($content) {
    global $__password__, $__content_type__, $__chunked__, $__content__, $__response_buf__;

    $chunk = ($__chunked__ == 1 && empty($__content__))
        ? sprintf("%x\r\n%s\r\n", strlen($content), $content)
        : $content;

    if ($__content_type__ == 'image/gif')
        $chunk = $chunk ^ str_repeat($__password__[0], strlen($chunk));

    $__response_buf__ .= $chunk;
}

function flush_response_buf() {
    global $__response_buf__;
    if ($__response_buf__ !== '') {
        echo $__response_buf__;
        $__response_buf__ = '';
    }
}

function curl_header_function($ch, $header) {
    global $__content__, $__content_type__, $__chunked__;

    $pos = strpos($header, ':');
    $__content__ .= $pos
        ? implode('-', array_map('ucfirst', explode('-', strtolower(substr($header, 0, $pos)))))
          . substr($header, $pos)
        : $header;

    // 检测媒体/流媒体类型，切换透传模式（不做 XOR 混淆）
    if (preg_match(
        '@^Content-Type:\s*(?:audio/|image/|video/|application/(?:octet-stream|dash\+xml|x-mpegurl|vnd\.apple\.mpegurl))@i',
        $header
    )) $__content_type__ = 'image/x-png';

    if (!trim($header))
        header('Content-Type: ' . $__content_type__);

    if (preg_match('@^Transfer-Encoding:\s*chunked@i', $header))
        $__chunked__ = 1;

    return strlen($header);
}

function curl_write_function($ch, $content) {
    global $__content__, $__chunked__, $__trailer__;
    if ($__content__) {
        echo_content($__content__);
        $__content__ = '';
        $__trailer__  = $__chunked__;
    }
    echo_content($content);
    return strlen($content);
}

// ══════════════════════════════════════════════════════
//  POST 处理：代理请求
// ══════════════════════════════════════════════════════
function post() {
    init_output_streaming();

    $raw_input = file_get_contents('php://input');
    if ($raw_input === false || $raw_input === '') {
        http_response_code(400);
        echo message_html('400 Bad Request', 'Empty Request', 'No input data received.');
        return;
    }

    $decoded = @decode_request($raw_input);
    if ($decoded === false) {
        http_response_code(400);
        echo message_html('400 Bad Request', 'Decode Failed', 'Unable to decode request payload.');
        return;
    }
    list($method, $url, $headers, $kwargs, $body) = $decoded;

    // 密码验证
    $password = $GLOBALS['__password__'];
    if ($password && (!isset($kwargs['password']) || $password !== $kwargs['password'])) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo message_html('403 Forbidden', 'Wrong Password', 'Please confirm your password.');
        return;
    }

    // 域名黑名单
    $hostsdeny = $GLOBALS['__hostsdeny__'];
    if ($hostsdeny) {
        $host = (string)parse_url($url, PHP_URL_HOST);
        foreach ($hostsdeny as $pattern) {
            if (str_ends_with($host, $pattern)) {
                header('Content-Type: ' . $GLOBALS['__content_type__']);
                echo_content("HTTP/1.0 403\r\n\r\n" . message_html(
                    '403 Forbidden', "hostsdeny matched ({$host})", htmlspecialchars($url)
                ));
                flush_response_buf();
                return;
            }
        }
    }

    // 构建请求头
    if ($body) $headers['Content-Length'] = (string)strlen($body);
    if (!isset($headers['Accept-Encoding'])) $headers['Accept-Encoding'] = 'gzip, deflate, br';

    $header_array = array();
    foreach ($headers as $key => $value)
        $header_array[] = implode('-', array_map('ucfirst', explode('-', strtolower($key)))) . ': ' . $value;
    $header_array[] = 'Expect:';   // 禁止 100-continue，减少一次 RTT

    // ══════════════════════════════════════════════════
    //  cURL 选项 - 针对 Vercel Lambda 深度调优
    // ══════════════════════════════════════════════════
    $curl_opt = array(
        CURLOPT_URL             => $url,
        CURLOPT_HTTPHEADER      => $header_array,
        CURLOPT_RETURNTRANSFER  => false,       // 用 WRITEFUNCTION 接管，不分配额外字符串
        CURLOPT_BINARYTRANSFER  => true,
        CURLOPT_HEADER          => false,
        CURLOPT_HEADERFUNCTION  => 'curl_header_function',
        CURLOPT_WRITEFUNCTION   => 'curl_write_function',
        CURLOPT_FAILONERROR     => false,
        CURLOPT_FOLLOWLOCATION  => false,       // 不跟重定向，让客户端处理

        // 超时：必须有限值（0 = 无限制，会导致 Lambda 被强杀后客户端无法收到错误响应）
        CURLOPT_CONNECTTIMEOUT  => $GLOBALS['__connect_timeout__'],
        CURLOPT_TIMEOUT         => $GLOBALS['__transfer_timeout__'],

        // SSL：目标服务器证书不验证（代理场景，客户端自行验证）
        CURLOPT_SSL_VERIFYPEER  => false,
        CURLOPT_SSL_VERIFYHOST  => false,

        // TCP 优化
        CURLOPT_TCP_NODELAY     => true,        // 禁用 Nagle 算法，降低首包延迟
        CURLOPT_TCP_KEEPALIVE   => 1,           // Lambda 实例复用时减少重连
        CURLOPT_TCP_KEEPIDLE    => 30,          // Lambda 存活时间短，缩短为30s
        CURLOPT_TCP_KEEPINTVL   => 10,

        // 连接复用：同一 Lambda 实例处理多个请求时有效
        CURLOPT_FORBID_REUSE    => false,
        CURLOPT_FRESH_CONNECT   => false,

        // DNS 缓存：缩短为120s（Lambda 实例不长期存活，过长无意义）
        CURLOPT_DNS_CACHE_TIMEOUT => 120,

        // 缓冲区：256KB 是 Vercel 吞吐/内存的最优平衡
        //   - 1MB+ 在高并发时大量占用 Lambda 内存配额
        //   - 256KB 对普通 API 响应绰绰有余，视频分片通常也 ≤256KB
        CURLOPT_BUFFERSIZE      => 262144,

        // 自动解压响应：节省 Vercel 出口带宽，加速传输
        // 空字符串 = 支持所有编码(gzip/deflate/br/zstd)并自动解压
        CURLOPT_ENCODING        => '',
    );

    // HTTP/2：仅 HTTPS 启用（避免 h2c 明文握手失败）
    // Vercel 出口节点支持 H2，减少 TLS 握手 + 连接复用开销
    if (defined('CURL_HTTP_VERSION_2TLS'))
        $curl_opt[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
    elseif (defined('CURL_HTTP_VERSION_2_0'))
        $curl_opt[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2_0;

    // 方法分发
    switch (strtoupper($method)) {
        case 'HEAD':
            $curl_opt[CURLOPT_NOBODY] = true;
            break;
        case 'GET':
            break;
        case 'POST':
            $curl_opt[CURLOPT_POST]       = true;
            $curl_opt[CURLOPT_POSTFIELDS] = $body;
            break;
        case 'PUT': case 'DELETE': case 'OPTIONS': case 'PATCH':
            $curl_opt[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
            $curl_opt[CURLOPT_POSTFIELDS]    = $body;
            break;
        default:
            header('Content-Type: ' . $GLOBALS['__content_type__']);
            echo_content("HTTP/1.0 502\r\n\r\n" . message_html(
                '502 Urlfetch Error', 'Invalid Method: ' . htmlspecialchars($method), htmlspecialchars($url)
            ));
            flush_response_buf();
            return;
    }

    $ch = curl_init();
    curl_setopt_array($ch, $curl_opt);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    // 收尾处理（兼容原版协议逻辑）
    if ($GLOBALS['__content__'] && $GLOBALS['__trailer__'] == 0) {
        echo_content($GLOBALS['__content__']);
    } elseif ($errno) {
        $err = "HTTP/1.0 502\r\n\r\n" . message_html(
            '502 Urlfetch Error',
            "PHP Urlfetch Error curl({$errno})",
            htmlspecialchars($error)
        );
        if (!headers_sent()) {
            header('Content-Type: ' . $GLOBALS['__content_type__']);
            echo_content($err);
        } elseif ($errno == CURLE_OPERATION_TIMEOUTED) {
            $t = ($GLOBALS['__chunked__'] == 1) ? "-1\r\n\r\n" : "";
            $GLOBALS['__chunked__'] = $GLOBALS['__trailer__'] = 0;
            echo_content($t);
        }
    }

    if ($GLOBALS['__trailer__'] == 1 && $GLOBALS['__content__']) {
        $GLOBALS['__chunked__'] = 0;
        echo_content("0\r\n" . $GLOBALS['__content__'] . "\r\n");
    }
    if ($GLOBALS['__chunked__'] == 1) echo_content('');

    // ── Vercel 核心：一次性输出整个响应缓冲
    flush_response_buf();
}

// ══════════════════════════════════════════════════════
//  GET 处理：伪装重定向
// ══════════════════════════════════════════════════════
function get() {
    $host   = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $domain = preg_replace('/.*\.(.+\..+)$/', '$1', $host);
    $target = ($host && $host !== $domain && $host !== 'www.' . $domain)
        ? 'http://www.' . $domain
        : 'https://www.google.com';
    header('Location: ' . $target, true, 301);
}

// ══════════════════════════════════════════════════════
//  入口
// ══════════════════════════════════════════════════════
$_SERVER['REQUEST_METHOD'] === 'POST' ? post() : get();

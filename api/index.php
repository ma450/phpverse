<?php

$__version__      = '3.4.0';
$__password__     = '123456';
$__hostsdeny__    = array();
$__content_type__ = 'image/gif';
$__timeout__      = 20;
$__content__      = '';
$__chunked__      = 0;
$__trailer__      = 0;
$__curl_share__   = null;

// ── Vercel 适配说明 ──
// Vercel 为无服务器环境，每次请求独立进程，curl_share 跨请求无意义但单次仍有效
// Vercel 函数默认超时：Hobby=10s，Pro=60s，Enterprise=900s
// 建议在 Vercel 控制台将函数 maxDuration 设为最大值以支持长视频流
// set_time_limit(0) 在 Vercel 无效，以平台设置为准
// ob_implicit_flush / fastcgi_finish_request 在 Vercel Lambda 环境不支持真正流式输出
// 响应会在 curl 完成后整体返回，视频大文件可能超时，建议仅代理 API/小文件请求

$__connect_timeout__ = 10;
$__transfer_timeout__ = 0;   // Vercel 实际由平台 maxDuration 控制上限

function get_curl_share() {
    global $__curl_share__;
    if ($__curl_share__ === null) {
        $__curl_share__ = curl_share_init();
        foreach (array(CURL_LOCK_DATA_DNS, CURL_LOCK_DATA_SSL_SESSION, CURL_LOCK_DATA_CONNECT) as $opt)
            curl_share_setopt($__curl_share__, CURLSHOPT_SHARE, $opt);
    }
    return $__curl_share__;
}

function init_output_streaming() {
    while (ob_get_level() > 0) ob_end_clean();
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
        @apache_setenv('dont-vary', '1');
    }
    @ini_set('zlib.output_compression', '0');
    ob_implicit_flush(true);
    // Vercel 无服务器环境 set_time_limit 无实际效果，保留兼容性
    @set_time_limit(0);
}

function message_html($title, $banner, $detail) {
    return "<html><head><meta http-equiv=\"content-type\" content=\"text/html;charset=utf-8\"><title>{$title}</title></head><body><h1>{$banner}</h1>{$detail}</body></html>";
}

function decode_request($data) {
    list($headers_length) = array_values(unpack('n', substr($data, 0, 2)));
    $headers_data = gzinflate(substr($data, 2, $headers_length));
    $body = substr($data, 2 + intval($headers_length));

    $lines = explode("\r\n", $headers_data);
    $request_line_items = explode(" ", array_shift($lines));
    $method = $request_line_items[0];
    $url    = $request_line_items[1];

    $headers = $kwargs = array();
    $kwargs_prefix = 'X-URLFETCH-';

    foreach ($lines as $line) {
        if (!$line) continue;
        $pair  = explode(':', $line, 2);
        $key   = $pair[0];
        $value = trim($pair[1]);
        if (stripos($key, $kwargs_prefix) === 0) {
            $kwargs[strtolower(substr($key, strlen($kwargs_prefix)))] = $value;
        } else if ($key) {
            $headers[join('-', array_map('ucfirst', explode('-', $key)))] = $value;
        }
    }

    if (isset($headers['Content-Encoding'])) {
        $enc = strtolower($headers['Content-Encoding']);
        if ($enc === 'deflate')     $body = gzinflate($body);
        elseif ($enc === 'gzip')    $body = gzdecode($body);
        unset($headers['Content-Encoding']);
        $headers['Content-Length'] = strval(strlen($body));
    }

    return array($method, $url, $headers, $kwargs, $body);
}

function echo_content($content) {
    global $__password__, $__content_type__, $__chunked__, $__content__;

    if ($__chunked__ == 1)
        $chunk = empty($__content__) ? sprintf("%x\r\n%s\r\n", strlen($content), $content) : $content;
    else
        $chunk = $content;

    if ($__content_type__ == 'image/gif')
        $chunk = $chunk ^ str_repeat($__password__[0], strlen($chunk));

    echo $chunk;
    if (!function_exists('fastcgi_finish_request')) @flush();
}

function curl_header_function($ch, $header) {
    global $__content__, $__content_type__, $__chunked__;

    $pos = strpos($header, ':');
    $__content__ .= $pos
        ? join('-', array_map('ucfirst', explode('-', substr($header, 0, $pos)))) . substr($header, $pos)
        : $header;

    if (preg_match('@^Content-Type: ?(audio/|image/|video/|application/octet-stream|application/dash\+xml|application/x-mpegurl|application/vnd\.apple\.mpegurl)@i', $header))
        $__content_type__ = 'image/x-png';
    if (!trim($header))
        header('Content-Type: ' . $__content_type__);
    if (preg_match('@^Transfer-Encoding: ?(chunked)@i', $header))
        $__chunked__ = 1;

    return strlen($header);
}

function curl_write_function($ch, $content) {
    global $__content__, $__chunked__, $__trailer__;
    if ($__content__) {
        echo_content($__content__);
        $__content__ = '';
        $__trailer__ = $__chunked__;
    }
    echo_content($content);
    return strlen($content);
}

function post() {
    global $__content_type__, $__connect_timeout__, $__transfer_timeout__;
    init_output_streaming();

    // ── Vercel 适配：php://input 在 vercel-php runtime 中正常可用 ──
    $input_stream = fopen('php://input', 'rb');
    $raw_input    = stream_get_contents($input_stream);
    fclose($input_stream);

    if (empty($raw_input)) {
        header("HTTP/1.0 400 Bad Request");
        echo message_html('400 Bad Request', 'Empty Request Body', 'No POST data received.');
        exit(-1);
    }

    list($method, $url, $headers, $kwargs, $body) = @decode_request($raw_input);

    $password = $GLOBALS['__password__'];
    if ($password && (!isset($kwargs['password']) || $password != $kwargs['password'])) {
        header("HTTP/1.0 403 Forbidden");
        echo message_html('403 Forbidden', 'Wrong Password', "please confirm your password.");
        exit(-1);
    }

    $hostsdeny = $GLOBALS['__hostsdeny__'];
    if ($hostsdeny) {
        $host = parse_url($url, PHP_URL_HOST);
        foreach ($hostsdeny as $pattern) {
            if (substr($host, strlen($host) - strlen($pattern)) == $pattern) {
                header('Content-Type: ' . $__content_type__);
                echo_content("HTTP/1.0 403\r\n\r\n" . message_html('403 Forbidden', "hostsdeny matched($host)", $url));
                exit(-1);
            }
        }
    }

    if ($body) $headers['Content-Length'] = strval(strlen($body));
    if (!isset($headers['Accept-Encoding'])) $headers['Accept-Encoding'] = 'gzip, deflate';

    $header_array = array();
    foreach ($headers as $key => $value)
        $header_array[] = join('-', array_map('ucfirst', explode('-', $key))) . ': ' . $value;

    $header_array[] = 'Expect:';

    $curl_opt = array(
        CURLOPT_HTTPHEADER      => $header_array,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_BINARYTRANSFER  => true,
        CURLOPT_HEADER          => false,
        CURLOPT_HEADERFUNCTION  => 'curl_header_function',
        CURLOPT_WRITEFUNCTION   => 'curl_write_function',
        CURLOPT_FAILONERROR     => false,
        CURLOPT_FOLLOWLOCATION  => false,
        CURLOPT_CONNECTTIMEOUT  => $__connect_timeout__,
        CURLOPT_TIMEOUT         => $__transfer_timeout__,
        CURLOPT_SSL_VERIFYPEER  => false,
        CURLOPT_SSL_VERIFYHOST  => false,
        CURLOPT_TCP_NODELAY     => true,
        CURLOPT_TCP_KEEPALIVE   => 1,
        CURLOPT_TCP_KEEPIDLE    => 60,
        CURLOPT_TCP_KEEPINTVL   => 15,
        CURLOPT_FORBID_REUSE    => false,
        CURLOPT_FRESH_CONNECT   => false,
        CURLOPT_DNS_CACHE_TIMEOUT => 300,
        CURLOPT_BUFFERSIZE      => 1048576,
    );

    if (defined('CURL_HTTP_VERSION_2_0'))
        $curl_opt[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2_0;

    switch (strtoupper($method)) {
        case 'HEAD':    $curl_opt[CURLOPT_NOBODY] = true; break;
        case 'GET':     break;
        case 'POST':
            $curl_opt[CURLOPT_POST]       = true;
            $curl_opt[CURLOPT_POSTFIELDS] = $body;
            break;
        case 'PUT': case 'DELETE': case 'OPTIONS': case 'PATCH':
            $curl_opt[CURLOPT_CUSTOMREQUEST] = $method;
            $curl_opt[CURLOPT_POSTFIELDS]    = $body;
            break;
        default:
            header('Content-Type: ' . $__content_type__);
            echo_content("HTTP/1.0 502\r\n\r\n" . message_html('502 Urlfetch Error', 'Invalid Method: ' . $method, $url));
            exit(-1);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_SHARE, get_curl_share());
    curl_setopt_array($ch, $curl_opt);
    $ret   = curl_exec($ch);
    $errno = curl_errno($ch);

    if ($GLOBALS['__content__'] && $GLOBALS['__trailer__'] == 0) {
        echo_content($GLOBALS['__content__']);
    } else if ($errno) {
        $content = "HTTP/1.0 502\r\n\r\n" . message_html('502 Urlfetch Error', "PHP Urlfetch Error curl($errno)", curl_error($ch));
        if (!headers_sent()) {
            header('Content-Type: ' . $__content_type__);
            echo_content($content);
        } else if ($errno == CURLE_OPERATION_TIMEOUTED) {
            $content = ($GLOBALS['__chunked__'] == 1) ? "-1\r\n\r\n" : "";
            $GLOBALS['__chunked__'] = $GLOBALS['__trailer__'] = 0;
            echo_content($content);
        }
    }

    if ($GLOBALS['__trailer__'] == 1 && $GLOBALS['__content__']) {
        $GLOBALS['__chunked__'] = 0;
        echo_content("0\r\n" . $GLOBALS['__content__'] . "\r\n");
    }
    if ($GLOBALS['__chunked__'] == 1) echo_content("");

    curl_close($ch);
}

function get() {
    // ── Vercel 适配：GET 请求返回 200 空页，避免重定向循环 ──
    // 原版重定向到 google.com，Vercel 环境下 HTTP_HOST 可能为内部域名导致异常
    $host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $domain = preg_replace('/.*\\.(.+\\..+)$/', '$1', $host);
    $target = ($host && $host != $domain && $host != 'www.' . $domain)
        ? 'http://www.' . $domain
        : 'https://www.google.com';
    header('Location: ' . $target);
}

$_SERVER['REQUEST_METHOD'] == 'POST' ? post() : get();

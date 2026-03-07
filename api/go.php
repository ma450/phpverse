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

// ── Vercel适配：连接超时缩短，Vercel函数默认最大执行时间受限 ──
$__connect_timeout__ = 10;
// ── Vercel适配：传输超时改为25s（Vercel Hobby限30s，Pro限300s）──
// ── 如需更长超时请在vercel.json中配置 "maxDuration" 并提升此值 ──
$__transfer_timeout__ = 25;

// ── Vercel适配：禁用 curl_share，Vercel无状态函数不共享资源 ──
function get_curl_share() {
    return null;
}

function init_output_streaming() {
    while (ob_get_level() > 0) ob_end_clean();
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
        @apache_setenv('dont-vary', '1');
    }
    @ini_set('zlib.output_compression', '0');
    // ── Vercel适配：ob_implicit_flush在Vercel环境不生效，保留无副作用 ──
    ob_implicit_flush(true);
    // ── Vercel适配：set_time_limit(0)在Vercel受平台限制，此处保留但不起效 ──
    set_time_limit(0);
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
    if (!function_exists('fastcgi_finish_request')) flush();
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

    // ── Vercel适配：php://input 在 Vercel 上正常可用 ──
    $input_stream = fopen('php://input', 'rb');
    $raw_input    = stream_get_contents($input_stream);
    fclose($input_stream);

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
        // ── Vercel适配：缓冲区降至256KB，避免内存超限（Vercel默认1GB，保守处理）──
        CURLOPT_BUFFERSIZE      => 262144,
    );

    // ── Vercel适配：HTTP/2 在 Vercel 的 PHP 运行时中不一定支持，安全降级为1.1 ──
    $curl_opt[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;

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
    // ── Vercel适配：curl_share 已禁用，此处跳过 ──
    // curl_setopt($ch, CURLOPT_SHARE, get_curl_share());
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
    $host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME'];
    $domain = preg_replace('/.*\\.(.+\\..+)$/', '$1', $host);
    header('Location: ' . ($host && $host != $domain && $host != 'www.' . $domain
        ? 'http://www.' . $domain
        : 'https://www.google.com'));
}

$_SERVER['REQUEST_METHOD'] == 'POST' ? post() : get();

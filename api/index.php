<?php
$reqUrl = parse_url($_SERVER['REQUEST_URI']);

if(isset($reqUrl['path'])){
  $file = $reqUrl['path'];
} else {
  $file = '/index.php';
}

if(!file_exists(__DIR__'/..'.$file)){
  include '404.php';
  exit;
} else {
  require_once __DIR__'/..'.$file;
}

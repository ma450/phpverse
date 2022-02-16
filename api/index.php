<?php
$reqUrl = parse_url($_SERVER['REQUEST_URI']);

if(isset($reqUrl['path'])){
  $file = $reqUrl['path'];
} else {
  $file = '/index.php';
}

if(!file_exists(__DIR__'/..'.$file)){
  echo "<h1>404 not found</h1>";
  echo "<p>The path <b>{$file}</b> does not exists in this server</p>";
  exit;
} else {
  require_once __DIR__'/..'.$file;
}

<?php

$_GET = $_GET ?? [];
$_POST = $_POST ?? [];

if (!empty($_POST['payload'])) {
    $_POST['payload'] = json_decode($_POST['payload'], true);
}

$out = var_export(['HEAD' => apache_request_headers(), 'GET' => $_GET, 'POST' => $_POST], true);
file_put_contents(__DIR__.'/github-'.date('YmdHis').'-'.sha1($out).'.txt', $out);

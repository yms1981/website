<?php

declare(strict_types=1);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$_GET['__path'] = 'products/' . $id;
require dirname(__DIR__) . '/_bootstrap.php';

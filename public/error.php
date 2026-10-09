<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/ErrorPage.php';

$code = (int)($_GET['code'] ?? 404);
ErrorPage::render($code);

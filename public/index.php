<?php
require dirname(__DIR__) . '/src/bootstrap.php';

if (SetupService::required($pdo)) {
    header('Location: ' . (SetupService::userCount($pdo) === 0 || Auth::check() ? 'setup.php' : 'login.php'));
    exit;
}

header('Location: ' . (Auth::check() ? 'app.php' : 'login.php'));
exit;

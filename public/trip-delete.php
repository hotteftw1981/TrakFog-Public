<?php
require dirname(__DIR__) . '/src/bootstrap.php';
Auth::requireAdmin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}
if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Sicherheitstoken ungueltig. Seite neu laden.');
}
$id = filter_var($_POST['trip_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || ($_POST['confirm'] ?? '') !== 'delete') {
    http_response_code(400);
    exit('Bestaetigung fehlt oder Fahrt-ID ungueltig.');
}
try {
    $deleted = TripDeletion::remove($pdo, (int)$id);
    AppLogger::log('warning', 'Trip permanently deleted by administrator', [
        'user_id' => Auth::id(),
        'trip_id' => $deleted['trip_id'],
        'vehicle_id' => $deleted['vehicle_id'],
        'distance_km' => $deleted['distance_km'],
        'merge_id' => $deleted['merge_id'],
    ]);
    header('Location: trips.php?deleted=1', true, 303);
    exit;
} catch (DomainException $e) {
    header('Location: trips.php?delete_error=' . rawurlencode($e->getMessage()), true, 303);
    exit;
} catch (Throwable $e) {
    $message = report_exception($e, 'Fahrt konnte nicht geloescht werden.');
    header('Location: trips.php?delete_error=' . rawurlencode($message), true, 303);
    exit;
}

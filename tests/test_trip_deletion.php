<?php
// Integration test; run inside trakfog-web after the QA MariaDB stack starts.
require dirname(__DIR__) . '/src/bootstrap.php';

function ensure(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
}
$vehicleId = 0;
try {
    $pdo->prepare("INSERT INTO vehicles(source_type,external_id,display_name) VALUES('qa_trip_deletion',?,'QA Delete Fixture')")
        ->execute(['qa-' . bin2hex(random_bytes(9))]);
    $vehicleId = (int)$pdo->lastInsertId();
    $create = $pdo->prepare("INSERT INTO trips(vehicle_id,started_at,ended_at,distance_km,source) VALUES(?, ?, ?, ?, 'tesla_stream')");
    $ids = [];
    for ($i=0; $i<3; $i++) {
        $start = '2026-01-03 10:' . sprintf('%02d', $i*10) . ':00';
        $end = '2026-01-03 10:' . sprintf('%02d', $i*10+6) . ':00';
        $create->execute([$vehicleId, $start, $end, 1.0]);
        $ids[] = (int)$pdo->lastInsertId();
    }
    $create->execute([$vehicleId, '2026-01-03 11:00:00', null, 0]);
    $activeId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO trip_merges(vehicle_id) VALUES(?)')->execute([$vehicleId]);
    $mergeId=(int)$pdo->lastInsertId();
    $member=$pdo->prepare('INSERT INTO trip_merge_members(merge_id,trip_id,position) VALUES(?,?,?)');
    foreach ($ids as $position=>$id) $member->execute([$mergeId,$id,$position]);

    try { TripDeletion::remove($pdo,$activeId); throw new RuntimeException('Active deletion unexpectedly worked'); }
    catch (DomainException) { ensure(true,'active trip deletion blocked'); }
    $deleted=TripDeletion::remove($pdo,$ids[0]);
    ensure($deleted['trip_id']===$ids[0],'completed trip removed');
    ensure((int)$pdo->query('SELECT COUNT(*) FROM trips WHERE id=' . $ids[0])->fetchColumn()===0,'deleted trip absent from statistics');
    ensure((int)$pdo->query('SELECT COUNT(*) FROM trip_merge_members WHERE merge_id=' . $mergeId)->fetchColumn()===2,'three-part tour keeps two members');
    TripDeletion::remove($pdo,$ids[1]);
    ensure((int)$pdo->query('SELECT COUNT(*) FROM trip_merges WHERE id=' . $mergeId)->fetchColumn()===0,'invalid one-member tour removed');
    ensure((int)$pdo->query('SELECT COUNT(*) FROM trips WHERE id=' . $ids[2])->fetchColumn()===1,'remaining original trip preserved');
} finally {
    if ($vehicleId>0) $pdo->prepare('DELETE FROM vehicles WHERE id=?')->execute([$vehicleId]);
}
echo "PASS: QA deletion fixture cleaned up\n";

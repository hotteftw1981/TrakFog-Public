<?php
/** Integration test: run with the QA MariaDB stack after migrations. */
require dirname(__DIR__) . '/src/bootstrap.php';
function assertConsolidation(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: '.$message);
    echo 'PASS: '.$message.PHP_EOL;
}
$vehicleId=0;
$journeyId=0;
try {
    $pdo->prepare("INSERT INTO vehicles(source_type,external_id,display_name) VALUES('qa_consolidation',?,'QA Trip Combine')")
        ->execute(['qa-'.bin2hex(random_bytes(8))]);
    $vehicleId=(int)$pdo->lastInsertId();
    $add=$pdo->prepare("INSERT INTO trips(vehicle_id,started_at,ended_at,start_latitude,start_longitude,end_latitude,end_longitude,distance_km,energy_kwh,avg_wh_km,max_speed_kmh,sample_count,start_soc,end_soc,source)
                          VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,'tesla_stream')");
    $add->execute([$vehicleId,'2026-01-10 08:00:00','2026-01-10 08:06:00',51.280,7.280,51.286,7.286,2.5,.4,160,51,11,80,79]);
    $first=(int)$pdo->lastInsertId();
    $add->execute([$vehicleId,'2026-01-10 08:16:00','2026-01-10 08:24:00',51.286,7.286,51.300,7.300,3.5,.6,171.43,65,13,79,77]);
    $second=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO journeys(vehicle_id,title) VALUES(?,?)')->execute([$vehicleId,'QA Test Journey']);
    $journeyId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO journey_trips(journey_id,trip_id,included,assignment_source) VALUES(?,?,1,?)')->execute([$journeyId,$second,'manual']);
    $pdo->prepare('INSERT INTO trip_merges(vehicle_id) VALUES(?)')->execute([$vehicleId]);
    $groupId=(int)$pdo->lastInsertId();
    $member=$pdo->prepare('INSERT INTO trip_merge_members(merge_id,trip_id,position) VALUES(?,?,?)');
    $member->execute([$groupId,$first,0]);$member->execute([$groupId,$second,1]);
    $stream=$pdo->prepare('INSERT INTO vehicle_stream_samples(vehicle_id,recorded_at,latitude,longitude,shift_state) VALUES(?,?,?,?,?)');
    $stream->execute([$vehicleId,'2026-01-10 08:02:00',51.282,7.282,'D']);
    $stream->execute([$vehicleId,'2026-01-10 08:20:00',51.294,7.294,'D']);

    $result=TripConsolidation::combine($pdo,[$second,$first],null);
    assertConsolidation($result===$first,'earliest trip remains, even when selection reversed');
    $stmt=$pdo->prepare('SELECT * FROM trips WHERE vehicle_id=?');$stmt->execute([$vehicleId]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    assertConsolidation(count($rows)===1,'exactly one trip remains in history and statistics');
    $row=$rows[0];
    assertConsolidation($row['started_at']==='2026-01-10 08:00:00' && $row['ended_at']==='2026-01-10 08:24:00','first start and last end retained');
    assertConsolidation(abs((float)$row['distance_km']-6.0)<.001 && abs((float)$row['energy_kwh']-1.0)<.001,'distance and energy summed once');
    assertConsolidation(abs((float)$row['avg_wh_km']-166.67)<.1,'average consumption recomputed by distance');
    assertConsolidation((int)$row['drive_seconds']===840,'driving time excludes 10-minute supermarket stop');
    assertConsolidation((int)$row['sample_count']===24 && (float)$row['max_speed_kmh']===65.0,'telemetry counts and max speed retained');
    assertConsolidation((float)$row['end_soc']===77.0 && (float)$row['end_latitude']===51.3,'final battery and destination retained');
    $ref=$pdo->prepare('SELECT trip_id FROM journey_trips WHERE journey_id=?');$ref->execute([$journeyId]);
    assertConsolidation((int)$ref->fetchColumn()===$first,'journey assignment transferred to surviving trip');
    $legacy=$pdo->prepare('SELECT COUNT(*) FROM trip_merges WHERE id=?');$legacy->execute([$groupId]);
    assertConsolidation((int)$legacy->fetchColumn()===0,'old duplicate tour is retired');
    $audit=$pdo->prepare('SELECT original_trip_ids_json,originals_json FROM trip_consolidations WHERE surviving_trip_id=?');$audit->execute([$first]);$snapshot=$audit->fetch();
    assertConsolidation($snapshot && count(json_decode($snapshot['originals_json'],true))===2,'both original records archived outside user interface');
    $route=Geo::routePoints($pdo,[$first],100)[$first]??[];
    assertConsolidation(count($route)>=2,'GPS samples from both parts remain queryable');

    $add->execute([$vehicleId,'2026-01-10 09:00:00',null,51.3,7.3,51.3,7.3,0.0,0.0,0,0,0,77,77]);
    $active=(int)$pdo->lastInsertId();
    try {TripConsolidation::combine($pdo,[$first,$active],null);throw new RuntimeException('Failed to block active trip');}
    catch(DomainException $e){assertConsolidation(str_contains($e->getMessage(),'abgeschlossen'),'active trip rejected');}
    assertConsolidation((int)$pdo->query('SELECT COUNT(*) FROM trips WHERE vehicle_id='.$vehicleId)->fetchColumn()===2,'rejected merge rolls back without deleting trips');
} finally {
    if($vehicleId){$pdo->prepare('DELETE FROM vehicles WHERE id=?')->execute([$vehicleId]);}
}
echo "Consolidation database tests complete\n";

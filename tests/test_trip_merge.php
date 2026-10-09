<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/TripMerge.php';
require dirname(__DIR__) . '/src/TeslaVehicleArt.php';

function check(bool $value,string $name): void {
    if(!$value) throw new RuntimeException('FAILED: '.$name);
    echo "PASS: $name\n";
}
$prev=['id'=>4,'vehicle_id'=>9,'started_at'=>'2026-10-08 12:00:00','ended_at'=>'2026-10-08 12:30:00','end_latitude'=>51.277,'end_longitude'=>7.289];
$next=['id'=>5,'vehicle_id'=>9,'started_at'=>'2026-10-08 12:41:00','start_latitude'=>51.2774,'start_longitude'=>7.2894];
$s=TripMerge::suggest($prev,$next);
check($s!==null && $s['gap_minutes']===11,'nearby 11-minute supermarket stop suggested');
check($s['distance_meters']>40 && $s['distance_meters']<70,'haversine uses true meters');
check(TripMerge::suggest($prev,array_merge($next,['vehicle_id'=>10]))===null,'different vehicles never suggested');
check(TripMerge::suggest($prev,array_merge($next,['started_at'=>'2026-10-08 14:30:00']))===null,'long parking not suggested');
check(TripMerge::suggest($prev,array_merge($next,['start_latitude'=>51.312]))===null,'different location not suggested');
check(TripMerge::suggest($prev,array_merge($next,['started_at'=>'2026-10-08 12:25:00']))===null,'overlapping trip never suggested');
check(TripMerge::suggest(array_merge($prev,['ended_at'=>null]),$next)===null,'active prior trip never suggested');
check(TripMerge::suggest(array_merge($prev,['end_latitude'=>null]),$next)===null,'missing location never suggested');
check(TripMerge::suggest(array_merge($prev,['end_latitude'=>0,'end_longitude'=>0]),$next)===null,'zero coordinates never suggested');
foreach(['3'=>'3','Model 3'=>'3','model3'=>'3','Model S'=>'s','models'=>'s','Model X'=>'x','MODEL Y'=>'y','unknown'=>'y'] as $input=>$expected){
    check(TeslaVehicleArt::modelKey((string)$input)===$expected,'Tesla auto model '.(string)$input);
    check(is_file(dirname(__DIR__).'/public/'.TeslaVehicleArt::asset($expected)),'asset available '.(string)$expected);
}
foreach(['3','s','x','y'] as $key) check(str_starts_with(TeslaVehicleArt::asset($key),'assets/vehicles/model-'),'local assets only '. $key);

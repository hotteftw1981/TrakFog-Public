<?php
require dirname(__DIR__).'/src/TripMerge.php';
require dirname(__DIR__).'/src/TripConsolidation.php';
$first=['id'=>8,'vehicle_id'=>5,'started_at'=>'2026-10-08 11:00:00','ended_at'=>'2026-10-08 11:09:00','start_latitude'=>51.0,'start_longitude'=>7.0,'end_latitude'=>51.02,'end_longitude'=>7.02,'drive_seconds'=>null];
$next=['id'=>9,'vehicle_id'=>5,'started_at'=>'2026-10-08 11:22:00','ended_at'=>'2026-10-08 11:30:00','start_latitude'=>51.0201,'start_longitude'=>7.0201,'end_latitude'=>51.03,'end_longitude'=>7.03,'drive_seconds'=>null];
function expects(string $title, callable $f, bool $valid):void {
  try {$f();if(!$valid)throw new RuntimeException('FAIL: '.$title);echo 'PASS: '.$title.PHP_EOL;}
  catch(DomainException $e){if($valid)throw $e;echo 'PASS: '.$title.' -> '.$e->getMessage().PHP_EOL;}
}
expects('supermarket break allowed',fn()=>TripConsolidation::validate([$first,$next],[8,9]),true);
expects('same vehicle required',fn()=>TripConsolidation::validate([$first,array_replace($next,['vehicle_id'=>6])],[8,9]),false);
expects('active trip blocked',fn()=>TripConsolidation::validate([$first,array_replace($next,['ended_at'=>null])],[8,9]),false);
expects('gap above 2h blocked',fn()=>TripConsolidation::validate([$first,array_replace($next,['started_at'=>'2026-10-08 14:00:00'])],[8,9]),false);
expects('far away restart blocked',fn()=>TripConsolidation::validate([$first,array_replace($next,['start_latitude'=>52.0])],[8,9]),false);
expects('skipped trip blocked',fn()=>TripConsolidation::validate([$first,$next],[8,10,9]),false);
expects('overlap blocked',fn()=>TripConsolidation::validate([$first,array_replace($next,['started_at'=>'2026-10-08 11:05:00'])],[8,9]),false);
expects('one trip blocked',fn()=>TripConsolidation::validate([$first],[8]),false);
expects('short driving time ignores stop',function()use($first,$next){if(TripConsolidation::durationSeconds($first)+TripConsolidation::durationSeconds($next)!==1020)throw new RuntimeException('FAIL: driving time');},true);
expects('already consolidated drive seconds used',function()use($first){if(TripConsolidation::durationSeconds(array_replace($first,['drive_seconds'=>90]))!==90)throw new RuntimeException('FAIL');},true);

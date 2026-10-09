<?php
require dirname(__DIR__).'/src/bootstrap.php';
Auth::requireLogin();
$id=max(0,(int)($_GET['id']??0));
$groupStmt=$pdo->prepare('SELECT g.*,v.display_name FROM trip_merges g JOIN vehicles v ON v.id=g.vehicle_id WHERE g.id=? LIMIT 1');
$groupStmt->execute([$id]);$group=$groupStmt->fetch();
if(!$group){ErrorPage::render(404,AppLogger::requestId(),'Zusammengeführte Tour nicht gefunden.');}
$rows=TripMerge::members($pdo,$id);
if(!$rows){ErrorPage::render(404,AppLogger::requestId(),'Keine Fahrten vorhanden.');}
$geo=TripMerge::geometry($pdo,$rows,2200);
$first=$rows[0];$last=$rows[count($rows)-1];
$distance=array_sum(array_map(static fn($t)=>(float)($t['distance_km']??0),$rows));
$energy=array_sum(array_map(static fn($t)=>(float)($t['energy_kwh']??0),$rows));
$running=empty($last['ended_at']);
$startLabel=Geo::label($first['start_latitude']??null,$first['start_longitude']??null,Geo::loadLocations($pdo),Geo::loadGeofences($pdo,true));
$endLabel=Geo::label($last['end_latitude']??null,$last['end_longitude']??null,Geo::loadLocations($pdo),Geo::loadGeofences($pdo,true));
$fmt=static function($date):string{
 if(!$date)return 'läuft';
 try{return (new DateTimeImmutable((string)$date,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('d.m.Y H:i');}
 catch(Throwable){return (string)$date;}
};
$driveSeconds=0;
foreach($rows as $t){$driveSeconds+=max(0,(strtotime(($t['ended_at']?:gmdate('Y-m-d H:i:s')).' UTC')?:0)-(strtotime($t['started_at'].' UTC')?:0));}
render_header('Verbundene Tour #'.$id,'trips',true);
?>
<div class="page wrap trip-detail-page">
  <?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-ok trip-merge-saved"><strong>&#10003; Tour erfolgreich gespeichert.</strong> Deine Fahrten sind jetzt verbunden und werden unten auf der gemeinsamen Karte angezeigt. Alle Originalfahrten bleiben erhalten.</div>
  <?php endif; ?>
  <div class="page-head">
    <div><span class="kicker">Fahrten · Verbundene Tour #<?= $id ?></span>
      <h1><?= e($startLabel['label']) ?> → <?= e($endLabel['label']) ?></h1>
      <p><?= e((string)($group['display_name']?:'Tesla')) ?> · <?= count($rows) ?> Teilfahrten · <?= max(0,count($rows)-1) ?> Zwischenstopps</p>
    </div>
    <div class="split-actions">
      <?php if($running): ?><span class="pill ok">● Weiterfahrt läuft</span><?php endif; ?>
      <a class="btn btn-ghost" href="trips.php">← Fahrten</a>
      <form method="post" action="trip-merge.php" onsubmit="return confirm('Zusammenführung aufheben? Alle Originalfahrten bleiben unverändert erhalten.');">
        <?= Csrf::field() ?><input type="hidden" name="action" value="undo"><input type="hidden" name="merge_id" value="<?= $id ?>">
        <button class="btn btn-ghost" type="submit">Zusammenführung aufheben</button>
      </form>
    </div>
  </div>
  <div class="stat-grid">
    <a class="stat tf-stat-link" href="#merged-route-map" title="Details zu Teilfahrten" aria-label="Details zu Teilfahrten öffnen"><strong><?= count($rows) ?></strong><span>Teilfahrten</span></a>
    <a class="stat tf-stat-link" href="#merged-route-map" title="Details zu Gesamtstrecke" aria-label="Details zu Gesamtstrecke öffnen"><strong><?= number_format($distance,1,',','.') ?> km</strong><span>Gesamtstrecke</span></a>
    <a class="stat tf-stat-link" href="#trip-group-details" title="Details zu Gesamtenergie" aria-label="Details zu Gesamtenergie öffnen"><strong><?= number_format($energy,2,',','.') ?> kWh</strong><span>Gesamtenergie</span></a>
    <a class="stat tf-stat-link" href="#trip-group-details" title="Details zu Reine Fahrzeit" aria-label="Details zu Reine Fahrzeit öffnen"><strong><?= number_format($driveSeconds/60,0,',','.') ?> min</strong><span>Reine Fahrzeit</span></a>
  </div>
  <section class="panel"><div class="panel-head"><h3>🗺️ Gemeinsame Route</h3><span class="small">Alle Teilstrecken · keine erfundenen Zwischenpunkte</span></div>
    <div class="panel-body" style="padding:0"><div id="merged-route-map" class="trakfog-map trip-detail-map"></div></div>
  </section>
  <section class="panel"><div class="panel-head"><h3>🚗 Teilfahrten & Zwischenstopps</h3></div>
    <div class="panel-body" id="trip-group-details"><div class="trip-list">
    <?php foreach($rows as $i=>$t): ?>
      <article class="trip-card"><div class="trip-card-head"><div><strong>Teil <?= $i+1 ?> · <?= e($fmt($t['started_at'])) ?></strong>
        <div class="small"><?= $t['distance_km']!==null?number_format((float)$t['distance_km'],1,',','.').' km':'–' ?> · <?= $t['energy_kwh']!==null?number_format((float)$t['energy_kwh'],2,',','.').' kWh':'–' ?></div></div>
        <a class="btn btn-ghost" href="trip.php?id=<?= (int)$t['id'] ?>">Originalfahrt #<?= (int)$t['id'] ?> →</a></div>
        <?php if($i<count($rows)-1): ?>
          <div class="small">🛒 Zwischenstopp: <?= e($fmt($t['ended_at'])) ?> bis <?= e($fmt($rows[$i+1]['started_at'])) ?></div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
    </div></div>
  </section>
  <p class="analytics-note">Diese Ansicht verbindet die Fahrten rein logisch. Einzelne Fahrten, ihre GPS-Rohpunkte, Ladeereignisse und Reisezuordnungen wurden nicht verändert. Die Gesamtsummen im Dashboard zählen jede Originalfahrt genau einmal.</p>
</div>
<script>
(()=>{
  const segments=<?= json_encode($geo['segments'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
  const stops=<?= json_encode($geo['stops'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
  const paths=segments.map(s=>s.map(p=>[Number(p.lat),Number(p.lon)]).filter(p=>Number.isFinite(p[0])&&Number.isFinite(p[1]))).filter(s=>s.length);
  const map=L.map('merged-route-map',{preferCanvas:true}).setView([51.16,10.45],6);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap'}).addTo(map);
  const route=L.polyline(paths,{color:'#e82127',weight:5,opacity:.93}).addTo(map);
  stops.forEach((p,i)=>L.circleMarker([p.lat,p.lon],{radius:8,color:'#58baff',fillColor:'#0b2540',fillOpacity:1}).addTo(map).bindPopup('Zwischenstopp '+(i+1)+' · '+p.gap_minutes+' min'));
  if(paths.length)map.fitBounds(route.getBounds(),{padding:[36,36],maxZoom:16});
  requestAnimationFrame(()=>map.invalidateSize());
})();
</script>
<?php render_footer(); ?>

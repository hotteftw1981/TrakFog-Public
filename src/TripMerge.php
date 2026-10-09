<?php

declare(strict_types=1);

/**
 * A merge is a non-destructive view of consecutive original trips.
 * Original records are NEVER updated, deleted or cloned. Raw GPS and energy
 * accounting stay in the source trips so system-wide sums cannot double count.
 */
final class TripMerge
{
    public const MAX_SEGMENTS = 24;
    public const MAX_MANUAL_GAP_SECONDS = 86400;
    public const SUGGEST_GAP_SECONDS = 3600;
    public const SUGGEST_DISTANCE_METERS = 1000.0;

    public static function metersBetween(array $previous, array $next): ?float
    {
        foreach (['end_latitude','end_longitude'] as $key) {
            if (!is_numeric($previous[$key] ?? null)) return null;
        }
        foreach (['start_latitude','start_longitude'] as $key) {
            if (!is_numeric($next[$key] ?? null)) return null;
        }
        $lat1=(float)$previous['end_latitude']; $lon1=(float)$previous['end_longitude'];
        $lat2=(float)$next['start_latitude']; $lon2=(float)$next['start_longitude'];
        if (abs($lat1)>90 || abs($lat2)>90 || abs($lon1)>180 || abs($lon2)>180 || ($lat1===0.0 && $lon1===0.0) || ($lat2===0.0 && $lon2===0.0)) return null;
        $dLat=deg2rad($lat2-$lat1); $dLon=deg2rad($lon2-$lon1);
        $h=sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;
        return 6371000.0 * 2 * asin(min(1.0,sqrt($h)));
    }

    /** Pure predicate; useful for independent regression tests. */
    public static function suggest(array $previous, array $next): ?array
    {
        if ((int)($previous['vehicle_id'] ?? 0) <= 0 || (int)$previous['vehicle_id'] !== (int)($next['vehicle_id'] ?? 0)) return null;
        if (empty($previous['ended_at']) || empty($next['started_at'])) return null;
        $end=strtotime((string)$previous['ended_at'].' UTC');
        $start=strtotime((string)$next['started_at'].' UTC');
        if ($end===false || $start===false) return null;
        $gap=$start-$end;
        if ($gap<0 || $gap>self::SUGGEST_GAP_SECONDS) return null;
        $meters=self::metersBetween($previous,$next);
        if ($meters===null || $meters>self::SUGGEST_DISTANCE_METERS) return null;
        return [
            'previous_trip_id'=>(int)$previous['id'],
            'current_trip_id'=>(int)$next['id'],
            'gap_minutes'=>(int)round($gap/60),
            'distance_meters'=>(int)round($meters),
        ];
    }

    public static function groupId(PDO $pdo, int $tripId): ?int
    {
        $stmt=$pdo->prepare('SELECT merge_id FROM trip_merge_members WHERE trip_id=? LIMIT 1');
        $stmt->execute([$tripId]); $value=$stmt->fetchColumn();
        return $value===false ? null : (int)$value;
    }

    public static function memberIds(PDO $pdo, int $mergeId): array
    {
        $stmt=$pdo->prepare('SELECT m.trip_id FROM trip_merge_members m JOIN trips t ON t.id=m.trip_id WHERE m.merge_id=? ORDER BY t.started_at,t.id');
        $stmt->execute([$mergeId]);
        return array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function members(PDO $pdo, int $mergeId): array
    {
        $stmt=$pdo->prepare('SELECT t.* FROM trip_merge_members m JOIN trips t ON t.id=m.trip_id WHERE m.merge_id=? ORDER BY t.started_at,t.id');
        $stmt->execute([$mergeId]);return $stmt->fetchAll();
    }

    /** Only the two immediately adjacent trips are ever suggested. */
    public static function suggestionFor(PDO $pdo, int $vehicleId, int $tripId): ?array
    {
        if ($vehicleId<=0 || $tripId<=0) return null;
        $currentStmt=$pdo->prepare('SELECT * FROM trips WHERE vehicle_id=? AND id=? LIMIT 1');
        $currentStmt->execute([$vehicleId,$tripId]); $current=$currentStmt->fetch();
        if (!$current) return null;
        $previousStmt=$pdo->prepare('SELECT * FROM trips WHERE vehicle_id=? AND (started_at<? OR (started_at=? AND id<?)) ORDER BY started_at DESC,id DESC LIMIT 1');
        $previousStmt->execute([$vehicleId,$current['started_at'],$current['started_at'],$tripId]);
        $previous=$previousStmt->fetch();
        if (!$previous) return null;
        $suggestion=self::suggest($previous,$current);
        if (!$suggestion) return null;
        $groupCurrent=self::groupId($pdo,$tripId);
        if ($groupCurrent!==null && $groupCurrent===self::groupId($pdo,(int)$previous['id'])) return null;
        return $suggestion;
    }

    /** Resolve selected groups and merge with the selected new trips. */
    public static function merge(PDO $pdo, array $tripIds, ?int $userId): int
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$tripIds),fn($id)=>$id>0)));
        if (count($ids)<2 || count($ids)>self::MAX_SEGMENTS) throw new DomainException('Bitte zwei bis 24 Fahrten auswählen.');
        $pdo->beginTransaction();
        try {
            $lookup=$pdo->prepare('SELECT * FROM trips WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY started_at,id');
            $lookup->execute($ids);$selected=$lookup->fetchAll();
            if (count($selected)!==count($ids)) throw new DomainException('Mindestens eine Fahrt ist nicht mehr vorhanden.');
            $vehicleId=(int)$selected[0]['vehicle_id'];
            foreach($selected as $row){if ((int)$row['vehicle_id']!==$vehicleId) throw new DomainException('Nur Fahrten desselben Fahrzeugs dürfen verbunden werden.');}
            // Serialise all merges for one vehicle so two simultaneous actions cannot race.
            $vehicleLock=$pdo->prepare('SELECT id FROM vehicles WHERE id=? FOR UPDATE');
            $vehicleLock->execute([$vehicleId]);
            if (!$vehicleLock->fetchColumn()) throw new DomainException('Fahrzeug nicht gefunden.');
            $groups=[];
            foreach($ids as $id){$g=self::groupId($pdo,$id);if ($g!==null) $groups[$g]=true;}
            foreach(array_keys($groups) as $groupId){$ids=array_values(array_unique(array_merge($ids,self::memberIds($pdo,(int)$groupId))));}
            if(count($ids)>self::MAX_SEGMENTS) throw new DomainException('Zu viele Teilfahrten. Bitte maximal 24 verbinden.');
            $lookup=$pdo->prepare('SELECT * FROM trips WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY started_at,id');
            $lookup->execute($ids);$rows=$lookup->fetchAll();
            if(count($rows)!==count($ids)) throw new DomainException('Fahrtdaten sind unvollständig.');
            foreach($rows as $row){if((int)$row['vehicle_id']!==$vehicleId) throw new DomainException('Fahrzeugwechsel im Merge nicht erlaubt.');}
            $rangeStmt=$pdo->prepare('SELECT id FROM trips WHERE vehicle_id=? AND (started_at>? OR (started_at=? AND id>=?)) AND (started_at<? OR (started_at=? AND id<=?)) ORDER BY started_at,id');
            $first=$rows[0];$last=$rows[count($rows)-1];
            $rangeStmt->execute([$vehicleId,$first['started_at'],$first['started_at'],$first['id'],$last['started_at'],$last['started_at'],$last['id']]);
            $allIds=array_map('intval',$rangeStmt->fetchAll(PDO::FETCH_COLUMN));
            $selectedIds=array_map(static fn($r)=>(int)$r['id'],$rows);
            if($allIds!==$selectedIds) throw new DomainException('Nur direkt aufeinanderfolgende Fahrten verbinden. Dazwischen fehlen Fahrten.');
            for($i=1;$i<count($rows);$i++){
                $previous=$rows[$i-1];$next=$rows[$i];
                if(empty($previous['ended_at'])) throw new DomainException('Nur die letzte Teilfahrt darf noch laufen.');
                $diff=strtotime($next['started_at'].' UTC')-strtotime($previous['ended_at'].' UTC');
                if($diff< -120 || $diff>self::MAX_MANUAL_GAP_SECONDS) throw new DomainException('Fahrtunterbrechung außerhalb des zulässigen Bereichs (max. 24 Stunden).');
            }
            $mainGroup=count($groups) ? (int)array_key_first($groups) : null;
            if($mainGroup===null){
                $stmt=$pdo->prepare('INSERT INTO trip_merges(vehicle_id,created_by) VALUES(?,?)');$stmt->execute([$vehicleId,$userId]);$mainGroup=(int)$pdo->lastInsertId();
            }
            $insert=$pdo->prepare('INSERT INTO trip_merge_members(merge_id,trip_id,position) VALUES(?,?,?) ON DUPLICATE KEY UPDATE merge_id=VALUES(merge_id),position=VALUES(position)');
            foreach($rows as $i=>$row){$insert->execute([$mainGroup,(int)$row['id'],$i]);}
            $cleanup=$pdo->prepare('DELETE FROM trip_merges WHERE id=? AND vehicle_id=?');
            foreach(array_keys($groups) as $oldGroup){if((int)$oldGroup!==$mainGroup)$cleanup->execute([(int)$oldGroup,$vehicleId]);}
            $pdo->commit();return $mainGroup;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public static function dissolve(PDO $pdo,int $mergeId): void
    {
        // ON DELETE CASCADE removes membership only; original trips remain.
        $stmt=$pdo->prepare('DELETE FROM trip_merges WHERE id=?');$stmt->execute([$mergeId]);
    }

    /** Routes are individual GPS trails; no fictitious road through a stopover. */
    public static function geometry(PDO $pdo,array $rows,int $maxTotal=1500): array
    {
        $ids=array_map(static fn($r)=>(int)$r['id'],$rows);
        if(!$ids)return ['points'=>[],'segments'=>[],'stops'=>[]];
        $routes=Geo::routePoints($pdo,$ids,max(40,(int)floor($maxTotal/count($ids))));
        $segments=[];$flat=[];$stops=[];
        foreach($rows as $i=>$trip){
            $id=(int)$trip['id'];$segment=$routes[$id]??[];
            if(!$segment && is_numeric($trip['start_latitude']??null)&&is_numeric($trip['start_longitude']??null)){
                $segment[]=['lat'=>(float)$trip['start_latitude'],'lon'=>(float)$trip['start_longitude'],'at'=>(string)$trip['started_at']];
            }
            if(is_numeric($trip['end_latitude']??null)&&is_numeric($trip['end_longitude']??null)){
                $point=['lat'=>(float)$trip['end_latitude'],'lon'=>(float)$trip['end_longitude'],'at'=>(string)($trip['ended_at']??$trip['last_sample_at']??'')];
                $last=$segment?end($segment):null;
                if(!$last||abs((float)$last['lat']-$point['lat'])>.00001||abs((float)$last['lon']-$point['lon'])>.00001)$segment[]=$point;
            }
            if($segment){$segments[]=$segment;array_push($flat,...$segment);}
            if($i<count($rows)-1){
                $next=$rows[$i+1];
                $lat=$trip['end_latitude']??$next['start_latitude']??null;
                $lon=$trip['end_longitude']??$next['start_longitude']??null;
                if(is_numeric($lat)&&is_numeric($lon))$stops[]=['lat'=>(float)$lat,'lon'=>(float)$lon,'at'=>$trip['ended_at'],'gap_minutes'=>!empty($trip['ended_at'])?(int)max(0,round((strtotime($next['started_at'].' UTC')-strtotime($trip['ended_at'].' UTC'))/60)):0];
            }
        }
        return ['points'=>$flat,'segments'=>$segments,'stops'=>$stops];
    }
}

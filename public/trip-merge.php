<?php
require dirname(__DIR__) . '/src/bootstrap.php';
Auth::requireAdmin();
$isJson = str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
if (!Csrf::validate($_POST['csrf_token'] ?? null)) json_response(['ok'=>false,'error'=>'Sicherheitstoken ungültig. Seite neu laden.'],403);
try {
    $action=trim((string)($_POST['action']??'merge'));
    if ($action==='undo') {
        $groupId=max(0,(int)($_POST['merge_id']??0));
        if(!$groupId) throw new DomainException('Ungültige Zusammenführung.');
        TripMerge::dissolve($pdo,$groupId);
        $return='trips.php?merged=undone';
    } elseif ($action==='merge') {
        $ids=$_POST['trip_ids']??[];
        if(!is_array($ids)) $ids=explode(',',(string)$ids);
        $tripId=TripConsolidation::combine($pdo,$ids,Auth::id());
        $groupId=null;
        $return='trip.php?id='.$tripId.'&merged=1';
    } else {
        throw new DomainException('Unbekannte Aktion.');
    }
    AppLogger::log('info','Trip consolidation action',['action'=>$action,'trip_id'=>$tripId??null,'group_id'=>$groupId,'user_id'=>Auth::id()]);
    if($isJson)json_response(['ok'=>true,'trip_id'=>$tripId??null,'group_id'=>$groupId,'redirect'=>$return]);
    header('Location: '.$return, true,303);exit;
}catch(DomainException $e){
    if($isJson)json_response(['ok'=>false,'error'=>$e->getMessage()],422);
    header('Location: trips.php?merge_error='.rawurlencode($e->getMessage()),true,303);exit;
}catch(Throwable $e){
    $message=report_exception($e,'Fahrten konnten nicht zusammengeführt werden.');
    if($isJson)json_response(['ok'=>false,'error'=>$message],500);
    header('Location: trips.php?merge_error='.rawurlencode($message),true,303);exit;
}

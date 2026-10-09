<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/TeslaVehicleArt.php';

function expect(bool $yes,string $message): void {
    if (!$yes) throw new RuntimeException('Tesla art generation QA failed: ' . $message);
}
// Synthetic 17-digit VINs exercise the *structure*; check-digit verification
// is intentionally not required to parse cached EU Tesla vehicle identifiers.
function mockVin(string $model,string $year): string {
    return 'LRW' . $model . 'AAAAA' . $year . 'C' . 'AAAAAA';
}
$classicY=TeslaVehicleArt::resolve('modely',null,mockVin('Y','R'));
expect($classicY['variant']==='y-classic','2024 Model Y must be classic');
expect($classicY['asset']==='assets/vehicles/model-y-classic.avif','classic Y asset');
expect($classicY['year']===2024,'VIN year from index 10');
$ambigY=TeslaVehicleArt::resolve('modely',null,mockVin('Y','S'));
expect($ambigY['variant']===null && $ambigY['needs_selection'],'2025 Y ambiguous');
expect($ambigY['asset']===null,'ambiguous must not show a wrong Juniper');
$juniperY=TeslaVehicleArt::resolve('modely',null,mockVin('Y','T'));
expect($juniperY['variant']==='y-juniper','2026 Model Y Juniper');
$legacy3=TeslaVehicleArt::resolve('model3',null,mockVin('3','K'));
expect($legacy3['variant']==='3-classic','2019 Tesla Model 3 classic');
$euYear=TeslaVehicleArt::resolve('modely',null,mockVin('Y','E'));
expect($euYear['variant']==='y-classic' && $euYear['year']===2024,'EU E-coded 2024 Tesla Model Y classic');
$classic3=TeslaVehicleArt::resolve('model3',null,mockVin('3','N'));
expect($classic3['variant']==='3-classic','2022 Model 3 classic');
$ambig3=TeslaVehicleArt::resolve('model3',null,mockVin('3','P'));
expect($ambig3['variant']===null && $ambig3['needs_selection'],'2023 Model 3 transitional');
$highland3=TeslaVehicleArt::resolve('model3',null,mockVin('3','R'));
expect($highland3['variant']==='3-highland','2024 Model 3 Highland');
$explicit=TeslaVehicleArt::resolve('YJuniper',null,mockVin('Y','S'));
expect($explicit['variant']==='y-juniper','explicit Tesla trim beats ambiguous VIN');
$manual=TeslaVehicleArt::resolve('modely',null,mockVin('Y','S'),'y-classic');
expect($manual['variant']==='y-classic' && $manual['confidence']==='manual','user override wins');
$wrong=TeslaVehicleArt::resolve('model3',null,mockVin('3','R'),'y-classic');
expect($wrong['variant']==='3-highland','cross-model override rejected');
$unknown=TeslaVehicleArt::resolve('modely',null,null);
expect($unknown['variant']===null && $unknown['asset']===null,'missing VIN stays neutral');
$notTesla=TeslaVehicleArt::resolve('modely',null,'WVWZZZ1JZXW000001');
expect($notTesla['variant']===null,'non Tesla VIN must not select variant');
$vinModel=TeslaVehicleArt::resolve(null,null,mockVin('Y','R'));
expect($vinModel['variant']==='y-classic','Tesla VIN may provide model family');
$mismatch=TeslaVehicleArt::resolve('model3',null,mockVin('Y','R'));
expect($mismatch['year']===null && $mismatch['variant']===null,'mismatched VIN must not define year');
expect(!TeslaVehicleArt::validOverride('y-juniper','3'),'do not accept cross-model option');
expect(TeslaVehicleArt::validOverride('auto','3'),'automatic always available');
expect(array_key_exists('y-classic',TeslaVehicleArt::choices('y')),'Y old choice visible');
expect(!array_key_exists('3-classic',TeslaVehicleArt::choices('y')),'3 choices hidden for Y');
expect(TeslaVehicleArt::overrideKey(17)==='vehicle_art_variant_17','settings key isolation');
foreach (['3-classic','3-highland','y-classic','y-juniper','s','x'] as $variant) {
    $asset=dirname(__DIR__) . '/public/' . TeslaVehicleArt::asset($variant);
    expect(file_exists($asset),'missing packaged file: '.$asset);
    expect(filesize($asset)>1000,'image unexpectedly empty: '.$asset);
}
echo "Tesla VIN / facelift / manual override / asset regression: PASS\n";

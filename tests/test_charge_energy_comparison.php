<?php
require dirname(__DIR__) . '/src/ChargeEnergyComparison.php';
function checkEnergy(bool $ok, string $test): void { if (!$ok) throw new RuntimeException('FAIL: '.$test); echo "PASS: $test\n"; }
$example = ChargeEnergyComparison::calculate(1.90, 2.14);
checkEnergy($example !== null, 'two valid measured energies compare');
checkEnergy(abs($example['difference_kwh'] - 0.24) < 0.00001, 'difference 0.24 kWh');
checkEnergy(abs($example['difference_pct_meter'] - 11.21495327) < 0.001, '11.2 percent relative to VoltCore');
checkEnergy(abs($example['vehicle_share'] - 88.78504672) < 0.001, 'meter bar ratio correct');
checkEnergy(ChargeEnergyComparison::calculate(1.90, null) === null, 'absent VoltCore input never invents comparison');
checkEnergy(ChargeEnergyComparison::calculate(null, 2.14) === null, 'absent Tesla input never invents comparison');
checkEnergy(ChargeEnergyComparison::calculate(-1, 2.14) === null, 'negative values rejected');
checkEnergy(ChargeEnergyComparison::calculate(INF, 2.14) === null, 'infinite values rejected');
$reverse = ChargeEnergyComparison::calculate(3.00, 2.50);
checkEnergy(!$reverse['meter_exceeds_vehicle'] && abs($reverse['meter_share'] - 83.3333) < 0.01, 'reverse difference meter marker supported');
checkEnergy(ChargeEnergyComparison::calculate(1, 0)['difference_pct_meter'] === null, 'zero meter energy has no invalid percent');
echo "Charge comparison checks passed\n";

<?php

declare(strict_types=1);

/**
 * Tesla family + generation selection with original TrakFog-created studio artwork. VIN decoding is local and conservative.
 * No Tesla-supplied images are distributed; these AVIF renders were newly generated for TrakFog.\n * Tesla's tenth VIN character identifies a year, NOT the facelift directly.
 * A manual per-vehicle override always wins when it matches the vehicle family.
 */
final class TeslaVehicleArt
{
    private const YEARS = [
        'H'=>2017, 'J'=>2018, 'K'=>2019,
        'L'=>2020, 'M'=>2021, 'N'=>2022, 'P'=>2023,
        'R'=>2024, 'E'=>2024, 'S'=>2025, 'T'=>2026, 'V'=>2027,
    ];
    private const ASSETS = [
        '3-classic'=>'assets/vehicles/model-3-classic.avif',
        '3-highland'=>'assets/vehicles/model-3-white.avif',
        'y-classic'=>'assets/vehicles/model-y-classic.avif',
        'y-juniper'=>'assets/vehicles/model-y-white.avif',
        's'=>'assets/vehicles/model-s-white.avif',
        'x'=>'assets/vehicles/model-x-white.avif',
    ];
    private const LABELS = [
        '3-classic'=>'Model 3 · Klassisch',
        '3-highland'=>'Model 3 · Highland',
        'y-classic'=>'Model Y · Klassisch',
        'y-juniper'=>'Model Y · Juniper',
        's'=>'Model S',
        'x'=>'Model X',
    ];
    public static function family(?string $carType, ?string $modelName = null): ?string
    {
        foreach ([$carType, $modelName] as $candidate) {
            $name = strtolower(trim((string)$candidate));
            $name = preg_replace('/[^a-z0-9]+/', '', $name) ?? $name;
            $result = match (true) {
                in_array($name,['model3','3','m3','3highland'],true),
                    str_starts_with($name,'model3') => '3',
                in_array($name,['models','s','ms','splaid'],true),
                    str_starts_with($name,'models') => 's',
                in_array($name,['modelx','x','mx','xplaid'],true),
                    str_starts_with($name,'modelx') => 'x',
                in_array($name,['modely','y','my','yjuniper'],true),
                    str_starts_with($name,'modely') => 'y',
                default => null,
            };
            if ($result !== null) return $result;
        }
        return null;
    }
    /** Existing dashboard compatibility; use resolve() for generation-aware art. */
    public static function modelKey(?string $carType, ?string $modelName = null): string
    {
        return self::family($carType,$modelName) ?? 'y';
    }
    public static function asset(string $modelKey): string
    {
        return self::ASSETS[$modelKey] ?? match ($modelKey) {
            '3'=>self::ASSETS['3-highland'],
            's'=>self::ASSETS['s'],
            'x'=>self::ASSETS['x'],
            default=>self::ASSETS['y-juniper'],
        };
    }
    public static function validOverride(string $value, ?string $family): bool
    {
        if ($value === 'auto') return true;
        return match ($family) {
            '3'=>in_array($value,['3-classic','3-highland'],true),
            'y'=>in_array($value,['y-classic','y-juniper'],true),
            's'=>$value === 's',
            'x'=>$value === 'x',
            default=>false,
        };
    }
    public static function overrideKey(int $vehicleId): string
    {
        return 'vehicle_art_variant_' . max(0,$vehicleId);
    }
    public static function override(PDO $pdo, int $vehicleId): string
    {
        return (string)setting($pdo,self::overrideKey($vehicleId),'auto');
    }
    public static function saveOverride(PDO $pdo, int $vehicleId, string $variant, ?string $family): void
    {
        if ($vehicleId <= 0 || !self::validOverride($variant,$family)) {
            throw new InvalidArgumentException('Ungültige Fahrzeugvariante.');
        }
        $stmt=$pdo->prepare(
            "INSERT INTO settings (setting_key,setting_value) VALUES (?,?)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
        );
        $stmt->execute([self::overrideKey($vehicleId),$variant]);
    }
    public static function choices(?string $family): array
    {
        $result=['auto'=>'Automatisch anhand VIN / gespeicherter Fahrzeugdaten'];
        foreach (self::LABELS as $key=>$label) {
            if (self::validOverride($key,$family)) $result[$key]=$label;
        }
        return $result;
    }
    /** @return array{year:int|null,model:string|null,plant:string|null} */
    public static function vinInfo(?string $vin): array
    {
        $s=strtoupper(trim((string)$vin));
        $empty=['year'=>null,'model'=>null,'plant'=>null];
        if (strlen($s)!==17 || !preg_match('/^[A-HJ-NPR-Z0-9]{17}$/',$s)) return $empty;
        if (!in_array(substr($s,0,3),['5YJ','7SA','LRW','XP7'],true)) return $empty;
        $model = match ($s[3]) {'3'=>'3','Y'=>'y','S'=>'s','X'=>'x',default=>null};
        if ($model===null) return $empty;
        $year=self::YEARS[$s[9]]??null;
        return ['year'=>$year,'model'=>$model,'plant'=>$s[10]];
    }
    /**
     * Neutral when generation is unknown; never silently claim facelift based
     * on a nickname, year 2025 Model Y, or transitional 2023 Model 3.
     */
    public static function resolve(?string $carType, ?string $modelName, ?string $vin, string $override='auto'): array
    {
        $family=self::family($carType,$modelName);
        $vinInfo=self::vinInfo($vin);
        if ($family===null) $family=$vinInfo['model'];
        $year=($family!==null && $vinInfo['model']===$family) ? $vinInfo['year'] : null;
        $variant=null;
        $source='unbekannt';
        $confidence='unknown';
        if ($family==='s' || $family==='x') {
            $variant=$family;$source='Modell';$confidence='high';
        } elseif ($family==='3' || $family==='y') {
            if ($year!==null) {
                if ($family==='3') {
                    if ($year<=2022) $variant='3-classic';
                    if ($year>=2024) $variant='3-highland';
                } else {
                    if ($year<=2024) $variant='y-classic';
                    if ($year>=2026) $variant='y-juniper';
                }
                if ($variant!==null) {$source='VIN-Jahr';$confidence='likely';}
                else {$source='Übergangsjahr';$confidence='uncertain';}
            }
            // Explicit Tesla model/trim metadata is more informative than ambiguous VIN year.
            $candidate=strtolower((string)$carType.' '.(string)$modelName);
            if (preg_match('/(highland|juniper)/i',$candidate)) {
                $variant=$family==='3'?'3-highland':'y-juniper';
                $source='Tesla-Konfiguration';$confidence='high';
            }
        }
        if ($override!=='auto' && self::validOverride($override,$family)) {
            $variant=$override;$source='Manuell bestätigt';$confidence='manual';
        }
        return [
            'family'=>$family, 'variant'=>$variant, 'asset'=>$variant?self::ASSETS[$variant]:null,
            'label'=>$variant?self::LABELS[$variant]:null,
            'source'=>$source, 'confidence'=>$confidence, 'year'=>$year,
            'needs_selection'=>$variant===null && in_array($family,['3','y'],true),
        ];
    }
}

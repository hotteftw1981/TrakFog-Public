from pathlib import Path
try:
 from PIL import Image
except ImportError:
 Image=None
from zipfile import ZipFile
import re,sys,subprocess
root=Path(__file__).resolve().parents[1]
version=(root/'VERSION').read_text().strip()
checks={
'4 model artwork': all((root/f'public/assets/vehicles/model-{m}-white.avif').is_file() for m in ('3','s','x','y')),
'generation-aware dashboard': all(s in (root/'public/app.php').read_text() for s in ('TeslaVehicleArt::resolve','data-car-asset','dashboard-thumb-link')),
'VIN variants and override': all(s in (root/'src/TeslaVehicleArt.php').read_text() for s in ('vinInfo(', 'saveOverride(', 'needs_selection')),
'classic model artwork': all((root/'public/assets/vehicles'/x).is_file() for x in ('model-y-classic.avif','model-3-classic.avif')),
'favorite-selected asset switch': 'primaryImage.setAttribute' in (root/'public/assets/js/app.js').read_text(),
'fleet map shows additional cars': all(s in (root/'public/assets/js/liveview.js').read_text() for s in ('otherVehicleMarkers','data.vehicles','selectedVehicleId=id')),
'no extra Tesla requests': 'TeslaService::refresh' not in (root/'public/live-data.php').read_text(),
'live stopovers': all(s in (root/'public/assets/js/liveview.js').read_text() for s in ('routeSegments','stopMarkers','continuationStorage')),
'active merge suggestion': 'TripMerge::suggestionFor' in (root/'public/live-data.php').read_text(),
'admin-only permanent write': 'Auth::requireAdmin()' in (root/'public/trip-merge.php').read_text(),
'CSRF on writes': 'Csrf::validate(' in (root/'public/trip-merge.php').read_text(),
'real trip consolidation': 'UPDATE trips SET' in (root/'src/TripConsolidation.php').read_text() and 'DELETE FROM trips' in (root/'src/TripConsolidation.php').read_text(),
'consolidation audit storage': 'CREATE TABLE IF NOT EXISTS trip_consolidations' in (root/'database/migrations/0.1.1.63.sql').read_text(),
'single-trip destination': "$return='trip.php?id='" in (root/'public/trip-merge.php').read_text(),
'liveview preview kept local': 'nur eine lokale Kartenvorschau' in (root/'README.md').read_text(),
'release version': bool(re.fullmatch(r'\d+\.\d+\.\d+\.\d+', version)),
'docker label': f'x-trakfog-version: "{version}"' in (root/'docker-compose.yml').read_text(),
'readme version': f'**V{version}**' in (root/'README.md').read_text(),
'web documentation version': f'<b>V{version}</b>' in (root/'docs/index.html').read_text(),
'full beta bundle includes migrations': (root/'database/migrations/0.1.1.63.sql').exists(),
}
for model in ['3','s','x','y']:
 path=root/f'public/assets/vehicles/model-{model}-white.avif'
 raw=path.read_bytes() if path.is_file() else b''
 checks['Model '+model+' generated AVIF studio render']=(
  len(raw)>10000
  and raw[4:12]==b'ftypavif'
 )
for name,ok in checks.items():print(('PASS' if ok else 'FAIL')+': '+name)
if not all(checks.values()):sys.exit(1)
print('TESTS:',len(checks),'passed')

# Keep the new release features part of the existing, automatically invoked Docker QA.
for label, command in [
    ('compact trip UI interaction', ['node', 'tests/test_compact_trips.cjs']),
    ('VoltCore energy measurement comparison', ['php', 'tests/test_charge_energy_comparison.php']),
    ('Trip SoC and charging overlap', ['php', 'tests/test_trip_soc.php']),
    ('Trip closing at charge start', ['python3', '-m', 'unittest', 'discover', '-s', 'tests', '-p', 'test_trip_charge_boundary.py', '-v']),
]:
    result = subprocess.run(command, cwd=root, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if result.returncode:
        print('FAIL: ' + label + '\n' + result.stdout)
        sys.exit(1)
    print('PASS: ' + label)

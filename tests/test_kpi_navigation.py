import unittest
from pathlib import Path
import re
ROOT=Path(__file__).resolve().parents[1]
PAGES={
 'app.php':4, 'drive.php':4, 'trips.php':4,'charges.php':4,
 'statistics.php':4,'journeys.php':4,'journey.php':6,
 'trip.php':4,'charge.php':4,'sleep.php':4,'trip-group.php':4,'connect.php':3
}
class NavigationAndTelemetry(unittest.TestCase):
 def test_every_kpi_tile_is_semantic_anchor(self):
  for page,expected in PAGES.items():
   with self.subTest(page=page):
    data=(ROOT/'public'/page).read_text()
    self.assertEqual(len(re.findall(r'class="stat tf-stat-link"',data)),expected)
    self.assertNotIn('<div class="stat">',data)
    links=re.findall(r'<a class="stat tf-stat-link" href="([^"]+)"',data)
    self.assertEqual(len(links),expected)
    self.assertTrue(all(link and not link.startswith('javascript:') for link in links))
 def test_internal_anchor_targets_exist(self):
  for page in PAGES:
   data=(ROOT/'public'/page).read_text()
   for link in re.findall(r'<a class="stat tf-stat-link" href="([^"]+)"',data):
    if '#' not in link:continue
    pathname,anchor=link.split('#',1)
    dest=ROOT/'public'/(pathname or page)
    self.assertTrue(dest.exists(),(page,link))
    self.assertIn(f'id="{anchor}"',dest.read_text(),(page,link))
 def test_live_map_drag_and_follow(self):
  js=(ROOT/'public/assets/js/liveview.js').read_text()
  css=(ROOT/'public/assets/css/liveview.css').read_text()
  self.assertIn('dragging:true',js)
  self.assertIn("map.on('dragstart'",js)
  self.assertIn('mapFollow=false',js)
  self.assertIn('if(mapFollow)',js)
  self.assertIn("button.setAttribute('aria-pressed',String(mapFollow))",js)
  self.assertIn('pointer-events:auto;',css[css.index('/* Live Map: the driver may pan manually'):])
 def test_chart_gap_and_peak(self):
  js=(ROOT/'public/assets/js/charts.js').read_text()
  php=(ROOT/'public/statistics.php').read_text()
  self.assertIn('MAX(CASE WHEN speed_kmh BETWEEN 0 AND 350 THEN speed_kmh END)',php)
  self.assertNotIn('AVG(speed_kmh) AS speed_kmh',php)
  self.assertIn('point.x - segment[segment.length - 1].x > maxGapMs',js)
  self.assertIn('Datenlücke(n) nicht verbunden',js)
  self.assertIn('nicht aufgezeichnete Geschwindigkeitsspitzen',php)
if __name__=='__main__': unittest.main()

"""Regression tests for false D/P trips and safe handling of sparse Tesla data."""
from __future__ import annotations

import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'worker'))
from trakfog_worker.trip_quality import discard_tentative_trip


class TripQualityTests(unittest.TestCase):
    def base(self):
        return dict(source='tesla_stream', distance_km=0.0,
                    start_odometer_km=12000.0, end_odometer_km=12000.0,
                    start_latitude=51.2770, start_longitude=7.2890,
                    end_latitude=51.2770, end_longitude=7.2890,
                    max_speed_kmh=0.0)

    def test_stationary_d_then_p_discarded(self):
        self.assertTrue(discard_tentative_trip(self.base()))

    def test_tiny_parking_shuffle_discarded(self):
        t=self.base() | {'distance_km': .04, 'end_odometer_km': 12000.04,
                         'end_latitude': 51.27725, 'max_speed_kmh': 4}
        self.assertTrue(discard_tentative_trip(t))

    def test_exact_100_meter_odometer_trip_kept(self):
        t=self.base() | {'end_odometer_km': 12000.1, 'distance_km': .1}
        self.assertFalse(discard_tentative_trip(t))

    def test_gps_keeps_trip_when_odometer_stale(self):
        t=self.base() | {'end_latitude': 51.27825}
        self.assertFalse(discard_tentative_trip(t))

    def test_both_sensors_missing_never_auto_delete(self):
        t=self.base() | {'start_odometer_km': None, 'end_odometer_km': None,
                         'start_latitude': None, 'end_latitude': None}
        self.assertFalse(discard_tentative_trip(t))

    def test_sensor_dropout_fast_speed_never_auto_delete(self):
        t=self.base() | {'end_odometer_km': 12000.01, 'max_speed_kmh': 55}
        self.assertFalse(discard_tentative_trip(t))

    def test_odo_reset_ambiguous_never_auto_delete(self):
        t=self.base() | {'end_odometer_km': 11999.0, 'start_latitude': None}
        self.assertFalse(discard_tentative_trip(t))

    def test_jitter_not_counted_as_100_meters(self):
        t=self.base() | {'end_latitude': 51.27707, 'end_longitude': 7.28903}
        self.assertTrue(discard_tentative_trip(t))

    def test_import_source_preserved(self):
        t=self.base() | {'source':'teslamate'}
        self.assertFalse(discard_tentative_trip(t))

    def test_filter_can_be_disabled(self):
        self.assertFalse(discard_tentative_trip(self.base(), minimum_meters=0))

    def test_blank_gps_and_odometer_unavailable_safe(self):
        t=self.base() | {'start_odometer_km':None,'end_odometer_km':None,
                         'start_latitude':0,'start_longitude':0}
        self.assertFalse(discard_tentative_trip(t))


if __name__ == '__main__':
    unittest.main(verbosity=2)

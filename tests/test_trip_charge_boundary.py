"""Regression: a charging session cannot leak its +SoC into an open trip."""
from __future__ import annotations

import sys
import types
import unittest
from pathlib import Path
from types import SimpleNamespace

# The host QA runner may lack the worker-only dependency; its database is mocked.
try:
    import pymysql
except ImportError:
    fake = types.ModuleType('pymysql')
    cursors = types.ModuleType('pymysql.cursors')
    cursors.DictCursor = object
    fake.cursors = cursors
    sys.modules['pymysql'] = fake
    sys.modules['pymysql.cursors'] = cursors

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'worker'))
from trakfog_worker.db import Database


class FakeCursor:
    def __init__(self, trip=None, last_driving_sample=None):
        self.trip = trip
        self.motion = last_driving_sample
        self._row = None
        self.commands = []
        self.rowcount = 1

    def execute(self, sql, values):
        self.commands.append((sql, values))
        if 'SELECT *' in sql and 'FROM trips' in sql:
            self._row = self.trip
        elif 'FROM vehicle_stream_samples' in sql:
            self._row = self.motion
        else:
            self._row = None

    def fetchone(self):
        return self._row

    def __enter__(self):
        return self

    def __exit__(self, _type, _value, _traceback):
        return False

    def update(self):
        found = [x for x in self.commands if 'UPDATE trips' in x[0]]
        if len(found) != 1:
            raise AssertionError(f'expecting one UPDATE trips, found {len(found)}')
        return found[0]


class FakeConnection:
    def __init__(self, cursor):
        self._cursor = cursor

    def __enter__(self):
        return self

    def __exit__(self, _type, _value, _traceback):
        return False

    def cursor(self):
        return self._cursor


def stored_trip():
    return dict(id=7, vehicle_id=1, started_at='2026-10-09 05:11:25',
                last_sample_at='2026-10-09 05:16:40',
                start_odometer_km=47260.3, end_odometer_km=47264.0,
                start_soc=76.0, end_soc=79.0,  # contaminated raw end
                distance_km=3.7, energy_kwh=.38, max_speed_kmh=89.0,
                start_latitude=51.277, start_longitude=7.28,
                end_latitude=51.28, end_longitude=7.29, source='tesla_stream')


def driving_sample():
    return dict(recorded_at='2026-10-09 05:16:30', odometer_km=47264.0,
                soc=76.0, range_km=307.0, latitude=51.28,
                longitude=7.29, speed_kmh=2.5)


class TripChargeBoundaryTests(unittest.TestCase):
    def setUp(self):
        self.db = Database(SimpleNamespace(min_trip_distance_meters=100))

    def test_active_charge_ends_trip_at_last_driving_measurement(self):
        cur = FakeCursor(trip=stored_trip(), last_driving_sample=driving_sample())
        self.db._close_open_trip_at_charge_start(cur, 1, '2026-10-09 05:17:15')
        sql, values = cur.update()
        self.assertIn('ended_at=%s', sql)
        self.assertEqual(values[0], '2026-10-09 05:16:30')
        self.assertEqual(values[4], 76.0)
        self.assertNotEqual(values[4], 79.0)
        self.assertEqual(values[-1], 7)
        self.assertEqual(values[6], 3.7)

    def test_rest_charge_poll_closes_trip_before_creating_charge(self):
        cur = FakeCursor(trip=stored_trip(), last_driving_sample=driving_sample())
        self.db.connection = lambda: FakeConnection(cur)
        self.db.update_charge_from_rest(1, '2026-10-09 05:17:15',
                                        dict(charging_state='Charging',
                                             charger_power=11,
                                             battery_level=76,
                                             charge_energy_added=0.0),
                                        dict(latitude=51.28, longitude=7.29))
        statements = [sql for sql, _ in cur.commands]
        close_index = next(i for i, sql in enumerate(statements) if 'UPDATE trips' in sql)
        insert_index = next(i for i, sql in enumerate(statements) if 'INSERT INTO charges' in sql)
        self.assertLess(close_index, insert_index, 'trip must be finalized before charge session starts')
        self.assertEqual(cur.update()[1][4], 76.0)

    def test_no_open_trip_means_no_write(self):
        cur = FakeCursor()
        self.db._close_open_trip_at_charge_start(cur, 1, '2026-10-09 05:17:15')
        self.assertEqual(len(cur.commands), 1)

    def test_when_motion_is_missing_do_not_take_new_charging_soc(self):
        cur = FakeCursor(trip=stored_trip())
        self.db._close_open_trip_at_charge_start(cur, 1, '2026-10-09 05:17:15')
        _, values = cur.update()
        self.assertEqual(values[0], '2026-10-09 05:16:40')
        self.assertIsNone(values[4])

    def test_parked_update_freezes_end_soc_and_energy(self):
        cur = FakeCursor(trip=stored_trip())
        sample = dict(recorded_at='2026-10-09 05:29:30', shift_state='P',
                      speed_kmh=0.0, soc=79, range_km=312, odometer_km=47264.0,
                      latitude=51.28, longitude=7.29, power_kw=-11)
        previous = dict(recorded_at='2026-10-09 05:29:20', shift_state='D',
                        speed_kmh=0, power_kw=-11)
        self.db._update_trip_from_sample(cur, 1, sample, previous)
        sql, values = cur.update()
        self.assertIn('end_soc=COALESCE(%s,end_soc)', sql)
        self.assertIn('WHERE id=%s AND ended_at IS NULL', sql, 'stream update cannot reopen a trip already closed by charging poll')
        self.assertEqual(values[0], sample['recorded_at'])
        self.assertIsNone(values[4], 'parked charging SoC must be ignored')
        self.assertIsNone(values[5], 'parked charging range must be ignored')
        self.assertEqual(values[7], .38, 'charging power is not driving energy')


if __name__ == '__main__':
    unittest.main(verbosity=2)

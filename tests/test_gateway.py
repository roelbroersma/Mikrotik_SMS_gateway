"""Behavior checks against an isolated fake RouterOS, including process races."""

from concurrent.futures import ThreadPoolExecutor
import json
import re
import subprocess
import time
import unittest

from support import PHP, ROOT, Sandbox


class GatewayTests(unittest.TestCase):
    def setUp(self):
        self.sandbox = Sandbox(QUEUE_MAX_AGE_SECONDS="86400")
        self.addCleanup(self.sandbox.close)

    def php(self, expression, **kwargs):
        return self.sandbox.evaluate(expression, **kwargs)

    def snapshot(self):
        return self.php("gateway_snapshot()")

    def mutate(self, statements):
        return self.php("gateway_with_state(function (&$s, $c, $now) { " + statements + " return true; })")

    def warm(self):
        # Model a runtime that has already completed its startup wait.
        self.mutate("$s['cooldown_mono'] = $now - 1; $s['origin_mono'] = max(0, $now - 3601);")

    def advance(self, seconds):
        # Move fixture timestamps backwards under the real state lock. Production
        # still reads its actual monotonic clock; no test clock is exposed by HTTP.
        self.mutate("""
            $delta = %s;
            foreach (array('origin_mono','cooldown_mono','last_attempt_mono') as $key)
                if (isset($s[$key])) $s[$key] = max(0, $s[$key] - $delta);
            foreach ($s['reservations'] as &$stamp) $stamp -= $delta;
            unset($stamp);
            foreach ($s['queue'] as &$item) {
                foreach (array('created_mono','next_attempt_mono','sending_mono') as $key)
                    if (isset($item[$key])) $item[$key] -= $delta;
            }
            unset($item);
            foreach ($s['incoming'] as &$bucket) {
                $bucket['expires'] -= $delta;
                foreach ($bucket['timestamps'] as &$stamp) $stamp -= $delta;
                unset($stamp);
            }
            unset($bucket);
        """ % float(seconds))

    def enqueue(self, text="Fixture SMS", source="192.0.2.1", request_id=None):
        args = ["+31612345678", text, source, request_id]
        return self.php("gateway_enqueue(...json_decode(" + json.dumps(json.dumps(args)) + ", true))")

    def tick(self, **kwargs):
        return self.php("gateway_tick()", **kwargs)

    def test_fifo_iso_metadata_and_default_ten_second_spacing(self):
        self.warm()
        records = [self.enqueue(f"message-{index}") for index in range(3)]
        snapshot = self.snapshot()
        self.assertEqual([r["id"] for r in records], [r["id"] for r in snapshot["queue"]])
        for record in snapshot["queue"]:
            self.assertRegex(record["created_at"], r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$")
            self.assertEqual("192.0.2.1", record["source_ip"])
            self.assertEqual(0, record["attempts"])
        self.assertEqual("sent", self.tick()["status"])
        self.assertEqual("waiting", self.tick()["status"])
        self.advance(9)
        self.assertEqual("waiting", self.tick()["status"])
        self.advance(1.1)
        self.assertEqual("sent", self.tick()["status"])
        self.advance(10.1)
        self.assertEqual("sent", self.tick()["status"])
        self.assertEqual(["message-0", "message-1", "message-2"],
                         [call["body"]["message"] for call in self.sandbox.router.sends])
        self.assertEqual([], self.snapshot()["queue"])

    def test_default_twenty_attempt_rolling_cap_survives_new_process_and_pause(self):
        self.warm()
        for index in range(22):
            self.enqueue(f"cap-{index}", f"192.0.2.{index + 1}")
        for _ in range(20):
            self.assertEqual("sent", self.tick()["status"])
            self.advance(10.1)
        self.assertEqual(20, len(self.sandbox.router.sends))
        self.assertEqual("waiting", self.tick()["status"])
        self.php("gateway_set_paused(true)")
        self.php("gateway_set_paused(false)")
        self.php("gateway_save_settings(array('interval_seconds' => 5))")
        self.assertEqual("waiting", self.tick()["status"])
        self.assertEqual(20, self.snapshot()["attempts_in_window"])
        # The pure budget calculation also accepts a hypothetical future instant.
        # This checks the rolling boundary without a one-hour sleep or invalid RAM clocks.
        future = self.php("gateway_with_state(function (&$s,$c,$now) { return gateway_budget($s,$c,$now+3601); })")
        self.assertEqual(0, future["attempts"])

    def test_cold_start_and_lost_ram_do_not_reset_send_allowance(self):
        self.enqueue()
        initial = self.snapshot()
        self.assertGreaterEqual(initial["cooldown_until"] - initial["now"], 3599)
        self.assertEqual("waiting", self.tick()["status"])
        self.warm()
        self.assertEqual("sent", self.tick()["status"])
        (self.sandbox.runtime / "state.json").unlink()
        self.enqueue("new-runtime")
        self.assertEqual("waiting", self.tick()["status"])
        self.assertEqual(1, len(self.sandbox.router.sends))
        self.assertGreaterEqual(self.snapshot()["cooldown_until"] - self.snapshot()["now"], 3599)

    def test_expiry_and_queue_capacity_are_bounded(self):
        self.sandbox.env.update(QUEUE_MAX_MESSAGES="2", QUEUE_MAX_AGE_SECONDS="60")
        self.warm()
        self.enqueue("first")
        self.enqueue("second")
        result = self.php("(function () { try { gateway_enqueue('+31612345678','overflow','192.0.2.1'); } catch (GatewayException $e) { return $e->httpStatus; } })()")
        self.assertEqual(429, result)
        self.advance(61)
        self.assertEqual("waiting", self.tick()["status"])
        snapshot = self.snapshot()
        self.assertEqual([], snapshot["queue"])
        self.assertEqual(["expired", "expired"], [r["status"] for r in snapshot["history"]])
        self.assertEqual([], self.sandbox.router.sends)

    def test_retries_count_before_send_and_stop_after_three_attempts(self):
        self.warm()
        self.enqueue()
        for attempt, delay in ((1, 60.1), (2, 300.1), (3, 300.1)):
            self.sandbox.router.respond(429, {"error": 429, "message": "fixture busy"})
            self.assertEqual("retry" if attempt < 3 else "failed", self.tick()["status"])
            snapshot = self.snapshot()
            self.assertEqual(attempt, snapshot["attempts_in_window"])
            if attempt < 3:
                self.assertEqual(attempt, snapshot["queue"][0]["attempts"])
                self.assertEqual("waiting", self.tick()["status"])
            self.advance(delay)
        self.assertEqual([], self.snapshot()["queue"])
        self.assertEqual("failed", self.snapshot()["history"][0]["status"])
        self.assertEqual("waiting", self.tick()["status"])
        self.assertEqual(3, len(self.sandbox.router.sends))

    def test_timeout_is_uncertain_and_never_automatically_retried(self):
        self.warm()
        self.enqueue("timeout-fixture")
        self.sandbox.router.respond(delay=26)
        self.assertEqual("uncertain", self.tick(timeout=30)["status"])
        snapshot = self.snapshot()
        self.assertEqual([], snapshot["queue"])
        self.assertEqual("uncertain", snapshot["history"][0]["status"])
        self.advance(11)
        self.assertEqual("waiting", self.tick()["status"])
        self.assertEqual(1, len(self.sandbox.router.sends))

    def test_concurrent_enqueues_and_workers_do_not_lose_or_duplicate_records(self):
        self.warm()
        with ThreadPoolExecutor(max_workers=8) as pool:
            records = list(pool.map(lambda n: self.enqueue(f"parallel-{n}"), range(20)))
        self.assertEqual(20, len({record["id"] for record in records}))
        self.assertEqual(20, len(self.snapshot()["queue"]))
        self.sandbox.router.respond(delay=0.3)
        with ThreadPoolExecutor(max_workers=8) as pool:
            results = list(pool.map(lambda _: self.tick(), range(8)))
        self.assertEqual(1, sum(result["status"] == "sent" for result in results))
        self.assertEqual(1, len(self.sandbox.router.sends))
        snapshot = self.snapshot()
        self.assertEqual(19, len(snapshot["queue"]))
        self.assertEqual(20, len({r["id"] for r in snapshot["queue"] + snapshot["history"]}))

    def test_enqueue_remains_available_while_modem_is_slow(self):
        self.warm()
        self.enqueue("slow-send")
        self.sandbox.router.respond(delay=0.7)
        with ThreadPoolExecutor(max_workers=2) as pool:
            tick = pool.submit(self.tick)
            deadline = time.monotonic() + 3
            while not self.sandbox.router.sends and time.monotonic() < deadline:
                time.sleep(0.01)
            self.assertTrue(self.sandbox.router.sends)
            started = time.monotonic()
            self.enqueue("arrived-during-send")
            self.assertLess(time.monotonic() - started, 0.5)
            self.assertEqual("sent", tick.result()["status"])
        self.assertEqual("arrived-during-send", self.snapshot()["queue"][0]["text"])

    def test_interrupted_worker_marks_record_uncertain_instead_of_resending(self):
        self.warm()
        self.enqueue("interrupted")
        self.mutate("$s['queue'][0]['status'] = 'sending'; $s['queue'][0]['attempts'] = 1; $s['reservations'][] = $now; $s['last_attempt_mono'] = $now;")
        self.advance(11)
        self.assertEqual("waiting", self.tick()["status"])
        self.assertEqual("uncertain", self.snapshot()["history"][0]["status"])
        self.assertEqual([], self.sandbox.router.sends)

    def test_pause_and_snapshot_do_not_write_persistent_config(self):
        self.warm()
        self.enqueue()
        self.php("gateway_set_paused(true)")
        self.assertEqual("waiting", self.tick()["status"])
        self.snapshot()
        self.assertEqual([], list(self.sandbox.config.iterdir()))
        self.php("gateway_save_settings(array('interval_seconds' => 5, 'limit' => 7))")
        config = self.sandbox.config / "settings.json"
        saved = config.read_text()
        self.assertNotIn("fixture-router-password", saved)
        self.assertNotIn("fixture-admin-password", saved)
        self.assertEqual(7, self.snapshot()["limit"])
        before = config.stat().st_mtime_ns
        self.php("gateway_set_paused(false)")
        self.assertEqual("sent", self.tick()["status"])
        self.snapshot()
        self.assertEqual(before, config.stat().st_mtime_ns)

    def test_emergency_pause_survives_saving_unchanged_startup_pause_setting(self):
        self.warm()
        self.enqueue("stay-paused")
        self.php("gateway_set_paused(true)")
        self.assertFalse(self.snapshot()["settings"]["paused"])
        # The form submits the unchanged startup default alongside edited settings.
        self.php("gateway_save_settings(array('interval_seconds' => 15, 'paused' => false))")
        snapshot = self.snapshot()
        self.assertTrue(snapshot["paused"])
        self.assertFalse(snapshot["settings"]["paused"])
        self.assertEqual(15, snapshot["settings"]["interval_seconds"])
        self.assertEqual("waiting", self.tick()["status"])
        self.assertEqual([], self.sandbox.router.sends)
        self.php("gateway_set_paused(false)")
        self.assertEqual("sent", self.tick()["status"])

    def test_idempotent_caller_retry_reuses_record_and_rejects_changed_message(self):
        first = self.enqueue("idempotent", request_id="fixture-key")
        again = self.enqueue("idempotent", request_id="fixture-key")
        self.assertEqual(first["id"], again["id"])
        self.assertEqual(1, len(self.snapshot()["queue"]))
        result = self.php("(function () { try { gateway_enqueue('+31612345678','changed','192.0.2.1','fixture-key'); } catch (GatewayException $e) { return $e->httpStatus; } })()")
        self.assertEqual(409, result)

    def test_larger_limit_window_extends_cooldown_when_old_history_is_unknown(self):
        self.sandbox.env["SEND_LIMITS"] = "20/60"
        self.snapshot()
        self.mutate("$s['origin_mono'] = max(0, $now - 61); $s['cooldown_mono'] = 0;")
        self.php("gateway_save_settings(array('window_seconds' => 3600))")
        snapshot = self.snapshot()
        self.assertGreaterEqual(snapshot["cooldown_until"] - snapshot["now"], 3538)
        self.enqueue()
        self.assertEqual("waiting", self.tick()["status"])
        self.assertEqual([], self.sandbox.router.sends)

    def test_lowering_attempt_limit_retires_exhausted_retry(self):
        self.warm()
        self.enqueue()
        self.sandbox.router.respond(429, {"error": 429})
        self.assertEqual("retry", self.tick()["status"])
        self.php("gateway_save_settings(array('max_attempts' => 1))")
        self.assertEqual("waiting", self.tick()["status"])
        self.assertEqual([], self.snapshot()["queue"])
        self.assertEqual("failed", self.snapshot()["history"][0]["status"])
        self.assertEqual(1, len(self.sandbox.router.sends))

    def test_corrupt_state_fails_closed_without_router_contact(self):
        self.warm()
        self.enqueue()
        (self.sandbox.runtime / "state.json").write_text('{"broken":')
        result = self.php("(function () { try { gateway_tick(); } catch (GatewayException $e) { return $e->httpStatus; } })()")
        self.assertEqual(503, result)
        self.assertEqual([], self.sandbox.router.sends)


if __name__ == "__main__":
    unittest.main()

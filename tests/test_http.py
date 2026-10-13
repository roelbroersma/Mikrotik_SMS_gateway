"""Real HTTP checks for the incoming API and authenticated control panel."""

import json
import re
import unittest
import urllib.parse

from support import HttpClient, Sandbox


class HttpTests(unittest.TestCase):
    def setUp(self):
        self.sandbox = Sandbox()
        self.addCleanup(self.sandbox.close)
        self.client = None

    def start(self):
        self.client = self.sandbox.server()
        return self.client

    def post(self, path, data, headers=None):
        return self.client.request(path, urllib.parse.urlencode(data).encode(),
                                   {"Content-Type": "application/x-www-form-urlencoded", **(headers or {})})

    def csrf(self, html):
        match = re.search(r'<meta name="csrf-token" content="([a-f0-9]+)"', html)
        self.assertIsNotNone(match, html[:400])
        return match.group(1)

    def login(self):
        status, page, _ = self.client.request("/admin.php")
        self.assertEqual(200, status)
        status, page, _ = self.post("/admin.php?action=login", {
            "csrf": self.csrf(page), "username": "fixture-admin", "password": "fixture-admin-password"})
        self.assertEqual(200, status)
        self.assertIn("Sign out", page)
        return self.csrf(page)

    def test_incoming_limit_uses_connecting_ip_and_not_forwarded_header(self):
        self.sandbox.env["RATE_LIMITS"] = "127.0.0.1:2/600,*:100/600"
        self.start()
        for index in range(2):
            status, body, headers = self.post("/sendsms.php", {"phone": "+31612345678", "text": f"incoming-{index}"},
                                              {"X-Forwarded-For": f"192.0.2.{index + 1}"})
            self.assertEqual(202, status, body)
            self.assertEqual(str(1 - index), headers["X-RateLimit-Remaining"])
        status, body, headers = self.post("/sendsms.php", {"phone": "+31612345678", "text": "blocked"},
                                          {"X-Forwarded-For": "192.0.2.199"})
        self.assertEqual(429, status)
        self.assertGreater(int(headers["Retry-After"]), 0)
        snapshot = self.sandbox.evaluate("gateway_snapshot()")
        self.assertEqual(["127.0.0.1", "127.0.0.1"], [item["source_ip"] for item in snapshot["queue"]])
        self.assertEqual([], self.sandbox.router.sends)

    def test_ip_allowlist_cannot_be_spoofed(self):
        self.sandbox.env["ALLOWED_IP_RANGES"] = "192.0.2.0/24"
        self.start()
        status, _, _ = self.post("/sendsms.php", {"phone": "+31612345678", "text": "denied"},
                                 {"X-Forwarded-For": "192.0.2.1"})
        self.assertEqual(403, status)
        self.assertEqual([], self.sandbox.router.sends)

    def test_message_validation_rejects_arrays_unicode_and_oversize(self):
        self.start()
        for phone, text in (([], "hello"), ("+31612345678", []), ("+31612345678", "x" * 161),
                            ("+31612345678", "😀"), ("+31612345678", "Привет"),
                            ("+31612345678", "^" * 81)):
            status, _, _ = self.client.request("/sendsms.php", json.dumps({"phone": phone, "text": text}).encode(),
                                                {"Content-Type": "application/json"})
            self.assertEqual(400, status)
        status, body, _ = self.post("/sendsms.php", {"phone": "06 12345678", "text": "Euro € and pound £"})
        self.assertEqual(202, status, body)
        status, _, _ = self.client.request("/sendsms.php")
        self.assertEqual(405, status)
        status, _, _ = self.client.request("/sendsms.php", b"x" * 8193, {"Content-Type": "application/json"})
        self.assertEqual(413, status)

    def test_admin_requires_credentials_and_csrf_for_mutations(self):
        self.start()
        status, body, _ = self.client.request("/admin.php?action=snapshot")
        self.assertEqual(401, status)
        self.assertNotIn("fixture-router-password", body)
        token = self.login()
        status, body, _ = self.post("/admin.php?action=enqueue", {"phone": "+31612345678", "text": "denied"})
        self.assertEqual(403, status)
        status, _, _ = self.client.request("/admin.php?action=pause&paused=true")
        self.assertEqual(405, status)
        status, body, _ = self.post("/admin.php?action=enqueue", {"csrf": token, "phone": "+31612345678", "text": "admin-test"})
        self.assertEqual(202, status, body)
        self.assertEqual([], self.sandbox.router.sends)
        status, body, _ = self.client.request("/admin.php?action=snapshot")
        self.assertEqual(200, status)
        self.assertEqual("admin-test", json.loads(body)["queue"][0]["text"])
        self.assertNotIn("fixture-admin-password", body)
        self.assertNotIn("fixture-router-password", body)
        self.assertEqual([], list(self.sandbox.config.iterdir()))
        self.post("/admin.php?action=logout", {"csrf": token})
        status, _, _ = self.client.request("/admin.php?action=snapshot")
        self.assertEqual(401, status)

    def test_admin_and_api_messages_share_the_same_outgoing_budget(self):
        self.sandbox.env["SEND_LIMITS"] = "1/3600"
        self.start()
        token = self.login()
        self.sandbox.evaluate("gateway_with_state(function (&$s,$c,$now) { $s['origin_mono']=max(0,$now-3601); $s['cooldown_mono']=0; return true; })")
        self.post("/sendsms.php", {"phone": "+31612345678", "text": "from-api"})
        self.post("/admin.php?action=enqueue", {"csrf": token, "phone": "+31612345678", "text": "from-admin"})
        self.assertEqual("sent", self.sandbox.evaluate("gateway_tick()")["status"])
        self.sandbox.evaluate("gateway_with_state(function (&$s) { $s['last_attempt_mono']-=11; return true; })")
        self.assertEqual("waiting", self.sandbox.evaluate("gateway_tick()")["status"])
        self.assertEqual(1, len(self.sandbox.router.sends))
        self.assertEqual("from-admin", self.sandbox.evaluate("gateway_snapshot()")["queue"][0]["text"])

    def test_settings_save_is_validated_and_only_persistent_write(self):
        self.start()
        token = self.login()
        status, body, _ = self.client.request("/admin.php?action=snapshot")
        settings = json.loads(body)["settings"]
        self.assertEqual(300, settings["startup_hold_seconds"])
        settings["startup_hold_seconds"] = 120
        settings["paused"] = "true" if settings["paused"] else "false"
        invalid = {**settings, "interval_seconds": "-1", "csrf": token}
        status, _, _ = self.post("/admin.php?action=save_settings", invalid)
        self.assertEqual(400, status)
        self.assertEqual([], list(self.sandbox.config.iterdir()))
        status, body, _ = self.post("/admin.php?action=save_settings", {**settings, "interval_seconds": "5", "paused": "true", "csrf": token})
        self.assertEqual(200, status, body)
        file = self.sandbox.config / "settings.json"
        self.assertEqual(5, json.loads(file.read_text())["interval_seconds"])
        self.assertTrue(json.loads(file.read_text())["paused"])
        before = file.stat().st_mtime_ns
        self.client.request("/admin.php?action=snapshot")
        self.post("/admin.php?action=pause", {"csrf": token, "paused": "false"})
        self.client.request("/admin.php?action=inbox")
        self.assertEqual(before, file.stat().st_mtime_ns)
        self.assertTrue(all("password" not in key.lower() for key in json.loads(file.read_text())))

    def test_admin_disabled_without_environment_credentials(self):
        self.sandbox.env["ADMIN_PASSWORD"] = ""
        self.start()
        status, _, _ = self.client.request("/admin.php")
        self.assertEqual(503, status)
        status, _, _ = self.client.request("/admin.php?action=snapshot")
        self.assertEqual(503, status)

    def test_admin_login_throttle_and_cookie_policy(self):
        self.start()
        status, page, headers = self.client.request("/admin.php")
        cookie = headers.get("Set-Cookie", "")
        self.assertIn("HttpOnly", cookie)
        self.assertIn("SameSite=Strict", cookie)
        token = self.csrf(page)
        codes = []
        for _ in range(11):
            status, _, headers = self.post("/admin.php?action=login", {"csrf": token, "username": "wrong", "password": "wrong"})
            codes.append(status)
        self.assertIn(401, codes)
        self.assertEqual(429, codes[-1])
        self.assertGreater(int(headers["Retry-After"]), 0)


if __name__ == "__main__":
    unittest.main()

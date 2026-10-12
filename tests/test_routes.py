"""The public listener must not expose internal code or saved configuration."""

import subprocess
import unittest

from support import PHP, ROOT, Sandbox


class PublicSurfaceTests(unittest.TestCase):
    def setUp(self):
        self.sandbox = Sandbox()
        self.addCleanup(self.sandbox.close)
        self.client = self.sandbox.server()

    def test_only_explicit_public_routes_are_served(self):
        for path in ("/.git/config", "/README.md", "/gateway.php", "/worker.php",
                     "/entrypoint.sh", "/config/settings.json", "/%2e%2e/config/settings.json",
                     "/tests/support.py"):
            with self.subTest(path=path):
                status, body, _ = self.client.request(path)
                self.assertEqual(404, status)
                self.assertNotIn("fixture-router-password", body)

    def test_assets_reject_post(self):
        for path in ("/admin.css", "/admin.js"):
            status, _, _ = self.client.request(path, body=b"x=1")
            self.assertEqual(404, status)

    def test_php_files_parse(self):
        for path in sorted(ROOT.glob("*.php")):
            result = subprocess.run([PHP, "-l", str(path)], capture_output=True, text=True)
            self.assertEqual(0, result.returncode, msg=result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()

"""Local-only integration helpers; never contacts a real modem or SMS provider."""

import contextlib
import http.cookiejar
import http.server
import json
import os
from pathlib import Path
import queue
import shutil
import socket
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BIN", "php")


class FakeRouter:
    def __init__(self):
        self.calls = []
        self.behaviors = queue.Queue()
        owner = self

        class Handler(http.server.BaseHTTPRequestHandler):
            def do_POST(self):
                body = self.rfile.read(int(self.headers.get("Content-Length", 0)))
                owner.calls.append({"path": self.path, "body": json.loads(body), "time": time.monotonic()})
                status, payload, delay = 200, {}, 0
                if self.path == "/rest/tool/sms/send":
                    try:
                        status, payload, delay = owner.behaviors.get_nowait()
                    except queue.Empty:
                        pass
                elif self.path != "/rest/execute":
                    status, payload = 404, {"error": "unexpected test endpoint"}
                if delay:
                    time.sleep(delay)
                self.send_response(status)
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                with contextlib.suppress(BrokenPipeError, ConnectionResetError):
                    self.wfile.write(json.dumps(payload).encode())

            def do_GET(self):
                owner.calls.append({"path": self.path, "body": None, "time": time.monotonic()})
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                self.wfile.write(b'[]')

            def log_message(self, *args):
                pass

        self.server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.url = f"http://127.0.0.1:{self.server.server_port}"

    @property
    def sends(self):
        return [call for call in self.calls if call["path"] == "/rest/tool/sms/send"]

    def respond(self, status=200, payload=None, delay=0):
        self.behaviors.put((status, {} if payload is None else payload, delay))

    def close(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=2)


class Sandbox:
    def __init__(self, **overrides):
        # Run an isolated source copy with a monotonic epoch offset. A freshly
        # booted CI runner may not yet have one hour of past timestamps available.
        # Only the test copy changes; the clock still advances at its real rate.
        clock = "function gateway_mono() { return hrtime(true) / 1000000000; }"
        test_clock = "function gateway_mono() { return 864000 + hrtime(true) / 1000000000; }"
        gateway_source = (ROOT / "gateway.php").read_text()
        if gateway_source.count(clock) != 1:
            raise AssertionError("Expected exactly one gateway_mono definition for the test-only clock offset")

        # RAM directories are disposable and never share production state.
        parent = "/dev/shm" if Path("/dev/shm").is_dir() else None
        self.directory = Path(tempfile.mkdtemp(prefix="sms-gateway-test-", dir=parent))
        self.runtime = self.directory / "runtime"
        self.config = self.directory / "config"
        self.project = self.directory / "app"
        self.project.mkdir()
        for source in ROOT.iterdir():
            if source.is_file() and source.suffix in (".php", ".css", ".js"):
                shutil.copyfile(source, self.project / source.name)
        (self.project / "gateway.php").write_text(gateway_source.replace(clock, test_clock))
        self.runtime.mkdir()
        self.config.mkdir()
        self.router = FakeRouter()
        self.env = dict(os.environ)
        self.env.update({
            "SMS_GATEWAY_URL": self.router.url,
            "SMS_STATE_DIR": str(self.runtime),
            "SMS_CONFIG_FILE": str(self.config / "settings.json"),
            "SMS_GATEWAY_USER": "fixture-router-user",
            "SMS_GATEWAY_PASS": "fixture-router-password",
            "ALLOWED_IP_RANGES": "127.0.0.1/32",
            "RATE_LIMITS": "OFF",
            "LOG_TO_FILE": "false",
            "LOG_TO_ROUTEROS": "false",
            "ADMIN_USER": "fixture-admin",
            "ADMIN_PASSWORD": "fixture-admin-password",
        })
        self.env.update({key: str(value) for key, value in overrides.items()})
        self.processes = []

    def php(self, code, timeout=10):
        result = subprocess.run([PHP, "-r", code], cwd=self.project, env=self.env,
                                text=True, capture_output=True, timeout=timeout)
        if result.returncode:
            raise AssertionError(f"PHP exit {result.returncode}: {result.stderr}\n{result.stdout}")
        if result.stderr:
            raise AssertionError(f"Unexpected PHP stderr: {result.stderr}")
        return result.stdout

    def evaluate(self, expression, timeout=10):
        return json.loads(self.php("require 'gateway.php'; echo json_encode(" + expression + ");", timeout=timeout))

    def server(self):
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        process = subprocess.Popen([PHP, "-S", f"127.0.0.1:{port}", "-t", str(self.project), str(self.project / "router.php")],
                                   cwd=self.project, env=self.env, stdout=subprocess.DEVNULL,
                                   stderr=subprocess.DEVNULL, start_new_session=True)
        self.processes.append(process)
        for _ in range(100):
            if process.poll() is not None:
                raise AssertionError("PHP HTTP server failed to start")
            with socket.socket() as sock:
                if sock.connect_ex(("127.0.0.1", port)) == 0:
                    return HttpClient(f"http://127.0.0.1:{port}")
            time.sleep(0.02)
        raise AssertionError("PHP HTTP server did not start")

    def close(self):
        for process in self.processes:
            if process.poll() is None:
                process.terminate()
                process.wait(timeout=3)
        self.router.close()
        shutil.rmtree(self.directory)


class HttpClient:
    def __init__(self, base):
        self.base = base
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))

    def request(self, path, body=None, headers=None, method=None):
        request = urllib.request.Request(self.base + path, data=body, headers=headers or {}, method=method)
        try:
            response = self.opener.open(request, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode(), response.headers

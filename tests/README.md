# Gateway checks

Run from the repository root with Python 3 and PHP 8.2+ (curl and session extensions):

```sh
python3 -m unittest discover -s tests -v
```

If PHP is not on your PATH:

```sh
PHP_BIN=/path/to/php python3 -m unittest discover -s tests -v
```

The integration suite uses a fake RouterOS HTTP server bound to `127.0.0.1`.
Its router URL, credentials, admin credentials and settings are isolated fixtures.
It never sends a real SMS. Runtime and configuration fixtures use disposable
`/dev/shm` directories, and the suite removes them after each test.

Tests exercise requests and worker calls across independent PHP processes.
Each sandbox copies the PHP files and static assets into its temporary app directory.
The copy adds a fixed ten-day offset to `gateway_mono()`, so tests can move fixture
history backwards even on a freshly booted CI runner. The monotonic clock still
advances at its real rate, and the same offset applies to the HTTP server and
worker processes. The replacement requires exactly one matching function.

Elapsed-time tests move only their fixture timestamps under the state lock.
Production source and its state validation remain unchanged; no HTTP parameter
or production environment variable can override the clock. Syntax tests lint
the original production PHP files.

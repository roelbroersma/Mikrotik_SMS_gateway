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
Elapsed-time tests move only their fixture timestamps under the state lock;
production still reads its actual monotonic clock. There is no HTTP parameter
or production environment variable that overrides the clock.

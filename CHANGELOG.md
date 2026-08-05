# Changelog

## v1.1 - 2026-08-05

### Added

- Added `LOG_TO_ROUTEROS`. When enabled, gateway log lines are written to stderr and appear in the RouterOS `/log` when container `logging=yes` is enabled. A successful direct send is logged as `SMS SENT: +316XXXXXXXX - Test message`.

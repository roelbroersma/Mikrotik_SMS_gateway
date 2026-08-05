# Changelog

## v1.1 - 2026-08-05

### Added

- Added `LOG_TO_ROUTEROS`. When enabled, a successful direct send is added directly to the RouterOS `/log` through `/rest/execute` as `script,info`. Container `logging=yes` is not required. Example: `SMS SENT: +316XXXXXXXX - Test message - Source IP: 192.0.2.10`.

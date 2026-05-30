# Proposal: Fix Backup Worker Recovery Race Condition

## Intent

Two interrelated bugs in `process_backup.php` cause backup jobs to silently fail or hang the browser:

1. **Race condition**: Worker CLI bootstrap takes >8s but `FS_BACKUP_QUEUE_RECOVERY_SECONDS = 8`. Recovery triggers before worker sets status to `"running"`, overwriting job state and competing for the lock file.
2. **Synchronous recovery blocks HTTP**: `recover_queued_job()` calls `respond_and_continue()` then `run_backup_job()` synchronously — backup runs 30+ seconds in the same HTTP request, browser times out at ~20s showing "Error de conexion con el servidor".

## Scope

### In Scope
- Increase `FS_BACKUP_QUEUE_RECOVERY_SECONDS` from 8 to 30
- Add PID liveness check via `/proc/$pid` in `should_attempt_queue_recovery()`
- Make `recover_queued_job()` async: re-launch CLI worker instead of synchronous backup
- Log worker output to temp file instead of `/dev/null`

### Out of Scope
- Changes to `process_bootstrap.php` or other process scripts
- Database schema changes
- UI/frontend changes
- Backup logic itself (`backup_manager.php`)

## Capabilities

### New Capabilities
None

### Modified Capabilities
None (no specs exist yet; this is a bug fix to existing behavior)

## Approach

All changes in `process_backup.php`:

| # | Change | How |
|---|--------|-----|
| 1 | Increase recovery threshold | Change constant `FS_BACKUP_QUEUE_RECOVERY_SECONDS` from `8` to `30` |
| 2 | PID liveness check | In `should_attempt_queue_recovery()`, read `pid` from `$data` and check `/proc/$pid` exists; if process is alive, return `false` (don't recover — worker is still booting) |
| 3 | Async recovery | In `recover_queued_job()`, replace synchronous `run_backup_job()` call with `launch_cli_worker()` (re-launch a new CLI worker); the HTTP request returns immediately after `respond_and_continue()` |
| 4 | Worker logging | In `launch_cli_worker()`, change `> /dev/null 2>&1` to `> /tmp/fs_backup_worker_{jobId}.log 2>&1` for debuggability |

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `process_backup.php:13` | Modified | `FS_BACKUP_QUEUE_RECOVERY_SECONDS` constant |
| `process_backup.php:243-259` | Modified | `should_attempt_queue_recovery()` — add PID liveness check |
| `process_backup.php:261-298` | Modified | `recover_queued_job()` — async re-launch instead of sync backup |
| `process_backup.php:359-366` | Modified | `launch_cli_worker()` — log to temp file |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Worker log files accumulate in `/tmp` | Low | Log files use jobId suffix; cleanup action already deletes state. Could add log cleanup to `clear_job_state()` |
| `/proc/$pid` unavailable in containers/chrooted envs | Low | Fall back to time-only check if `/proc` is unreadable; existing `FS_BACKUP_MAX_RECOVERY_ATTEMPTS = 2` still bounds retries |
| Second worker launched during recovery competes with first | Very Low | First worker is dead (PID not alive); lock file prevents true concurrency |

## Rollback Plan

Revert `process_backup.php` to previous version. The constant, function signatures, and flow are self-contained — no schema or external state changes.

## Dependencies

None. Standalone plugin, no external service dependencies.

## Success Criteria

- [ ] Worker with slow bootstrap (>8s) does NOT trigger false recovery
- [ ] `recover_queued_job()` returns HTTP response in <1s (no browser timeout)
- [ ] Worker output is captured in `/tmp/fs_backup_worker_*.log`
- [ ] Dead worker (PID gone) is correctly detected and recovered

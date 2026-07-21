# Agent Notes

## Coding Standards

Follow [CODING_STANDARDS.md](CODING_STANDARDS.md). Keep it concise and non-obvious; do not restate patterns visible in the code or enforced by tooling. Update it when feedback reveals a clear recurring taste or style direction.

## Non-Issues

Record very concise insights about findings the user explicitly declared non-issues. Do not treat these as bugs/action items. Include the date, affected area, finding, user decision.

Format: `YYYY-MM-DD` — **Area:** finding. **Decision:** why it is a non-issue.

2026-07-18 — **Alarm delivery claiming:** selecting and marking deliveries is not atomic across workers. **Decision:** only one non-overlapping alarm dispatch cron runs.
2026-07-21 — **Demo event selection:** `MATA_EVENTS_MOCK` and configurable mock-event paths were removed. **Decision:** demo mode always uses the fixed mock event fixture; no override is needed.

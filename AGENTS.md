# Agent Notes

## Non-Issues

Record very concise insights about findings the user explicitly declared non-issues. Do not treat these as bugs/action items. Include the date, affected area, finding, user decision.

Format: `YYYY-MM-DD` — **Area:** finding. **Decision:** why it is a non-issue.

2026-07-18 — **Alarm delivery claiming:** selecting and marking deliveries is not atomic across workers. **Decision:** only one non-overlapping alarm dispatch cron runs.

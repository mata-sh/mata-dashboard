
# Policy-as-Code

## config evaluatios


These Conftest policies validate the example JSON configurations in `config/example/` so CI fails fast on invalid or unsafe templates.

Local run:

```sh
conftest test config/example/*.json.example -p policy
```


## alarm evaluation

- cooldowns have a short debounce (seconds/minutes) to avoid bursts determined by the intervals.json
- the alarm cooldowns per event (type+level) are determineb by the intervals.json
- opa decides if the event should create an alarm based on alarms.rego

  {
    "input": {
      "event": { "id": 42, "type": "disk_usage", "level": "critical", "threshold": 0.9 },
      "application": { "id": 123456, "code": "app-1", "env": "prod" },
      "server": { "id": 12345, "name": "server-1" },
      "metrics": {
        "counts": {
          "same_type_level_or_higher": { "1h": 1, "4h": 4 }
        },
          "last_event_at": "2024-01-01T11:10:00Z",s
          "last_alarm_at": "2024-01-01T10:00:00Z"
      },
      "channels": ["email", "messenger"]
    }
  }

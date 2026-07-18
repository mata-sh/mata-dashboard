# Policy-as-Code

## Config evaluation

These Conftest policies validate the example JSON configurations in `config/example/` so CI fails fast on invalid or unsafe templates.

Local run:

```sh
make policy-validate
```

The policies live in `config.*` namespaces, so plain conftest without `--all-namespaces` matches nothing.

## Alarm evaluation

PHP applies count and frequency thresholds before OPA. OPA policy input is stateless by design: it classifies one candidate from its type, level, threshold, and environment, then returns allow/channels/priority/reason. It never receives or evaluates event counts or metrics.

```json
{
  "input": {
    "event": {
      "id": 42,
      "type": "disk_usage",
      "level": "critical",
      "threshold": 0.9
    },
    "application": {
      "id": 123456,
      "code": "app-1",
      "env": "prod"
    },
    "server": {
      "id": 12345,
      "name": "server-1"
    },
    "channels": ["email", "messenger"]
  }
}
```

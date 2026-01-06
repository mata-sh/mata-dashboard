# Policy-as-Code

These Conftest policies validate the example JSON configurations in `config/example/` so CI fails fast on invalid or unsafe templates.

Local run:

```sh
conftest test config/example/*.json.example -p policy
```

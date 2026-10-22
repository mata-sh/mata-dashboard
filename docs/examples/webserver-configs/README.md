# Web server examples

Starting points for serving the dashboard natively. They are not tested in
every environment: review and test them before production.

Every configuration must serve only `public/`, route requests that do not match
a file to `index.php`, and deny `config/`, `src/`, `vendor/`, `temp/`, and
`logs/`.

| File | Server | Install as |
| --- | --- | --- |
| `apache-vhost.conf` | Apache, preferred | `/etc/apache2/sites-available/mata.conf`, then `a2ensite mata`; needs `rewrite` and `headers` |
| `.htaccess` | Apache without vhost access, e.g. shared hosting | `public/.htaccess` |
| `nginx-site.conf` | Nginx | `/etc/nginx/sites-available/mata`, linked into `sites-enabled/` |
| `Caddyfile` | Caddy, with automatic HTTPS | `/etc/caddy/Caddyfile` |

Replace the paths and domain names, then reload the server. Docker uses its own
vhost in `docker/apache/000-default.conf`.

## Check the rewrite

```bash
# Should return or redirect to login, not 404
curl -sS -o /dev/null -w '%{http_code} %{redirect_url}\n' http://your-domain/watch

# Static asset should return 200
curl -sS -o /dev/null -w '%{http_code}\n' http://your-domain/assets/css/main.css
```

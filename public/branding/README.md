# Instance Branding

This directory holds the branding images used by **this installation**. It is
gitignored (except this README), so your custom logos survive updates.

On first run the default MATA branding images are copied here from
`public/assets/branding/`. To rebrand:

| File              | Used on              |
| ----------------- | -------------------- |
| `logo.png`        | Login page           |
| `logo_topbar.png` | Top navigation bar   |
| `favicon.svg`     | Browser tab icon     |
| `favicon.png`     | Browser tab icon     |

- **Replace** a file with your own image — keep the filename. `favicon.svg`
  takes precedence over `favicon.png`.
- **Restore a default** by deleting the file and reloading the app
  (it is re-seeded automatically).
- The application name next to the logo is configured separately via
  `APP_NAME` in `config/config.env`.
- **Docker (prod):** the compose files do not mount this directory, so custom
  logos live only in the container and are lost when it is recreated. Add a
  bind mount to the `mata-web` service to keep them:

  ```yaml
  volumes:
    - ./public/branding:/var/www/html/public/branding
  ```

  The dev setup bind-mounts the whole project, so no extra mount is needed there.

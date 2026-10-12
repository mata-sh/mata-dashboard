# Documentation

The documentation site is built with MkDocs and the shadcn theme from Markdown
under `docs/`, configured in `mkdocs.yml`. `site/` is generated and ignored.

## Local preview

Install MkDocs, the theme, and the plugin with pipx, then serve the site at
`http://localhost:3330`:

```bash
pipx install mkdocs
pipx inject mkdocs mkdocs-shadcn mkdocs-llmstxt
make docs-serve
```

Before committing, build the site the way CI does:

```bash
scripts/docs/sync-node-docs.sh
mkdocs build --strict
```

!!! warning "Strict builds"
    CI fails on any MkDocs warning, such as a broken link, a page missing from
    the nav, or a missing snippet file.

## Source layout

```text
docs/
├── index.md
├── architecture/
├── development/
├── guides/
├── reference/
├── examples/
├── images/
├── styles/
├── node/        # generated, see below
├── plans/       # not published
└── drafts/      # not published
```

`docs/plans/` and `docs/drafts/` stay in the repository but are excluded from the
site. Keep published guidance in the canonical pages; do not add duplicate draft
manuals or README files that repeat them.

!!! info "Admonitions"
    The theme only colors `!!! info`, `!!! warning`, and `!!! danger`.

Pages can include repository files with `--8<-- "path/from/repo/root"`, as the
[Docker deployment](../reference/docker.md) reference does with the crontab.
Add an included file to the deploy workflow paths so changes to it republish
the site.

## MATA Node pages

The Node section is copied from mata-node into `docs/node/` (gitignored) by
`scripts/docs/sync-node-docs.sh`. `make docs-serve`, `make docs-deploy`, and CI
run it first. It uses `MATA_NODE_DIR`, else a sibling `../mata-node` checkout,
else a sparse clone of `MATA_NODE_REPO` at `MATA_NODE_REF` (default `main`).

Edit node pages in mata-node. To add one, add it to the script and to the `Node`
nav in `mkdocs.yml`.

## Deployment

`.github/workflows/gh-pages.yml` deploys to GitHub Pages when `docs/**`,
`scripts/docs/**`, `mkdocs.yml`, or `docker/cron/crontab` changes on `main`, or
manually from the Actions tab (Deploy Docs → Run workflow). Node pages refresh on
each deploy, so run the workflow manually to publish mata-node doc changes
sooner. To deploy from a local checkout:

```bash
make docs-deploy
```

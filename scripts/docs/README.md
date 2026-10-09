# Documentation tooling

MATA uses MkDocs with the shadcn theme.

- Source Markdown: `docs/`
- Configuration: `mkdocs.yml`
- Generated output: `site/`, ignored by Git
- Deployment: GitHub Pages through `.github/workflows/gh-pages.yml`

## Local preview

Install MkDocs and the theme with pipx, then run:

```bash
pipx install mkdocs
pipx inject mkdocs mkdocs-shadcn
make docs-serve
```

This serves the site at `http://localhost:3330`. Direct commands are:

```bash
mkdocs serve -a 127.0.0.1:3330
mkdocs build --strict
mkdocs gh-deploy --force
```

## Source layout

Published docs are grouped into:

```text
docs/
├── index.md
├── architecture/
├── development/
├── guides/
├── reference/
├── examples/
├── images/
└── styles/
```

`docs/plans/` contains implementation notes and is excluded from the published
site. Keep published guidance in the canonical pages above; do not add duplicate
draft manuals.

## MATA Node pages

The Node section is copied from mata-node into `docs/node/` (gitignored) by
`sync-node-docs.sh`. `make docs-serve`, `make docs-deploy` and CI run it first. It
uses `MATA_NODE_DIR`, else a sibling `../mata-node` checkout, else a sparse clone
of `MATA_NODE_REPO` at `MATA_NODE_REF` (default `main`). Edit node pages in
mata-node; to add one, add it to the script and the `Node` nav in `mkdocs.yml`.

## Deployment

GitHub Actions deploys when `docs/**`, `scripts/docs/**` or `mkdocs.yml` changes
on `main`, or manually from the Actions tab (Deploy Docs → Run workflow). Node
pages refresh on each deploy, so run the workflow manually to publish mata-node
doc changes sooner. To deploy from a local checkout:

```bash
make docs-deploy
```

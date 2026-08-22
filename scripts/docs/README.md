# Documentation tooling

MATA uses MkDocs with the shadcn theme.

- Source Markdown: `docs/`
- Configuration: `mkdocs.yml`
- Generated output: `site/`, ignored by Git
- Deployment: GitHub Pages through `.github/workflows/gh-pages.yml`
- AI helper: `scripts/docs/ai-update.sh`

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

## AI update helper

`ai-update.sh` reads the staged or unstaged Git diff, asks Claude Code to
identify affected docs, then lets you accept all suggestions or select files.
It needs Claude Code, `jq`, and Git. Review every generated change against the
code and configuration examples before committing it.

## Deployment

GitHub Actions deploys when `docs/**` or `mkdocs.yml` changes on `main`. To
deploy manually:

```bash
make docs-deploy
```

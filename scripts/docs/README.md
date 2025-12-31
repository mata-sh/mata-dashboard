# Documentation Tooling

This directory contains scripts for managing the project's documentation system.

## Documentation System Overview

The project uses **MkDocs** with the **shadcn** theme to generate static documentation from Markdown files.

### Key Components

1. **Source Files**: Markdown files in `docs/` directory
2. **Configuration**: `mkdocs.yml` in project root
3. **Build Output**: `site/` directory (auto-generated, git-ignored)
4. **Deployment**: GitHub Pages via `.github/workflows/gh-pages.yml`
5. **AI Updates**: `ai-update.sh` script for AI-assisted doc updates

### Documentation Workflow

```
docs/*.md  →  [mkdocs build]  →  site/  →  [GitHub Pages]
    ↑
    └── [AI updates via ai-update.sh]
```

## Scripts

### `ai-update.sh`

AI-powered documentation updater using Claude Code CLI. Analyzes code changes and suggests documentation updates.

**Prerequisites:**
- **Claude Code CLI**: Install from https://github.com/anthropics/claude-code
- **jq**: JSON processor (`sudo apt install jq` or `brew install jq`)
- **Git**: For tracking changes

**Usage:**

```bash
# Run from project root
./scripts/docs/ai-update.sh
```

**How it works:**

1. **Change Detection**
   - Checks for staged changes first (`git diff --staged`)
   - Falls back to unstaged changes if nothing is staged
   - Exits if no changes are found

2. **AI Analysis**
   - Uses Claude Code to analyze the diff
   - Identifies which documentation files might be affected
   - Generates a summary of changes

3. **User Review**
   - Shows the analysis
   - Options: Accept (`y`), Cancel (`n`), or Edit (`e`)
   - Edit opens analysis in `$EDITOR` (default: `vi`)

4. **File Selection**
   - Scans all `.md` files in `docs/` directory
   - AI suggests which files need updates
   - Choose between automatic or interactive mode

5. **Documentation Update**
   - For each selected file:
     - AI generates updated content
     - Shows diff of proposed changes
     - Prompts for confirmation before applying

**Example workflow:**

```bash
# Make code changes
vim src/Controllers/DashboardController.php

# Stage the changes
git add src/Controllers/DashboardController.php

# Run AI documentation updater
./scripts/docs/ai-update.sh

# Review analysis and approve updates
```

**Environment variables:**

- `EDITOR`: Text editor for manual editing (default: `vi`)
  ```bash
  export EDITOR=nano
  ./scripts/docs/ai-update.sh
  ```

## MkDocs Commands

The project Makefile provides shortcuts for common MkDocs operations:

```bash
# Serve documentation locally at http://127.0.0.1:8000
make docs-serve

# Deploy to GitHub Pages
make docs-deploy
```

**Direct MkDocs commands:**

```bash
# Serve locally
mkdocs serve -a 127.0.0.1:8000

# Build static site to site/
mkdocs build

# Deploy to GitHub Pages
mkdocs gh-deploy --force
```

## Documentation Structure

The `docs/` directory is organized as follows:

```
docs/
├── index.md                    # Homepage
├── guides/                     # User guides
│   ├── installation.md
│   ├── quickstart.md
│   ├── configuration.md
│   └── ...
├── architecture/               # System architecture
│   ├── overview.md
│   ├── components.md
│   └── ...
├── api/                        # API documentation
│   ├── dashboard-endpoints.md
│   ├── hooks.md
│   └── ...
├── reference/                  # Reference docs
│   ├── server-metrics.md
│   ├── configuration-reference.md
│   └── ...
├── development/                # Developer guides
│   ├── contributing.md
│   ├── testing.md
│   └── ...
└── tutorials/                  # Step-by-step tutorials
    ├── first-server.md
    └── ...
```

## Automatic Deployment

Documentation is automatically deployed to GitHub Pages when:
- Changes are pushed to `main` branch
- Changes affect `docs/**` or `mkdocs.yml`

The workflow (`.github/workflows/gh-pages.yml`) runs:
1. Checks out the repository
2. Sets up Python 3.x
3. Installs MkDocs and theme
4. Deploys to GitHub Pages

## Tips

1. **Preview before pushing**: Use `make docs-serve` to preview changes locally
2. **Stage related changes**: For AI updates, stage only related code changes
3. **Review AI suggestions**: Always review AI-generated content before applying
4. **Check build output**: Ensure MkDocs builds without errors (`mkdocs build`)
5. **Keep structure consistent**: Follow existing patterns in docs organization

## Troubleshooting

### "Claude Code CLI not found"
- Install Claude Code: `npm install -g @anthropic-ai/claude-code`
- Verify: `claude --version`

### "No changes found"
- Ensure you have staged changes: `git add <file>`
- Or make unstaged modifications
- Check: `git status`

### "MkDocs command not found"
- Install MkDocs: `pip install mkdocs`
- Install theme: `pip install mkdocs-shadcn`

### "site/ directory in git status"
- This should be git-ignored
- The `site/` directory is added to `.gitignore`
- Run: `git rm -r --cached site/` if already tracked

### Documentation not deploying
- Check GitHub Actions: https://github.com/xarcdotdev/mata-dashboard/actions
- Verify workflow file: `.github/workflows/gh-pages.yml`
- Ensure GitHub Pages is enabled in repository settings

## Output and Temporary Files

**AI Update Script:**
- Uses temporary directory for intermediate files
- Creates: `changed_docs.md`, `analysis_prompt.txt`, etc.
- Auto-cleaned on script exit

**MkDocs Build:**
- Output: `site/` directory (git-ignored)
- Can be safely deleted and rebuilt anytime

**Colored output:**
- Blue: Informational messages
- Green: Success messages
- Yellow: Warnings and prompts
- Red: Error messages

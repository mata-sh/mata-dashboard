
# Contributing Guide

Thank you for considering contributing to this project!  
This document explains how to set up your environment, run tests, and follow our workflow.

---

## 🚀 Getting Started

1. **Clone the repository**
   ```bash
   git clone https://github.com/<your-username>/<your-repo>.git
   cd <your-repo>
    ```

2. **Set up the development environment**

   ```bash
    make dev-start
    make db-migrate
   ```

   > **Note:** `make dev-start` automatically creates two default users:
   > - Username: `dev` | Password: `password` (admin role)
   > - Username: `admin` | Password: `password` (admin role)
   >
   > You can create additional users with `make users-create` if needed.

---

## ✅ Git Hooks

We provide a **pre-commit hook** in `.githooks/pre-commit`.
It runs code quality checks (PHPStan, PHP Cs-Fixer, etc.) before commits.
If the hook blocks your commit, fix the reported issues and try again.
You can skip with `git commit --no-verify`.

---

## 🔧 Code Style & Quality

* Follow **PSR-12** coding standards.
* Run static analysis and linting locally before pushing:

  ```bash
  composer check
  ```

---

## 📦 Branching & Commits

* Use **feature branches** (`feature/...`), **bugfix branches** (`fix/...`), or **hotfix branches** (`hotfix/...`).

* Write clear, descriptive commit messages:

  ```
  type(scope): short description

  [optional longer description]
  ```

  Example:

  ```
  fix(router): prevent double slashes in generated URLs
  ```

* Types: `feat`, `fix`, `docs`, `refactor`, `test`, `chore`.

---

## 🛠 Release & Changelog

Maintainers can generate a changelog (needs `Claude Code`) when creating a new release tag using:

```bash
./scripts/release/generate-changelog.sh
```

This script builds a commit-based changelog to include in release notes.

---

## 🧪 Tests

  ```bash
  composer test
  ```
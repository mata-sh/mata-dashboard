# Contributing

Thank you for your interest in contributing to MATA Dashboard! This guide will help you get started.

## How to Contribute

Contributions are welcome in many forms:

- Bug reports and feature requests
- Code contributions
- Documentation improvements
- Testing and quality assurance
- Community support

## Getting Started

### 1. Fork the Repository

```bash
# Fork via GitHub web interface, then clone your fork
git clone https://github.com/YOUR_USERNAME/mata-dashboard.git
cd mata-dashboard
```

### 2. Set Up Development Environment

```bash
# Install dependencies
composer install
npm install

# Copy configuration files
cp config/config.env.example config/config.env
cp config/mail.config.env.example config/mail.config.env
cp config/servers.json.example config/servers.json

# Build assets
npm run build-bulma

# Copy HTMX
cp node_modules/htmx.org/dist/htmx.min.js public/assets/js/
```

### 3. Create a Feature Branch

```bash
git checkout -b feature/amazing-feature
```

## Development Workflow

### Code Standards

MATA Dashboard follows PSR-12 coding standards for PHP.

**Check code style:**

```bash
# Run PHP CodeSniffer
vendor/bin/phpcs

# Auto-fix issues
vendor/bin/phpcbf
```

**Run static analysis:**

```bash
# PHPStan (level 5)
vendor/bin/phpstan analyse
```

**Run code quality checks:**

```bash
# PHP Mess Detector
vendor/bin/phpmd src/ text ruleset.xml
```

### Code Quality Requirements

All contributions must:

- Pass PHP CodeSniffer (PSR-12)
- Pass PHPStan level 5 analysis
- Include appropriate documentation
- Follow existing architectural patterns
- Not introduce security vulnerabilities

### Testing Changes

While MATA Dashboard doesn't currently have automated tests, please:

1. **Manual testing:**
   - Test all affected functionality
   - Test on different PHP versions (8.3+)
   - Test with different web servers (Apache, Nginx)
   - Verify no regressions

2. **Security testing:**
   - Check for XSS vulnerabilities
   - Verify SQL injection protection
   - Test authentication and authorization
   - Review file permission handling

## Making Changes

### Code Organization

Follow the existing project structure:

```
src/
├── Controllers/     # HTTP request handlers
├── Services/        # Business logic
├── Models/          # Data access
├── View/            # Template renderers
├── Core/            # Core application services
├── Config/          # Configuration loaders
├── Http/            # HTTP layer (routing, security)
└── Utils/           # Utility classes
```

### Naming Conventions

**Classes:**
- PascalCase (e.g., `ServerDataCollector`)
- Descriptive names indicating purpose

**Methods:**
- camelCase (e.g., `collectServerData`)
- Verb-based names for actions

**Variables:**
- camelCase (e.g., `$serverData`)
- Descriptive names

**Files:**
- Match class name (e.g., `ServerDataCollector.php`)

### Adding New Features

When adding new features:

1. **Check existing patterns** - Follow established architectural patterns
2. **Update configuration** - Add necessary config options with examples
3. **Add documentation** - Document new features in appropriate docs
4. **Update CLAUDE.md** - Add new components to project overview
5. **Consider security** - Review security implications

### Example: Adding a New Service

```php
<?php

namespace IDS\Services;

/**
 * Service description
 */
class MyNewService
{
    /**
     * Constructor with dependency injection
     */
    public function __construct(
        private Database $database,
        private CacheManager $cacheManager
    ) {
    }

    /**
     * Method description
     *
     * @param string $param Parameter description
     * @return array Result description
     */
    public function doSomething(string $param): array
    {
        // Implementation
        return [];
    }
}
```

## Commit Guidelines

### Commit Message Format

```
type: brief description

Detailed explanation of the change (optional)

Resolves #123
```

### Commit Types

- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation changes
- `style`: Code style changes (formatting, no logic change)
- `refactor`: Code refactoring
- `perf`: Performance improvements
- `test`: Adding or updating tests
- `chore`: Maintenance tasks

### Examples

```bash
# Good commit messages
git commit -m "feat: add disk I/O monitoring to server metrics"
git commit -m "fix: prevent XSS in application name display"
git commit -m "docs: update configuration guide with new options"

# Bad commit messages
git commit -m "update stuff"
git commit -m "fixes"
git commit -m "WIP"
```

## Pull Request Process

### 1. Prepare Your Pull Request

Before submitting:

- [ ] Code passes all quality checks (phpcs, phpstan, phpmd)
- [ ] Changes are tested manually
- [ ] Documentation is updated
- [ ] Commit messages follow guidelines
- [ ] No merge conflicts with main branch

### 2. Create Pull Request

```bash
# Push to your fork
git push origin feature/amazing-feature
```

Then create a pull request via GitHub web interface.

### 3. Pull Request Template

```markdown
## Description
Brief description of the changes

## Type of Change
- [ ] Bug fix (non-breaking change fixing an issue)
- [ ] New feature (non-breaking change adding functionality)
- [ ] Breaking change (fix or feature causing existing functionality to change)
- [ ] Documentation update

## Testing
How have you tested these changes?

## Checklist
- [ ] Code follows PSR-12 standards
- [ ] Passes PHPStan analysis
- [ ] Documentation updated
- [ ] No security vulnerabilities introduced
- [ ] Tested on PHP 8.3+

## Screenshots (if applicable)
Add screenshots for UI changes

## Related Issues
Closes #123
```

### 4. Code Review Process

After submitting:

1. **Automated checks** will run (if configured)
2. **Maintainer review** - Address any feedback
3. **Revisions** - Make requested changes
4. **Approval** - PR will be merged once approved

## Bug Reports

### Before Reporting

1. **Search existing issues** - Check if already reported
2. **Verify bug exists** - Reproduce on latest version
3. **Gather information** - Collect relevant details

### Bug Report Template

```markdown
**Describe the bug**
A clear description of what the bug is.

**To Reproduce**
Steps to reproduce:
1. Go to '...'
2. Click on '...'
3. See error

**Expected behavior**
What you expected to happen.

**Screenshots**
Add screenshots if applicable.

**Environment:**
- OS: [e.g., Ubuntu 22.04]
- PHP Version: [e.g., 8.3.1]
- Web Server: [e.g., Apache 2.4]
- Database: [e.g., MySQL 8.0]

**Additional context**
Any other relevant information.
```

## Feature Requests

### Feature Request Template

```markdown
**Is your feature request related to a problem?**
A clear description of the problem.

**Describe the solution you'd like**
A clear description of what you want to happen.

**Describe alternatives you've considered**
Any alternative solutions or features you've considered.

**Additional context**
Add any other context or screenshots about the feature request.
```

## Documentation Contributions

Documentation improvements are highly valued!

### Documentation Structure

```
docs/
├── guides/          # User guides
├── architecture/    # Architecture documentation
├── reference/       # Reference documentation
├── api/            # API documentation
├── development/    # Development guides
└── tutorials/      # Step-by-step tutorials
```

### Writing Documentation

- Use clear, concise language
- Include code examples where appropriate
- Add screenshots for UI features
- Link to related documentation
- Test all commands and examples

### Building Documentation

```bash
# Install mkdocs
pip install mkdocs

# Serve documentation locally
mkdocs serve

# Access at http://localhost:8000
```

## Community Guidelines

### Code of Conduct

- Be respectful and inclusive
- Welcome newcomers
- Give constructive feedback
- Focus on what is best for the community
- Show empathy towards others

### Getting Help

- **GitHub Issues**: For bug reports and feature requests
- **GitHub Discussions**: For questions and general discussion
- **Documentation**: Check docs first

## Development Tips

### Local Development Server

```bash
# PHP built-in server
php -S 0.0.0.0:8001 -t public/

# Watch CSS changes
npm start
```

### Debugging

Enable debug mode in `config/config.env`:

```bash
DEBUG_MODE=true
```

Check logs:

```bash
tail -f temp/logs/application.log
```

### Database Changes

If adding database changes:

1. Update `src/Config/DatabaseSetup.php`
2. Document schema changes
3. Provide migration path for existing installations

### Configuration Changes

If adding new configuration:

1. Add to appropriate config file
2. Create `.example` version
3. Document in configuration guide
4. Update `EnvConfigLoader` or `MonitoringSettingsLoader`

## License

By contributing, you agree that your contributions will be licensed under the GNU Affero General Public License v3.0.

## Questions?

If you have questions about contributing, please open a GitHub Discussion or reach out to the maintainers.

## Recognition

Contributors will be recognized in:

- GitHub contributors list
- Release notes (for significant contributions)
- Project README

Thank you for contributing to MATA Dashboard!

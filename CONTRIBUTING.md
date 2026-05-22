# Contributing to Gift Reporter for MemberPress

Thank you for your interest in contributing to Gift Reporter for MemberPress! This document provides guidelines and instructions for contributing.

## How to Contribute

### Reporting Bugs

Before creating bug reports, please check the issue list as you might find out that you don't need to create one. When you are creating a bug report, please include as many details as possible:

- **Clear and descriptive title**
- **Steps to reproduce the issue**
- **Expected behavior**
- **Actual behavior**
- **Screenshots** (if applicable)
- **Environment details:**
  - WordPress version
  - PHP version
  - MemberPress version
  - MemberPress Gifting add-on version
  - Plugin version
  - Other active plugins (if relevant)

### Suggesting Enhancements

Enhancement suggestions are tracked as GitHub issues. When creating an enhancement suggestion, please include:

- **Clear and descriptive title**
- **Detailed description of the proposed enhancement**
- **Use case**: Why is this enhancement useful?
- **Possible implementation** (if you have ideas)

### Pull Requests

1. **Fork the repository**
2. **Create a feature branch** (`git checkout -b feature/amazing-feature`)
3. **Make your changes**
4. **Follow coding standards** (see below)
5. **Test your changes** thoroughly
6. **Commit your changes** (`git commit -m 'Add some amazing feature'`)
7. **Push to the branch** (`git push origin feature/amazing-feature`)
8. **Open a Pull Request**

## Coding Standards

### PHP Code Standards

This plugin follows WordPress Coding Standards:

- Use WordPress PHP Coding Standards (WPCS)
- Follow PSR-12 for general PHP structure
- Use meaningful variable and function names
- Add PHPDoc comments for all functions and classes
- Ensure all code is properly sanitized and escaped
- Use nonces for all forms and AJAX requests
- Use prepared statements for all database queries

### JavaScript Code Standards

- Use modern ES6+ syntax where possible
- Follow WordPress JavaScript Coding Standards
- Use meaningful variable and function names
- Add JSDoc comments for functions
- Ensure proper error handling

### CSS Code Standards

- Follow WordPress CSS Coding Standards
- Use meaningful class names
- Keep styles organized and commented
- Ensure responsive design

## Development Setup

1. **Clone the repository:**
   ```bash
   git clone https://github.com/omaraelhawary/gift-reporter-for-memberpress.git
   cd memberpress-gift-reporter
   ```

2. **Set up a local WordPress environment** with:
   - WordPress 5.0+
   - PHP 7.4+
   - MemberPress plugin
   - MemberPress Gifting add-on

3. **Install dependencies:**
   ```bash
   npm install
   composer install
   ```

4. **Run code quality checks:**
   ```bash
   npm run lint
   composer run-script phpcs
   ```

5. **Set up and run PHPUnit (first time only):**
   ```bash
   # Requires local MySQL. Adjust user/password/host if needed.
   bash bin/install-wp-tests.sh wordpress_test root root localhost latest
   composer test
   ```

   Subsequent runs only need:
   ```bash
   composer test
   ```

## Testing

Before submitting a pull request, please ensure:

- [ ] All existing tests pass
- [ ] New functionality is tested
- [ ] Code follows WordPress coding standards
- [ ] No PHP errors or warnings
- [ ] No JavaScript console errors
- [ ] Cross-browser compatibility (if UI changes)
- [ ] Mobile responsiveness (if UI changes)

## Commit Messages

Please write clear commit messages:

- Use the present tense ("Add feature" not "Added feature")
- Use the imperative mood ("Move cursor to..." not "Moves cursor to...")
- Limit the first line to 72 characters or less
- Reference issues and pull requests after the first line

Example:
```
Add bulk resend email functionality

Implements bulk action to send reminder emails to multiple
unclaimed gifts at once. Includes progress tracking and
error handling.

Fixes #123
```

## Code Review Process

1. All pull requests require review before merging
2. Maintainers will review code for:
   - Code quality and standards
   - Security considerations
   - Performance implications
   - Test coverage
3. Address any feedback promptly
4. Once approved, a maintainer will merge your PR

## Questions?

If you have questions about contributing, feel free to:

- Open an issue with the `question` label
- Contact the maintainer at omaraelhawary@gmail.com

Thank you for contributing to Gift Reporter for MemberPress! 🎉


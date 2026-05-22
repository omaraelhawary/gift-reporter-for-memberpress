# Security Policy

## Supported Versions

We actively support and provide security updates for the following versions:

| Version | Supported          |
| ------- | ------------------ |
| 1.6.x   | :white_check_mark: |
| < 1.6   | :x:                |

## Reporting a Vulnerability

We take the security of Gift Reporter for MemberPress seriously. If you believe you have found a security vulnerability, please report it to us as described below.

### Please do NOT:

- Open a public GitHub issue for the vulnerability
- Discuss the vulnerability publicly until it has been resolved

### Please DO:

1. **Email us directly** at: omaraelhawary@gmail.com
2. Include the following information:
   - Description of the vulnerability
   - Steps to reproduce the issue
   - Potential impact
   - Suggested fix (if you have one)
   - Your WordPress/PHP/plugin versions

### What to Expect

- **Acknowledgment**: We will acknowledge receipt of your report within 48 hours
- **Initial Assessment**: We will provide an initial assessment within 7 days
- **Updates**: We will keep you informed of our progress
- **Resolution**: We will work to resolve the issue as quickly as possible
- **Credit**: With your permission, we will credit you in the security advisory

### Disclosure Policy

- We will disclose the vulnerability after it has been fixed and a patch is available
- We will coordinate with you on the disclosure timeline
- We will credit you for the discovery (unless you prefer to remain anonymous)

## Security Best Practices

When using this plugin, please follow these security best practices:

1. **Keep WordPress Updated**: Always use the latest version of WordPress
2. **Keep Plugins Updated**: Keep MemberPress and all related plugins updated
3. **Use Strong Passwords**: Ensure all admin accounts use strong, unique passwords
4. **Limit Admin Access**: Only grant admin access to trusted users
5. **Regular Backups**: Maintain regular backups of your site
6. **Security Plugins**: Consider using security plugins for additional protection
7. **HTTPS**: Always use HTTPS for your WordPress site
8. **Review Permissions**: Regularly review user roles and capabilities

## Known Security Considerations

This plugin:

- Requires `manage_options` capability for all admin functions
- Uses WordPress nonces for form and AJAX request verification
- Sanitizes all user inputs
- Uses prepared statements for all database queries
- Escapes all output for display

## Security Updates

Security updates will be released as new versions. Please update the plugin as soon as security updates are available.

## WordPress.org deploy credentials

`SVN_PASSWORD` must **never** be committed to this repository.

- **GitHub Actions:** store only in repository secrets (`SVN_USERNAME`, `SVN_PASSWORD`). GitHub masks these in workflow logs.
- **Local deploy:** use `export SVN_PASSWORD=...` in your shell session only; do not add to `.env` files that might be committed.
- **Documentation:** examples use placeholders such as `your-application-password`, not real values.
- **Release zip:** deploy scripts and workflow files are excluded from `dist/` builds.

If a password is ever committed, revoke the WordPress.org application password immediately and create a new one.

Thank you for helping keep Gift Reporter for MemberPress and its users safe!


# Contributing to WP SIMKU

Thanks for considering a contribution! This project aims to stay **backward compatible** and easy to maintain for WordPress hosting environments.

## Quick start (local)

1. Clone / download the plugin into `wp-content/plugins/wp-simku/`
2. Activate it from WP Admin → Plugins.
3. Make sure **WP_DEBUG** is enabled during development.

## Development guidelines

- Prefer **small, focused PRs** (one fix/feature per PR).
- Keep behavior backward-compatible unless the change is clearly documented.
- Use WordPress security best practices:
  - Sanitize/validate user input
  - Escape output (`esc_html`, `esc_attr`, etc.)
  - Use nonces and capability checks for admin actions / AJAX

## Coding style

- Follow WordPress PHP coding standards where practical.
- Keep functions/classes cohesive and avoid growing single files further.

## Reporting bugs

When reporting a bug, please include:
- WP version
- PHP version
- Plugin version
- Steps to reproduce
- Error logs (if any)

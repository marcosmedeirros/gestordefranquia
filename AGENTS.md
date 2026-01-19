# Repository Guidelines

## Project Structure & Module Organization
- `backend/` contains configuration and shared PHP utilities (`config.php`, `db.php`, `helpers.php`).
- `api/` and `public/api/` expose PHP endpoints used by the UI.
- Root `*.php` files are server-rendered pages; `public/` mirrors a deployable web root.
- `js/`, `css/`, and `img/` hold frontend assets; `uploads/` stores user-uploaded files.
- `sql/` contains schema and database setup (`sql/schema.sql`).

## Build, Test, and Development Commands
There is no build step. Run the app via PHP or a local stack (XAMPP/Apache).

```bash
# Local PHP server (serve repo root)
php -S localhost:8000

# Alternative: serve public/ as web root
php -S localhost:8000 -t public

# Run a migration script
php migrate.php
php migrate-players-columns.php
```

## Coding Style & Naming Conventions
- PHP: 4-space indentation, braces on the next line, short array syntax (`[]`).
- JavaScript: 4-space indentation, arrow functions and `const`/`let` preferred.
- Filenames are lowercase with hyphens for scripts (e.g., `migrate-playoff-system.php`).
- Keep API payloads and response keys in `snake_case` to match backend usage.

## Testing Guidelines
- Automated tests are not present. Validate changes manually.
- Focus on API endpoints (`/api/*.php`) and the affected UI page (`*.php` or `public/*.php`).
- For DB changes, run the relevant `migrate-*.php` script and verify tables.

## Commit & Pull Request Guidelines
- Git history uses short, descriptive commits (e.g., `ajustes`). Prefer concise, specific messages.
- PRs should describe the change, list impacted endpoints/pages, and include screenshots for UI changes.
- Link related issues or database migration scripts when applicable.

## Configuration & Security Notes
- Copy `backend/config.sample.php` to `backend/config.php` and set DB + mail settings.
- Do not commit secrets; keep `backend/config.php` local.
- Ensure `uploads/` remains writable in deployed environments.

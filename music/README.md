# Music Landing (PHP)

Plain-PHP starter to let artists sign up, create song landing pages (smart links), and publish them at `/@username/<slug>`.
Includes auth, page builder, image uploads, and link click tracking—no framework required.

## Requirements
- PHP 8.1+ with PDO and GD enabled
- MySQL 8+ (or MariaDB 10.4+)
- Apache with `AllowOverride All` (for `.htaccess` rewrites)
- Composer (optional, not required for this starter)
- XAMPP is fine

## Quick Start (XAMPP)
1. Create a database (e.g. `music_landing`).
2. Import `sql/schema.sql`.
3. Copy everything in `public/` to `C:\xampp\htdocs\music` (or point a virtual host to `/public`).
4. Update `public/config.php` with your DB credentials and `BASE_URL`.
5. Ensure the `/public/uploads` folder is writable (chmod 775 on Linux; full control on Windows).
6. Visit `http://localhost/music/` → Register → Create your first page.

## Features
- Email+password auth with password hashing
- Create/Edit published landing pages with:
  - Title, artist name, cover image
  - Custom background color or image
  - Unlimited outbound links (Spotify, Apple, YouTube, etc.)
  - Custom slugs
  - Open Graph + Twitter Card meta
- Public pages live at `/@username/<slug>`
- Click tracking via `/r/<link_id>` redirect
- Basic analytics on the dashboard (per-link click counts)

## DEV Notes
- This is intentionally lightweight. Migrate to a framework like Laravel once you outgrow it.
- CSRF tokens are used on POST routes (basic, in-session).

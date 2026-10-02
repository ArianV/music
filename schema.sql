-- ===========================================================================
-- PlugBio – schema (SQLite)
-- Applied automatically by db() in config.php the first time the app connects
-- (it checks PRAGMA user_version). Safe to re-run: everything is IF NOT EXISTS.
-- Timestamps are UTC text 'YYYY-MM-DD HH:MM:SS'; booleans are 0/1.
-- ===========================================================================

CREATE TABLE IF NOT EXISTS users (
  id                        INTEGER PRIMARY KEY,
  email                     TEXT NOT NULL,
  password_hash             TEXT,
  handle                    TEXT,
  display_name              TEXT,
  bio                       TEXT,
  avatar_uri                TEXT,
  socials_json              TEXT NOT NULL DEFAULT '{}',
  profile_public            INTEGER NOT NULL DEFAULT 0,
  created_at                TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS users_email_ci_idx  ON users (lower(email));
CREATE UNIQUE INDEX IF NOT EXISTS users_handle_ci_idx ON users (lower(handle));

CREATE TABLE IF NOT EXISTS pages (
  id           INTEGER PRIMARY KEY,
  user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  title        TEXT NOT NULL,
  artist_name  TEXT,
  cover_uri    TEXT,
  links_json   TEXT NOT NULL DEFAULT '[]',   -- [{"label":"Spotify","url":"..."}, ...]
  slug         TEXT,                          -- public URL: /s/{slug}
  published    INTEGER NOT NULL DEFAULT 0,
  created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS pages_user_id_idx ON pages (user_id);
CREATE INDEX IF NOT EXISTS pages_feed_idx    ON pages (published, updated_at);
CREATE UNIQUE INDEX IF NOT EXISTS pages_slug_ci_idx ON pages (lower(slug));

-- One row per visitor per page per day
CREATE TABLE IF NOT EXISTS page_views (
  id           INTEGER PRIMARY KEY,
  page_id      INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
  user_id      INTEGER,
  session_key  TEXT,
  user_agent   TEXT,
  ref_host     TEXT,
  created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS page_views_page_time_idx ON page_views (page_id, created_at);

-- Outbound link clicks via /go/{page}/{index}
CREATE TABLE IF NOT EXISTS page_clicks (
  id           INTEGER PRIMARY KEY,
  page_id      INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
  link_index   INTEGER NOT NULL,
  url          TEXT NOT NULL,
  session_key  TEXT,
  ref_host     TEXT,
  created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS page_clicks_page_time_idx ON page_clicks (page_id, created_at);

-- Username change log (rate limit: 2 per 14 days)
CREATE TABLE IF NOT EXISTS username_changes (
  id            INTEGER PRIMARY KEY,
  user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  old_username  TEXT,
  new_username  TEXT NOT NULL,
  changed_ip    TEXT,
  changed_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS username_changes_user_time_idx ON username_changes (user_id, changed_at);

-- Failed logins, for throttling
CREATE TABLE IF NOT EXISTS login_attempts (
  id            INTEGER PRIMARY KEY,
  identifier    TEXT NOT NULL,
  attempted_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS login_attempts_id_time_idx ON login_attempts (identifier, attempted_at);

PRAGMA user_version = 1;

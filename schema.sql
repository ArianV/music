-- ===========================================================================
-- Music Landing – minimal schema (PostgreSQL)
-- Safe to run multiple times: uses IF NOT EXISTS and guarded triggers
-- ===========================================================================

BEGIN;

-- ---------- Helpers ---------------------------------------------------------
CREATE OR REPLACE FUNCTION set_updated_at() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  NEW.updated_at := NOW();
  RETURN NEW;
END;
$$;

-- ---------- users -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id             BIGSERIAL PRIMARY KEY,
  email          TEXT NOT NULL,
  password_hash  TEXT,
  name           TEXT,
  username       TEXT,     -- optional; we index it case-insensitively
  handle         TEXT,     -- preferred for @handle routes
  created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Case-insensitive uniqueness for email/handle/username (NULLs allowed)
CREATE UNIQUE INDEX IF NOT EXISTS users_email_ci_idx   ON users (LOWER(email));
CREATE UNIQUE INDEX IF NOT EXISTS users_handle_ci_idx  ON users (LOWER(handle));
CREATE UNIQUE INDEX IF NOT EXISTS users_username_ci_idx ON users (LOWER(username));

-- updated_at trigger
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'set_timestamp_users') THEN
    CREATE TRIGGER set_timestamp_users
      BEFORE UPDATE ON users
      FOR EACH ROW EXECUTE FUNCTION set_updated_at();
  END IF;
END$$;

-- ---------- pages -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS pages (
  id           BIGSERIAL PRIMARY KEY,
  user_id      BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  title        TEXT NOT NULL,
  -- artist: app can use artist_name or artist; we ship artist_name
  artist_name  TEXT,
  -- cover columns: app writes the first one that exists; we provide cover_uri
  cover_uri    TEXT,
  -- links JSON: [{"label":"Spotify","url":"..."}, ...]
  links_json   JSONB NOT NULL DEFAULT '[]'::jsonb,
  slug         TEXT,                                    -- pretty URL
  published    BOOLEAN NOT NULL DEFAULT FALSE,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- For existing DBs, add any missing columns safely
ALTER TABLE pages
  ADD COLUMN IF NOT EXISTS user_id     BIGINT,
  ADD COLUMN IF NOT EXISTS title       TEXT,
  ADD COLUMN IF NOT EXISTS artist_name TEXT,
  ADD COLUMN IF NOT EXISTS cover_uri   TEXT,
  ADD COLUMN IF NOT EXISTS links_json  JSONB NOT NULL DEFAULT '[]'::jsonb,
  ADD COLUMN IF NOT EXISTS slug        TEXT,
  ADD COLUMN IF NOT EXISTS published   BOOLEAN NOT NULL DEFAULT FALSE,
  ADD COLUMN IF NOT EXISTS created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  ADD COLUMN IF NOT EXISTS updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW();

-- Ensure FK if it was missing
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
    WHERE conrelid = 'pages'::regclass AND conname = 'pages_user_id_fkey'
  ) THEN
    ALTER TABLE pages
      ADD CONSTRAINT pages_user_id_fkey
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
  END IF;
END$$;

-- Useful indexes
CREATE INDEX IF NOT EXISTS pages_user_id_idx    ON pages (user_id);
CREATE INDEX IF NOT EXISTS pages_published_idx  ON pages (published);
CREATE INDEX IF NOT EXISTS pages_updated_idx    ON pages (updated_at DESC NULLS LAST);
CREATE INDEX IF NOT EXISTS pages_created_idx    ON pages (created_at DESC NULLS LAST);

-- Slug uniqueness per user (case-insensitive; allows NULL)
CREATE UNIQUE INDEX IF NOT EXISTS pages_user_slug_ci_idx
  ON pages (user_id, LOWER(slug));

-- updated_at trigger
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'set_timestamp_pages') THEN
    CREATE TRIGGER set_timestamp_pages
      BEFORE UPDATE ON pages
      FOR EACH ROW EXECUTE FUNCTION set_updated_at();
  END IF;
END$$;

COMMIT;

-- ===========================================================================
-- Optional: seed an owner to log in with (edit values, then run)
-- INSERT INTO users (email, password_hash, handle, name)
-- VALUES ('you@example.com', crypt('your-password', gen_salt('bf')), 'yourhandle', 'You');
-- (If pgcrypto isn’t available for crypt(), use PHP to create password_hash())
-- ===========================================================================

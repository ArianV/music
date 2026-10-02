<?php
// routes/go.php — /go/{page_id}/{link_index}: count the click, then send the visitor on.
// Only ever redirects to a URL stored on the page, so it can't be used as an open redirect.
require_once __DIR__ . '/../config.php';

$page_id = (int)($GLOBALS['page_id'] ?? 0);
$index   = (int)($GLOBALS['link_index'] ?? -1);

$st = db()->prepare('SELECT id, user_id, published, links_json FROM pages WHERE id = :id');
$st->execute([':id' => $page_id]);
$page = $st->fetch();

$is_owner = $page && (int)$page['user_id'] === (int)(current_user()['id'] ?? 0);
if (!$page || (!$page['published'] && !$is_owner)) not_found();

$url = page_links($page)[$index]['url'] ?? null;
if (!$url) not_found('That link no longer exists');

if (!$is_owner && !is_bot_ua($_SERVER['HTTP_USER_AGENT'] ?? '')) {
  try {
    db()->prepare('INSERT INTO page_clicks (page_id, link_index, url, session_key, ref_host)
                   VALUES (:pid, :i, :url, :sk, :rh)')
        ->execute([
          ':pid' => $page_id,
          ':i'   => $index,
          ':url' => $url,
          ':sk'  => session_key(),
          ':rh'  => external_referrer_host(),
        ]);
  } catch (Throwable $e) {
    error_log('[clicks] insert failed: ' . $e->getMessage());
  }
}

header('Cache-Control: no-store');
header('Location: ' . $url, true, 302);
exit;

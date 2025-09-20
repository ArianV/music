<?php
// Do NOT require config again here; config.php already required before route_dispatch includes this file.
// Just enforce auth:
require_auth();

$u = current_user();            // logged-in user array
$errors = [];
$saved  = false;
$flash  = null;

csrf_check(); // keep your CSRF check consistent with the rest of the app

// --- Username change handling (max 2 per 14 days) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'change_username')) {
  $new_username = trim($_POST['username'] ?? '');

  // Basic validations
  if ($new_username === '') {
    $errors[] = 'Username cannot be empty.';
  } elseif (!preg_match('/^[a-z0-9_\.]{3,20}$/i', $new_username)) {
    $errors[] = 'Username must be 3–20 chars: letters, numbers, underscore, dot.';
  } elseif (strcasecmp((string)($u['username'] ?? ''), $new_username) === 0) {
    $errors[] = 'That is already your username.';
  }

  // Rate limit: max 2 changes in the last 14 days
  if (!$errors) {
    $gate = can_change_username((int)$u['id']);  // provided in config.php earlier
    if (!$gate['allowed']) {
      $when = $gate['next_at'] ? date('Y-m-d H:i', strtotime($gate['next_at'])) : 'later';
      $errors[] = "You’ve reached the limit (2 changes per 14 days). Try again after $when.";
    }
  }

  if (!$errors) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
      // Ensure uniqueness (case-insensitive)
      $chk = $pdo->prepare("SELECT 1 FROM users WHERE lower(username) = lower(:u) AND id <> :id");
      $chk->execute([':u' => $new_username, ':id' => $u['id']]);
      if ($chk->fetchColumn()) {
        throw new RuntimeException('That username is taken.');
      }

      $old = $u['username'] ?? null;

      // Apply update
      $upd = $pdo->prepare("UPDATE users SET username = :u WHERE id = :id");
      $upd->execute([':u' => $new_username, ':id' => $u['id']]);

      // Log change
      record_username_change((int)$u['id'], $old, $new_username);

      $pdo->commit();
      $saved = true;
      $flash = 'Username updated.';

      // refresh user for display
      $u = user_by_id((int)$u['id']);
    } catch (\Throwable $e) {
      $pdo->rollBack();
      if (str_contains(strtolower($e->getMessage()), 'duplicate') || str_contains($e->getMessage(), 'taken')) {
        $errors[] = 'That username is taken.';
      } else {
        error_log('[account_settings username] ' . $e->getMessage());
        $errors[] = 'Could not update username. Please try again.';
      }
    }
  }
}

ob_start();
?>

<h2>Account Settings</h2>

<!-- Username change -->
<div class="card">
  <h3>Change username</h3>

  <?php if (!empty($errors)): ?>
    <div class="alert error">
      <ul><?php foreach ($errors as $e) echo '<li>'.e($e).'</li>'; ?></ul>
    </div>
  <?php endif; ?>

  <?php if ($saved && $flash): ?>
    <div class="alert success"><?= e($flash) ?></div>
  <?php endif; ?>

  <form method="post" action="/account/settings">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="change_username">
    <div class="row">
      <label for="username">New username</label>
      <input id="username" name="username" value="<?= e(($u['username'] ?? '') ) ?>" maxlength="20" required>
    </div>
    <div class="row">
      <button class="btn" type="submit">Update username</button>
    </div>
  </form>

  <?php
    // Show remaining changes or cooldown
    $info = can_change_username((int)$u['id']);
    $pdo  = db();
    $c = $pdo->prepare("SELECT count(*) FROM username_changes WHERE user_id=:uid AND changed_at >= now() - INTERVAL '14 days'");
    $c->execute([':uid' => $u['id']]);
    $used = (int)$c->fetchColumn();
    $left = max(0, 2 - $used);

    if ($info['allowed']) {
      echo '<p class="muted">You can change your username ' . $left . ' more time(s) in the next 14 days.</p>';
    } else {
      echo '<p class="muted">Limit reached. Try again after ' . e(date('Y-m-d H:i', strtotime($info['next_at']))) . '.</p>';
    }
  ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../layout.php';

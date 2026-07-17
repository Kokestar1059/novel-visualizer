<?php
// ============================================================
// logout.php — ログアウト処理
//   出典: idea.md §7.1（4）
//   $_SESSION=[] → Cookie削除 → session_destroy() → login.php へ
// ============================================================
session_start();

// セッション変数を全消去
$_SESSION = [];

// セッションCookieも削除（使っている場合）
if (ini_get('session.use_cookies')) {
  $params = session_get_cookie_params();
  setcookie(
    session_name(),
    '',
    time() - 42000,
    $params['path'],
    $params['domain'],
    $params['secure'],
    $params['httponly']
  );
}

// セッション破棄
session_destroy();

header('Location: login.php');
exit;

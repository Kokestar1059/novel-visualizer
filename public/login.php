<?php
// ============================================================
// login.php — ログインフォーム（画面）
//   出典: idea.md §7.1
//   action="login_act.php" / method="post" で login_id / login_pw を送る。
//   既にログイン済みなら index.php へ飛ばす。
// ============================================================
session_start();

// 既にログイン済みならトップへ
if (isset($_SESSION['chk_ssid']) && $_SESSION['chk_ssid'] == session_id()) {
  header('Location: index.php');
  exit;
}

require_once __DIR__ . '/functions.php';

// login_act.php から ?err=1 で戻されたらエラー表示
$hasError = isset($_GET['err']);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ログイン — novel-visualizer</title>
  <style>
    body { font-family: system-ui, sans-serif; background: #f4f5f7; margin: 0; }
    .box { max-width: 360px; margin: 10vh auto; background: #fff; padding: 2rem;
           border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
    h1 { font-size: 1.2rem; margin: 0 0 1.2rem; }
    label { display: block; font-size: .85rem; margin: .8rem 0 .3rem; color: #444; }
    input[type=text], input[type=password] { width: 100%; padding: .5rem;
           border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
    button { width: 100%; margin-top: 1.4rem; padding: .6rem; border: 0;
             border-radius: 4px; background: #2b6cb0; color: #fff; font-size: 1rem;
             cursor: pointer; }
    .err { color: #c53030; font-size: .85rem; margin-top: .8rem; }
  </style>
</head>
<body>
  <div class="box">
    <h1>novel-visualizer ログイン</h1>
    <form action="login_act.php" method="post">
      <label for="login_id">ログインID</label>
      <input type="text" id="login_id" name="login_id" autocomplete="username" required autofocus>

      <label for="login_pw">パスワード</label>
      <input type="password" id="login_pw" name="login_pw" autocomplete="current-password" required>

      <button type="submit">ログイン</button>
    </form>
    <?php if ($hasError): ?>
      <p class="err">ログインIDまたはパスワードが正しくありません。</p>
    <?php endif; ?>
  </div>
</body>
</html>

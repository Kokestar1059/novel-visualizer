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
  <title>ログイン — こころ、図解</title>
  <style>
    :root {
      --paper: #f4f1ea; --surface: #fffefb; --ink: #232026; --ink-soft: #5f5a54;
      --ink-faint: #948d82; --line: #e5ded1; --indigo: #2f3d5c; --indigo-600: #3b4d72;
      --vermilion: #b24a34;
      --mincho: "Hiragino Mincho ProN","Yu Mincho","YuMincho",serif;
      --sans: "Hiragino Sans","Yu Gothic","YuGothic","Noto Sans JP",system-ui,-apple-system,sans-serif;
    }
    body {
      font-family: var(--sans); color: var(--ink); margin: 0; min-height: 100vh;
      background:
        radial-gradient(circle at 20% 12%, rgba(255,255,255,.6), transparent 42%),
        var(--paper);
      display: flex; align-items: center; justify-content: center;
    }
    .box {
      width: 360px; max-width: calc(100vw - 2.4rem);
      background: var(--surface); padding: 2.2rem 2rem 2.4rem;
      border: 1px solid var(--line); border-radius: 14px;
      box-shadow: 0 18px 50px -20px rgba(35,32,38,.28), 0 1px 2px rgba(35,32,38,.05);
    }
    .brand { display: flex; align-items: center; gap: .7rem; margin-bottom: 1.6rem; }
    .brand-mark { width: 4px; height: 2.4rem; border-radius: 2px; flex: 0 0 auto;
                  background: linear-gradient(180deg, var(--vermilion), var(--indigo)); }
    .brand h1 { font-family: var(--mincho); font-size: 1.5rem; margin: 0; letter-spacing: .14em; font-weight: 600; }
    .brand p { margin: .2rem 0 0; font-size: .72rem; color: var(--ink-faint); letter-spacing: .05em; }
    label { display: block; font-size: .82rem; margin: .95rem 0 .35rem; color: var(--ink-soft); letter-spacing: .02em; }
    input[type=text], input[type=password] {
      width: 100%; padding: .6rem .7rem; font-family: var(--sans); font-size: .95rem; color: var(--ink);
      background: var(--surface); border: 1px solid var(--line); border-radius: 8px; box-sizing: border-box;
      transition: border-color .15s, box-shadow .15s;
    }
    input:focus { outline: none; border-color: var(--indigo-600); box-shadow: 0 0 0 3px rgba(47,61,92,.14); }
    button {
      width: 100%; margin-top: 1.6rem; padding: .7rem; border: 0; border-radius: 8px;
      background: var(--indigo); color: #fbfaf7; font-family: var(--sans); font-size: 1rem; letter-spacing: .06em;
      cursor: pointer; box-shadow: 0 4px 14px -5px rgba(47,61,92,.55); transition: background .15s, transform .06s;
    }
    button:hover { background: var(--indigo-600); }
    button:active { transform: translateY(1px); }
    .err { color: var(--vermilion); font-size: .84rem; margin-top: .9rem; }
  </style>
</head>
<body>
  <div class="box">
    <div class="brand">
      <span class="brand-mark" aria-hidden="true"></span>
      <div>
        <h1>こころ、図解</h1>
        <p>テキストマイニングで読む夏目漱石『こころ』</p>
      </div>
    </div>
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

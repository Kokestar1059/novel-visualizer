<?php
// ============================================================
// index.php — グラフ全体表示のトップページ（要ログイン）
//   ★#3では暫定。冒頭で loginCheck() を呼び「未ログインで弾かれること」を担保する。
//     Cytoscape.js によるグラフ描画は #5 で実装する。
//   出典: idea.md §7.2（各ページ冒頭での使い方）
// ============================================================
session_start();
require_once __DIR__ . '/functions.php';
loginCheck();   // 未ログインなら 'LOGIN ERROR' で停止
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>novel-visualizer</title>
  <style>
    body { font-family: system-ui, sans-serif; margin: 0; color: #222; }
    header { display: flex; justify-content: space-between; align-items: center;
             padding: .8rem 1.2rem; background: #2b6cb0; color: #fff; }
    header a { color: #fff; font-size: .9rem; }
    main { padding: 1.6rem; }
    .note { color: #666; font-size: .9rem; }
  </style>
</head>
<body>
  <header>
    <strong>novel-visualizer</strong>
    <span>
      <?php echo h($_SESSION['name'] ?? ''); ?> さん
      <a href="logout.php">ログアウト</a>
    </span>
  </header>
  <main>
    <h1>グラフ表示</h1>
    <p class="note">ネットワーク図（Cytoscape.js）は #5 で実装予定です。<br>
       このページは要ログイン。未ログインでは表示されません。</p>
  </main>
</body>
</html>

<?php
// ============================================================
// index.php — グラフ全体表示のトップページ（要ログイン）
//   冒頭で loginCheck() を呼び「未ログインで弾かれること」を担保する。
//   ネットワーク図は Cytoscape.js（CDN・3.34.0 安定版）で描画（Issue #5）。
//   ・グラフデータは assets/graph.js が graph_data.php を fetch して取得
//   ・凡例で「実線＝統計的抽出／破線＝AI解釈」を明示（ADR-004・idea.md §9）
//   出典: idea.md §7.2（各ページ冒頭での使い方）・§9
// ============================================================
session_start();
require_once __DIR__ . '/functions.php';

// 未ログインは login.php へ誘導する（画面ページなのでJSON 401ではなくリダイレクト。Issue #5）。
// ・login.php と対になる挙動（あちらはログイン済みを index へ飛ばす）。
// ・JS無効でも確実に誘導できるようサーバー側で行う。
// ・ログイン済みなら loginCheck() で従来通りセッションIDを再生成する（#3の契約は不変）。
if (!isset($_SESSION['chk_ssid']) || $_SESSION['chk_ssid'] != session_id()) {
  header('Location: login.php');
  exit;
}
loginCheck();   // ログイン済みパス：セッションID再生成（ハイジャック対策）
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>novel-visualizer</title>
  <style>
    html, body { height: 100%; }
    body { font-family: system-ui, sans-serif; margin: 0; color: #222; }
    header { display: flex; justify-content: space-between; align-items: center;
             padding: .8rem 1.2rem; background: #2b6cb0; color: #fff; box-sizing: border-box; }
    header a { color: #fff; font-size: .9rem; }
    /* グラフ領域と凡例は position: absolute で重ねるため、基準となる相対配置 */
    .graph-wrap { position: relative; }
  </style>
  <link rel="stylesheet" href="assets/graph.css">
</head>
<body>
  <header>
    <strong>novel-visualizer</strong>
    <span>
      <?php echo h($_SESSION['name'] ?? ''); ?> さん
      <a href="logout.php">ログアウト</a>
    </span>
  </header>

  <div class="graph-wrap">
    <!-- Cytoscape.js の描画先 -->
    <div id="cy"></div>

    <!-- 読込中・データなし・エラー時のメッセージ（graph.js が制御） -->
    <div id="graph-message" class="graph-message">読み込み中…</div>

    <!-- ノード詳細サイドパネル（Issue #6）: ノードクリックで node_detail.php を fetch して表示。
         初期は非表示（hidden）。中身は graph.js が textContent で組み立てる（XSS対策）。 -->
    <aside id="node-panel" class="node-panel" hidden aria-label="ノード詳細">
      <div class="node-panel-head">
        <h2 id="node-panel-title">ノード詳細</h2>
        <button type="button" id="node-panel-close" class="node-panel-close" aria-label="閉じる">×</button>
      </div>
      <div id="node-panel-body" class="node-panel-body"></div>
    </aside>

    <!-- 凡例（レジェンド）: Provenance の視覚的分離を明示（ADR-004・idea.md §9） -->
    <div class="legend" aria-label="凡例">
      <h2>凡例</h2>
      <div class="legend-row">
        <span class="legend-line primary"></span>
        <span>実線＝統計的抽出（一次データ・事実）</span>
      </div>
      <div class="legend-row pending">
        <span class="legend-line secondary"></span>
        <span>破線＝AI解釈（二次データ・今後 #8 で追加）</span>
      </div>
      <h2 style="margin-top:.7rem;">ノード種別</h2>
      <div class="legend-row"><span class="legend-dot person"></span><span>人物（person）</span></div>
      <div class="legend-row"><span class="legend-dot place"></span><span>場所（place）</span></div>
    </div>
  </div>

  <!-- Cytoscape.js 3.34.0（安定版・CDN固定バージョン。CLAUDE.md §10） -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/cytoscape/3.34.0/cytoscape.min.js"></script>
  <script src="assets/graph.js"></script>
</body>
</html>

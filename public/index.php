<?php
// ============================================================
// index.php — グラフ全体表示のトップページ（要ログイン）
//   冒頭で loginCheck() を呼び「未ログインで弾かれること」を担保する。
//   ネットワーク図は Cytoscape.js（CDN・3.34.0 安定版）で描画（Issue #5）。
//   ・グラフデータは assets/graph.js が graph_data.php を fetch して取得
//   ・凡例で「実線＝共起（一次データ）」とノード種別を明示（テーマ分類=#8はv1で無効化）
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
  <title>こころ、図解 — テキストマイニングで読む夏目漱石</title>
  <style>
    /* ヘッダーとページ地の基本トーン（詳細スタイルは assets/graph.css の :root トークンと一致） */
    html, body { height: 100%; }
    body {
      margin: 0;
      font-family: "Hiragino Sans","Yu Gothic","YuGothic","Noto Sans JP",system-ui,-apple-system,sans-serif;
      color: #232026;
      background: #f4f1ea;
    }
    .app-header {
      height: 4.4rem;
      box-sizing: border-box;
      display: flex; justify-content: space-between; align-items: center;
      padding: 0 1.4rem;
      background: #fffefb;
      border-bottom: 1px solid #e5ded1;
    }
    .app-title { display: flex; align-items: center; gap: .75rem; min-width: 0; }
    /* 藍→朱の細い罫（明治文学＋モダンの差し色） */
    .app-mark { width: 4px; height: 2.15rem; border-radius: 2px; flex: 0 0 auto;
                background: linear-gradient(180deg, #b24a34, #2f3d5c); }
    .app-title h1 {
      font-family: "Hiragino Mincho ProN","Yu Mincho","YuMincho",serif;
      font-size: 1.42rem; margin: 0; color: #232026;
      letter-spacing: .14em; font-weight: 600; line-height: 1.15;
    }
    .app-sub { margin: .16rem 0 0; font-size: .72rem; color: #948d82; letter-spacing: .05em; }
    .app-user { display: flex; align-items: center; gap: .95rem; font-size: .82rem; color: #5f5a54; white-space: nowrap; }
    .app-user a { color: #2f3d5c; text-decoration: none; border-bottom: 1px solid rgba(47,61,92,.28); padding-bottom: 1px; transition: color .15s, border-color .15s; }
    .app-user a:hover { color: #b24a34; border-color: rgba(178,74,52,.45); }
    /* グラフ領域と凡例は position: absolute で重ねるため、基準となる相対配置 */
    .graph-wrap { position: relative; }
  </style>
  <link rel="stylesheet" href="assets/graph.css">
</head>
<body>
  <header class="app-header">
    <div class="app-title">
      <span class="app-mark" aria-hidden="true"></span>
      <div>
        <h1>こころ、図解</h1>
        <p class="app-sub">テキストマイニングで読む夏目漱石『こころ』</p>
      </div>
    </div>
    <div class="app-user">
      <span><?php echo h($_SESSION['name'] ?? ''); ?> さん</span>
      <a href="logout.php">ログアウト</a>
    </div>
  </header>

  <div class="graph-wrap">
    <!-- 自然言語フィルタ（Issue #7）:
         入力 → query_llm.php（AIがクエリJSONに翻訳）→ execute_query.php（DB実行）→ グラフ再描画。
         AIは「翻訳」のみ。本体データ（事実）は生成・変更しない（ADR-002/§8）。 -->
    <form id="query-bar" class="query-bar" autocomplete="off">
      <input type="text" id="query-text" class="query-text"
             maxlength="200"
             placeholder="例: 恋慕の関係だけ見せて／太郎を中心に2ホップ（自然言語で絞り込み）"
             aria-label="自然言語でグラフを絞り込む">
      <button type="submit" id="query-submit" class="query-btn primary">絞り込む</button>
      <button type="button" id="query-reset" class="query-btn">全体表示に戻す</button>
      <span id="query-status" class="query-status" role="status" aria-live="polite"></span>
    </form>

    <!-- テーマ別グルーピング（Issue #8）はv1では無効化（精度・筋が悪いため）。
         自然言語フィルタ（絞り込み）に一本化。二次データ/Provenance分離（ADR-004）の概念は
         将来のAI関係抽出で再利用予定。バックエンド(groupings_*.php)・テーブルは提出後に整理。 -->

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
        <span>実線＝共起（同じ文に現れた関連・一次データ）</span>
      </div>
      <h2 style="margin-top:.7rem;">ノード種別</h2>
      <div class="legend-row"><span class="legend-dot narrator"></span><span>語り手「私」（全体と共起する観察者）</span></div>
      <div class="legend-row"><span class="legend-dot person"></span><span>人物（person）</span></div>
      <div class="legend-row"><span class="legend-dot place"></span><span>場所（place）</span></div>
      <div class="legend-row"><span class="legend-dot term"></span><span>アクタント（物・出来事・主題）</span></div>
    </div>
  </div>

  <!-- Cytoscape.js 3.34.0（安定版・CDN固定バージョン。CLAUDE.md §10） -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/cytoscape/3.34.0/cytoscape.min.js"></script>
  <script src="assets/graph.js"></script>
</body>
</html>

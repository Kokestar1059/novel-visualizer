<?php
// ============================================================
// functions.php — 世界Bの共通関数
//   出典: idea.md §7.2（ログイン認証）
//   ★ログイン必須ページ／APIの冒頭で loginCheck() を呼ぶ
//   ★このファイル自体はセッションを開始しない。呼び出し側で session_start() 済みが前提
// ============================================================

// ------------------------------------------------------------
// loginCheck — ログインチェック＋セッションID更新（ハイジャック対策込み）
//   idea.md §7.2 のパターンを踏襲。
//   通過ごとに session_regenerate_id(true) で鍵を更新する。
//   ★複数APIを初期描画で同時に叩く画面（#4/#6）を作る際は、この更新頻度を
//     「ログイン時のみ」に緩めるか要検討（idea.md §7.3）。#3では毎回更新のまま。
//   使い方（ページ冒頭）:
//     session_start();
//     require_once __DIR__ . '/functions.php';
//     loginCheck();
// ------------------------------------------------------------
function loginCheck(): void {
  if (!isset($_SESSION['chk_ssid']) || $_SESSION['chk_ssid'] != session_id()) {
    exit('LOGIN ERROR');
  } else {
    session_regenerate_id(true);            // チェック通過ごとに鍵を新しくする
    $_SESSION['chk_ssid'] = session_id();
  }
}

// ------------------------------------------------------------
// loginCheckApi — API（JSONを返すファイル）向けのログインチェック
//   idea.md §7.3: プレーンテキストの 'LOGIN ERROR' ではなく、
//   401 + JSON（{"error":"unauthorized"}）を返す。フロントが検知して
//   ログイン画面へ誘導できるようにするため。
//   ★graph_data.php / node_detail.php 等（#4/#6）で使う想定。
//
//   ★セッションID更新について（#4で確定・idea.md §7.3）:
//     APIでは session_regenerate_id() を「呼ばない」。検証のみ行う。
//     理由: #5/#6 でフロントが graph_data と node_detail を並行で叩くと、
//     毎回更新では「片方が古いセッションIDのまま届いて401」の競合が起きうる。
//     鍵の更新は全画面遷移（loginCheck）とログイン時（login_act）に集約する。
// ------------------------------------------------------------
function loginCheckApi(): void {
  if (!isset($_SESSION['chk_ssid']) || $_SESSION['chk_ssid'] != session_id()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  // 通過時は何もしない（セッションIDは更新しない。上記コメント参照）
}

// ------------------------------------------------------------
// h — HTML出力用のエスケープ（XSS対策の短縮ヘルパー）
// ------------------------------------------------------------
function h(?string $s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

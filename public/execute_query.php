<?php
// ============================================================
// execute_query.php — クエリJSONを受け取り DB に対して実行し、結果を返すAPI
//   出典: idea.md §8.4・§9 / Issue #7・§11 ステップ7
//
//   仕様（CLAUDE.md §5/§6）:
//     - 要ログイン。未認証は 401 + {"error":"unauthorized"}（loginCheckApi）
//     - POST 限定（GETでは実行しない）
//     - リクエストボディ = クエリJSON（{"action":"filter_edges","params":{...}}）
//     - 実際の解釈・実行は lib/QueryBuilder.php に集約（ホワイトリスト・prepared statement・ADR-005）
//     - ★query_llm.php を信用しない：直接叩かれても壊れないよう、ここでも入力を独立検証する。
//       未知 action・不正 params は QueryBuilder が例外を投げ、ここで 400 にする。
//     - 一次データ（nodes/edges）のみ返す。二次データ（llm_groupings）は混ぜない（ADR-004）
//     - レスポンスは graph_data.php と同一形式（フロントは同じ描画関数で再描画できる）
//
//   レスポンス例:
//     { "work_id": 1,
//       "elements": { "nodes": [...], "edges": [...] } }   // graph_data.php と同じ
// ============================================================

// weight（DECIMAL由来のfloat）を冗長な精度で出さない（graph_data.php と揃える）。
ini_set('serialize_precision', '-1');

session_start();
require_once __DIR__ . '/functions.php';

// --- 1. 認証（未認証は 401 JSON で停止） ---
loginCheckApi();

// これ以降のレスポンスはすべてJSON
header('Content-Type: application/json; charset=utf-8');

// --- 2. POST 限定 ---
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  header('Allow: POST');
  echo json_encode(['error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
  exit;
}

require_once __DIR__ . '/../lib/QueryBuilder.php';

try {
  // --- 3. リクエストボディ（クエリJSON）を安全にパース ---
  //   上限を設けて巨大ボディを弾く（濫用対策）。純粋なJSONオブジェクトのみ受け付ける。
  $raw = file_get_contents('php://input', false, null, 0, 64 * 1024);   // 最大64KB
  if ($raw === false || $raw === '') {
    http_response_code(400);
    echo json_encode(['error' => 'empty_body'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $query = json_decode($raw, true);
  if (!is_array($query) || !isset($query['action'])) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_query'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // --- 4. QueryBuilder に委譲して実行（ホワイトリスト・prepared statement） ---
  $pdo = require __DIR__ . '/../config/db.php';
  $qb  = new QueryBuilder($pdo);
  $result = $qb->run($query);   // 未知action・不正paramsは InvalidArgumentException

  echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $ex) {
  // AI/クライアント由来の不正なクエリ（未知action・不正params）。実行はしていない。
  //   ★詳細メッセージは返すが、DB内部情報は含まない安全な文言のみ（QueryBuilderが管理）。
  http_response_code(400);
  echo json_encode(['error' => 'invalid_query', 'detail' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
} catch (Throwable $ex) {
  // 予期せぬ失敗（DB接続エラー等）。詳細は返さず500 JSONのみ。
  http_response_code(500);
  echo json_encode(['error' => 'internal_error'], JSON_UNESCAPED_UNICODE);
  exit;
}

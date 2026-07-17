<?php
// ============================================================
// graph_data.php — 指定作品のノード・エッジを Cytoscape.js 形式のJSONで返すAPI
//   出典: idea.md §7.3・§9・§11 ステップ4
//
//   仕様（CLAUDE.md §5/§6）:
//     - 要ログイン。未認証は 401 + {"error":"unauthorized"}（loginCheckApi）
//     - work_id は ?work_id=N で指定。省略時は最新1件を自動選択（#4で確定）
//     - 一次データ（nodes/edges）のみ返す。二次データ（llm_groupings）は混ぜない（ADR-004）
//     - DBアクセスは lib/GraphRepository.php に集約（prepared statements）
//     - config/lib は __DIR__ 基準で require（symlink越しでも解決。CLAUDE.md §9）
//
//   レスポンス例:
//     { "work_id": 1,
//       "elements": {
//         "nodes": [{"data":{"id":"1","label":"太郎","node_type":"person","frequency":10}}],
//         "edges": [{"data":{"id":"e5","source":"2","target":"3",
//                            "edge_type":"共起","weight":0.5,"method":"co_occurrence"}}]
//       } }
// ============================================================

// weight（DECIMAL由来のfloat）を json_encode で冗長な精度で出さないため、
// 最短で往復可能な表現に固定する（PHP既定と同じ -1。php.iniで上書きされている環境対策）。
ini_set('serialize_precision', '-1');

session_start();
require_once __DIR__ . '/functions.php';

// --- 1. 認証（未認証は 401 JSON で停止） ---
loginCheckApi();

// これ以降のレスポンスはすべてJSON
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/GraphRepository.php';

try {
  /** @var PDO $pdo */
  $pdo  = require __DIR__ . '/../config/db.php';
  $repo = new GraphRepository($pdo);

  // --- 2. work_id の解決（GET優先、無ければ最新1件） ---
  $workId = null;
  if (isset($_GET['work_id']) && $_GET['work_id'] !== '') {
    // 数値以外・0以下は不正。フィルタで弾く
    $workId = filter_var($_GET['work_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($workId === false) {
      http_response_code(400);
      echo json_encode(['error' => 'invalid work_id'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  } else {
    $workId = $repo->latestWorkId();
  }

  // --- 3. 作品が1件も無い場合は空グラフを返す（200） ---
  if ($workId === null) {
    echo json_encode(
      ['work_id' => null, 'elements' => ['nodes' => [], 'edges' => []]],
      JSON_UNESCAPED_UNICODE
    );
    exit;
  }

  // --- 4. 一次データ（nodes/edges）を取得 ---
  $nodes = $repo->findNodes($workId);
  $edges = $repo->findEdges($workId);

  // --- 5. Cytoscape.js 形式へ整形 ---
  //   ノードid・エッジのsource/targetは文字列にする（Cytoscapeの要件）。
  //   エッジidはノードidとの衝突回避で先頭に "e" を付ける。
  $cyNodes = [];
  foreach ($nodes as $n) {
    $cyNodes[] = ['data' => [
      'id'        => (string)$n['id'],
      'label'     => $n['label'],
      'node_type' => $n['node_type'],
      'frequency' => (int)$n['frequency'],
    ]];
  }

  $cyEdges = [];
  foreach ($edges as $e) {
    $cyEdges[] = ['data' => [
      'id'        => 'e' . $e['id'],
      'source'    => (string)$e['source_node_id'],
      'target'    => (string)$e['target_node_id'],
      'edge_type' => $e['edge_type'],
      'weight'    => (float)$e['weight'],
      'method'    => $e['method'],
    ]];
  }

  echo json_encode(
    ['work_id' => $workId, 'elements' => ['nodes' => $cyNodes, 'edges' => $cyEdges]],
    JSON_UNESCAPED_UNICODE
  );
} catch (Throwable $ex) {
  // 予期せぬ失敗（DB接続エラー等）。詳細は返さず500 JSONのみ。
  http_response_code(500);
  echo json_encode(['error' => 'internal_error'], JSON_UNESCAPED_UNICODE);
  exit;
}

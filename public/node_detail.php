<?php
// ============================================================
// node_detail.php — 指定ノードの隣接ノード＋エビデンス原文をJSONで返すAPI
//   出典: idea.md §5・§9・§11 ステップ6 / Issue #6
//
//   仕様（CLAUDE.md §5/§6）:
//     - 要ログイン。未認証は 401 + {"error":"unauthorized"}（loginCheckApi）
//     - node_id は ?node_id=N（必須）。work_id は ?work_id=N（省略時は最新1件）
//     - 一次データ（nodes/edges/evidence）のみ返す。二次データは混ぜない（ADR-004）
//     - 全エッジのエビデンス（原文・文番号・位置）を辿れる（ADR-003）
//     - DBアクセスは lib/GraphRepository.php に集約（prepared statements）
//     - config/lib は __DIR__ 基準で require（symlink越しでも解決。CLAUDE.md §9）
//
//   レスポンス例:
//     { "work_id": 1,
//       "node": {"id":2,"label":"先生","node_type":"person","frequency":30},
//       "neighbors": [
//         {"edge_id":1,"direction":"out","edge_type":"師事","weight":0.6821,
//          "method":"co_occurrence",
//          "neighbor":{"id":1,"label":"私","node_type":"person"},
//          "evidence":[{"sentence_id":12,"text_span_start":0,"text_span_end":8,
//                       "sentence_text":"私はその人を常に先生と呼んでいた。"}]}
//       ] }
// ============================================================

// weight（DECIMAL由来のfloat）を冗長な精度で出さない（graph_data.php と揃える）。
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

  // --- 2. node_id（必須）の検証 ---
  //   数値以外・0以下・未指定は不正。graph_data.php の work_id と同じ作法。
  if (!isset($_GET['node_id']) || $_GET['node_id'] === '') {
    http_response_code(400);
    echo json_encode(['error' => 'invalid node_id'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $nodeId = filter_var($_GET['node_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  if ($nodeId === false) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid node_id'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // --- 3. work_id の解決（GET優先、無ければ最新1件） ---
  $workId = null;
  if (isset($_GET['work_id']) && $_GET['work_id'] !== '') {
    $workId = filter_var($_GET['work_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($workId === false) {
      http_response_code(400);
      echo json_encode(['error' => 'invalid work_id'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  } else {
    $workId = $repo->latestWorkId();
  }

  // --- 4. ノード本体を取得（作品が無い/ノードが無ければ404） ---
  $node = ($workId === null) ? null : $repo->findNode($workId, $nodeId);
  if ($node === null) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // --- 5. 隣接エッジ＋相手ノードを取得 ---
  $rows = $repo->findNeighbors($workId, $nodeId);

  // --- 6. 各エッジのエビデンスをまとめて取得（N+1回避） ---
  $edgeIds = array_map(static function ($r) { return (int)$r['edge_id']; }, $rows);
  $evidenceByEdge = $repo->findEvidenceByEdgeIds($edgeIds);

  // --- 7. レスポンス整形 ---
  $neighbors = [];
  foreach ($rows as $r) {
    $edgeId = (int)$r['edge_id'];
    $evList = [];
    foreach ($evidenceByEdge[$edgeId] ?? [] as $ev) {
      $evList[] = [
        'sentence_id'     => $ev['sentence_id'] === null ? null : (int)$ev['sentence_id'],
        'text_span_start' => $ev['text_span_start'] === null ? null : (int)$ev['text_span_start'],
        'text_span_end'   => $ev['text_span_end'] === null ? null : (int)$ev['text_span_end'],
        'sentence_text'   => $ev['sentence_text'],
      ];
    }
    $neighbors[] = [
      'edge_id'   => $edgeId,
      'direction' => $r['direction'],
      'edge_type' => $r['edge_type'],
      'weight'    => (float)$r['weight'],
      'method'    => $r['method'],
      'neighbor'  => [
        'id'        => (int)$r['neighbor_id'],
        'label'     => $r['neighbor_label'],
        'node_type' => $r['neighbor_type'],
      ],
      'evidence'  => $evList,
    ];
  }

  echo json_encode([
    'work_id'   => $workId,
    'node'      => [
      'id'        => (int)$node['id'],
      'label'     => $node['label'],
      'node_type' => $node['node_type'],
      'frequency' => (int)$node['frequency'],
    ],
    'neighbors' => $neighbors,
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $ex) {
  // 予期せぬ失敗（DB接続エラー等）。詳細は返さず500 JSONのみ。
  http_response_code(500);
  echo json_encode(['error' => 'internal_error'], JSON_UNESCAPED_UNICODE);
  exit;
}

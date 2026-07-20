<?php
// ============================================================
// groupings_data.php — 保存済みのテーマ別グルーピング（二次データ）を返すAPI
//   出典: idea.md §8.5・§9 / Issue #8
//
//   仕様（CLAUDE.md §5/§6）:
//     - 要ログイン。未認証は 401 + {"error":"unauthorized"}（loginCheckApi）
//     - work_id は ?work_id=N で指定。省略時は最新1件を自動選択（graph_data.php と同じ）
//     - ★二次データ（llm_groupings）のみ返す。一次データ（nodes/edges）は混ぜない（ADR-004）。
//     - DBアクセスは lib/GroupingRepository.php に集約（prepared statements）。
//     - このAPIはAIを呼ばない（＝ページ再読込で無駄な課金をしない）。
//       AIによる生成・保存は groupings_llm.php（POST）が担当し、ここは保存済みを読むだけ。
//
//   レスポンス例:
//     { "work_id": 1,
//       "proposals": [
//         { "proposal_set": 1,
//           "groups": [ {"group_label":"対立関係","description":"...","node_ids":[3,4]}, ... ] },
//         ...
//       ] }
// ============================================================

session_start();
require_once __DIR__ . '/functions.php';

// --- 1. 認証（未認証は 401 JSON で停止） ---
loginCheckApi();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/GraphRepository.php';
require_once __DIR__ . '/../lib/GroupingRepository.php';

try {
  /** @var PDO $pdo */
  $pdo       = require __DIR__ . '/../config/db.php';
  $graphRepo = new GraphRepository($pdo);
  $groupRepo = new GroupingRepository($pdo);

  // --- 2. work_id の解決（GET優先、無ければ最新1件。graph_data.php と揃える） ---
  $workId = null;
  if (isset($_GET['work_id']) && $_GET['work_id'] !== '') {
    $workId = filter_var($_GET['work_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($workId === false) {
      http_response_code(400);
      echo json_encode(['error' => 'invalid work_id'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  } else {
    $workId = $graphRepo->latestWorkId();
  }

  // --- 3. 作品が無い場合は空の案を返す（200） ---
  if ($workId === null) {
    echo json_encode(['work_id' => null, 'proposals' => []], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // --- 4. 保存済みのグルーピング案を返す（無ければ proposals は空配列） ---
  echo json_encode(
    ['work_id' => $workId, 'proposals' => $groupRepo->findProposals($workId)],
    JSON_UNESCAPED_UNICODE
  );
} catch (Throwable $ex) {
  // 予期せぬ失敗（DB接続エラー等）。詳細は返さず500 JSONのみ。
  http_response_code(500);
  echo json_encode(['error' => 'internal_error'], JSON_UNESCAPED_UNICODE);
  exit;
}

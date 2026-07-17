<?php
// ============================================================
// import.php — 世界Aの解析済みJSON（pipeline/output/*.json）をMySQLに取り込む
//   ★CLI実行専用（Web経由の実行は禁止。CLAUDE.md §6 / idea.md §11）
//   ★全INSERTは prepared statements 経由（文字列結合でSQLを組まない）
//   ★AIは一切関与しない決定論的処理（ADR-002）
//
//   使い方:
//     /Applications/XAMPP/xamppfiles/bin/php public/import.php
//
//   再実行時の挙動: 同一work（title＋author）が既にあれば、その work 配下の
//   evidence→edges→nodes→work を削除してから再INSERT（毎回まっさら＝冪等）
// ============================================================

// --- 1. CLI以外からの実行を弾く（Web経由実行の禁止） ---
if (php_sapi_name() !== 'cli') {
  http_response_code(403);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'forbidden: import.php is CLI-only'], JSON_UNESCAPED_UNICODE);
  exit(1);
}

// --- 2. 設定・入力ファイルのパス（__DIR__基準。CWD依存にしない） ---
$nodesJsonPath = __DIR__ . '/../pipeline/output/nodes.json';
$edgesJsonPath = __DIR__ . '/../pipeline/output/edges.json';

// --- 3. JSONの読み込みとパース ---
function loadJson(string $path): array {
  if (!is_file($path)) {
    fwrite(STDERR, "エラー: JSONが見つかりません: {$path}\n");
    exit(1);
  }
  $raw = file_get_contents($path);
  $data = json_decode($raw, true);
  if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "エラー: JSONのパースに失敗: {$path} ({$path} : " . json_last_error_msg() . ")\n");
    exit(1);
  }
  return $data;
}

$nodesData = loadJson($nodesJsonPath);
$edgesData = loadJson($edgesJsonPath);

// --- 4. 最低限のバリデーション ---
if (empty($nodesData['work']) || !isset($nodesData['nodes']) || !is_array($nodesData['nodes'])) {
  fwrite(STDERR, "エラー: nodes.json の構造が不正です（work / nodes が必要）\n");
  exit(1);
}
if (!isset($edgesData['edges']) || !is_array($edgesData['edges'])) {
  fwrite(STDERR, "エラー: edges.json の構造が不正です（edges が必要）\n");
  exit(1);
}

$work  = $nodesData['work'];
$nodes = $nodesData['nodes'];
$edges = $edgesData['edges'];

// --- 5. DB接続 ---
$pdo = require __DIR__ . '/../config/db.php';

echo "取り込み開始: 「{$work['title']}」（{$work['author']}）\n";

try {
  $pdo->beginTransaction();

  // --- 5-1. 冪等化: 同一work（title＋author）が既にあれば配下を全削除 ---
  //   FK制約のため evidence → edges → nodes → work の順で削除する。
  $sel = $pdo->prepare(
    'SELECT id FROM works WHERE title = :title AND author <=> :author'
  );
  $sel->bindValue(':title', $work['title']);
  $sel->bindValue(':author', $work['author'] ?? null);
  $sel->execute();
  $existingWorkIds = $sel->fetchAll(PDO::FETCH_COLUMN, 0);

  foreach ($existingWorkIds as $oldWorkId) {
    // evidence（edges経由）
    $delEv = $pdo->prepare(
      'DELETE ev FROM evidence ev
         JOIN edges e ON ev.edge_id = e.id
        WHERE e.work_id = :wid'
    );
    $delEv->bindValue(':wid', $oldWorkId, PDO::PARAM_INT);
    $delEv->execute();

    $delEdges = $pdo->prepare('DELETE FROM edges WHERE work_id = :wid');
    $delEdges->bindValue(':wid', $oldWorkId, PDO::PARAM_INT);
    $delEdges->execute();

    $delNodes = $pdo->prepare('DELETE FROM nodes WHERE work_id = :wid');
    $delNodes->bindValue(':wid', $oldWorkId, PDO::PARAM_INT);
    $delNodes->execute();

    $delWork = $pdo->prepare('DELETE FROM works WHERE id = :wid');
    $delWork->bindValue(':wid', $oldWorkId, PDO::PARAM_INT);
    $delWork->execute();

    echo "既存データを削除しました（work_id={$oldWorkId}）\n";
  }

  // --- 5-2. works を1件INSERT ---
  $insWork = $pdo->prepare(
    'INSERT INTO works (title, author, source) VALUES (:title, :author, :source)'
  );
  $insWork->bindValue(':title',  $work['title']);
  $insWork->bindValue(':author', $work['author'] ?? null);
  $insWork->bindValue(':source', $work['source'] ?? null);
  $insWork->execute();
  $workId = (int)$pdo->lastInsertId();

  // --- 5-3. nodes をINSERT。JSONのローカルref → DBのid へマッピング ---
  $insNode = $pdo->prepare(
    'INSERT INTO nodes (work_id, label, node_type, frequency)
     VALUES (:work_id, :label, :node_type, :frequency)'
  );
  $refToNodeId = [];  // 例: "n1" => 17
  $nodeCount = 0;
  foreach ($nodes as $n) {
    if (empty($n['ref']) || !isset($n['label'])) {
      throw new RuntimeException('nodes[] に ref または label が欠けています');
    }
    $insNode->bindValue(':work_id',   $workId, PDO::PARAM_INT);
    $insNode->bindValue(':label',     $n['label']);
    $insNode->bindValue(':node_type', $n['node_type'] ?? null);
    $insNode->bindValue(':frequency', (int)($n['frequency'] ?? 0), PDO::PARAM_INT);
    $insNode->execute();
    $refToNodeId[$n['ref']] = (int)$pdo->lastInsertId();
    $nodeCount++;
  }

  // --- 5-4. edges と evidence をINSERT ---
  $insEdge = $pdo->prepare(
    'INSERT INTO edges (work_id, source_node_id, target_node_id, edge_type, weight, method)
     VALUES (:work_id, :source_node_id, :target_node_id, :edge_type, :weight, :method)'
  );
  $insEvidence = $pdo->prepare(
    'INSERT INTO evidence (edge_id, sentence_id, text_span_start, text_span_end, sentence_text)
     VALUES (:edge_id, :sentence_id, :text_span_start, :text_span_end, :sentence_text)'
  );
  $edgeCount = 0;
  $evidenceCount = 0;
  foreach ($edges as $e) {
    $srcRef = $e['source_ref'] ?? null;
    $tgtRef = $e['target_ref'] ?? null;
    if (!isset($refToNodeId[$srcRef]) || !isset($refToNodeId[$tgtRef])) {
      throw new RuntimeException("edges[] の参照ノードが nodes.json に存在しません（source={$srcRef}, target={$tgtRef}）");
    }
    $insEdge->bindValue(':work_id',        $workId, PDO::PARAM_INT);
    $insEdge->bindValue(':source_node_id', $refToNodeId[$srcRef], PDO::PARAM_INT);
    $insEdge->bindValue(':target_node_id', $refToNodeId[$tgtRef], PDO::PARAM_INT);
    $insEdge->bindValue(':edge_type',      $e['edge_type'] ?? null);
    $insEdge->bindValue(':weight',         (float)($e['weight'] ?? 0));
    $insEdge->bindValue(':method',         $e['method'] ?? null);
    $insEdge->execute();
    $edgeId = (int)$pdo->lastInsertId();
    $edgeCount++;

    $evidences = $e['evidence'] ?? [];
    foreach ($evidences as $ev) {
      $insEvidence->bindValue(':edge_id',         $edgeId, PDO::PARAM_INT);
      $insEvidence->bindValue(':sentence_id',     isset($ev['sentence_id']) ? (int)$ev['sentence_id'] : null,
                              isset($ev['sentence_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
      $insEvidence->bindValue(':text_span_start', isset($ev['text_span_start']) ? (int)$ev['text_span_start'] : null,
                              isset($ev['text_span_start']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
      $insEvidence->bindValue(':text_span_end',   isset($ev['text_span_end']) ? (int)$ev['text_span_end'] : null,
                              isset($ev['text_span_end']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
      $insEvidence->bindValue(':sentence_text',   $ev['sentence_text'] ?? null);
      $insEvidence->execute();
      $evidenceCount++;
    }
  }

  $pdo->commit();

  echo "取り込み完了: work_id={$workId} / nodes={$nodeCount} / edges={$edgeCount} / evidence={$evidenceCount}\n";
} catch (Throwable $ex) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  fwrite(STDERR, "取り込み失敗（ロールバックしました）: " . $ex->getMessage() . "\n");
  exit(1);
}

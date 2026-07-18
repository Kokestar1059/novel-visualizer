<?php
// ============================================================
// GraphRepository.php — ノード・エッジ・エビデンスのDBアクセスを集約するクラス
//   出典: idea.md §5（lib/ の役割）
//
//   設計方針（CLAUDE.md §5/§6）:
//     - 一次データ（nodes/edges/evidence＝事実）のみを扱う。
//       二次データ（llm_groupings＝AI解釈）には一切触れない（ADR-004。二次データは #8 で別クラス想定）。
//     - SQLはすべて prepared statements。値は必ず bindValue する（文字列結合でSQLを組まない）。
//
//   使い方:
//     $pdo  = require __DIR__ . '/../config/db.php';
//     $repo = new GraphRepository($pdo);
//     $workId = $repo->latestWorkId();
//     $nodes  = $repo->findNodes($workId);
//     $edges  = $repo->findEdges($workId);
// ============================================================

class GraphRepository {
  private PDO $pdo;

  public function __construct(PDO $pdo) {
    $this->pdo = $pdo;
  }

  // ------------------------------------------------------------
  // latestWorkId — 最新（id最大）の作品のidを返す。作品が無ければ null。
  //   graph_data.php で work_id が省略された場合の既定値に使う（#4で確定）。
  // ------------------------------------------------------------
  public function latestWorkId(): ?int {
    $stmt = $this->pdo->query('SELECT id FROM works ORDER BY id DESC LIMIT 1');
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
  }

  // ------------------------------------------------------------
  // findNodes — 指定作品のノード一覧を返す（一次データ）。
  //   戻り値: [['id'=>1,'label'=>'太郎','node_type'=>'person','frequency'=>10], ...]
  // ------------------------------------------------------------
  public function findNodes(int $workId): array {
    $stmt = $this->pdo->prepare(
      'SELECT id, label, node_type, frequency
         FROM nodes
        WHERE work_id = :work_id
        ORDER BY id'
    );
    $stmt->bindValue(':work_id', $workId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
  }

  // ------------------------------------------------------------
  // findEdges — 指定作品のエッジ一覧を返す（一次データ）。
  //   戻り値: [['id'=>5,'source_node_id'=>1,'target_node_id'=>2,
  //            'edge_type'=>'共起','weight'=>'0.5000','method'=>'co_occurrence'], ...]
  // ------------------------------------------------------------
  public function findEdges(int $workId): array {
    $stmt = $this->pdo->prepare(
      'SELECT id, source_node_id, target_node_id, edge_type, weight, method
         FROM edges
        WHERE work_id = :work_id
        ORDER BY id'
    );
    $stmt->bindValue(':work_id', $workId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
  }

  // ------------------------------------------------------------
  // findNode — 指定作品の1ノードを返す（一次データ）。
  //   node_detail.php でクリックされたノード本体の表示に使う。
  //   作品に属さない or 存在しない node_id のときは null（呼び出し側で404）。
  //   戻り値: ['id'=>2,'label'=>'先生','node_type'=>'person','frequency'=>30]
  // ------------------------------------------------------------
  public function findNode(int $workId, int $nodeId): ?array {
    $stmt = $this->pdo->prepare(
      'SELECT id, label, node_type, frequency
         FROM nodes
        WHERE id = :node_id AND work_id = :work_id'
    );
    $stmt->bindValue(':node_id', $nodeId, PDO::PARAM_INT);
    $stmt->bindValue(':work_id', $workId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();
    return $row === false ? null : $row;
  }

  // ------------------------------------------------------------
  // findNeighbors — 指定ノードに接続するエッジ＋相手ノードを返す（一次データ）。
  //   node_detail.php の隣接ノード表示に使う。source/target どちら向きでも拾う。
  //   ★emulation OFF のため同名プレースホルダを再利用できない。:nid_src / :nid_tgt に分ける。
  //   direction: 'out'＝クリックノードが起点 / 'in'＝終点。
  //   戻り値: [['edge_id'=>1,'direction'=>'out','edge_type'=>'師事','weight'=>'0.6821',
  //            'method'=>'co_occurrence','neighbor_id'=>1,'neighbor_label'=>'私',
  //            'neighbor_type'=>'person'], ...]
  // ------------------------------------------------------------
  public function findNeighbors(int $workId, int $nodeId): array {
    $stmt = $this->pdo->prepare(
      'SELECT e.id AS edge_id,
              CASE WHEN e.source_node_id = :nid_dir THEN \'out\' ELSE \'in\' END AS direction,
              e.edge_type, e.weight, e.method,
              n.id        AS neighbor_id,
              n.label     AS neighbor_label,
              n.node_type AS neighbor_type
         FROM edges e
         JOIN nodes n
           ON n.id = CASE WHEN e.source_node_id = :nid_case
                          THEN e.target_node_id ELSE e.source_node_id END
        WHERE e.work_id = :work_id
          AND (e.source_node_id = :nid_src OR e.target_node_id = :nid_tgt)
        ORDER BY e.id'
    );
    $stmt->bindValue(':nid_dir',  $nodeId, PDO::PARAM_INT);
    $stmt->bindValue(':nid_case', $nodeId, PDO::PARAM_INT);
    $stmt->bindValue(':nid_src',  $nodeId, PDO::PARAM_INT);
    $stmt->bindValue(':nid_tgt',  $nodeId, PDO::PARAM_INT);
    $stmt->bindValue(':work_id',  $workId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
  }

  // ------------------------------------------------------------
  // findEvidenceByEdgeIds — 複数エッジのエビデンス（原文根拠）をまとめて返す（一次データ）。
  //   N+1 回避のため IN (?, ?, ...) で一括取得。プレースホルダは edge_id 数ぶん
  //   動的生成し、値は全て bindValue する（＝文字列結合でSQLを組まない。ADR-003/§6準拠）。
  //   戻り値: edge_id をキーにグルーピングした連想配列
  //     [1 => [['sentence_id'=>12,'text_span_start'=>0,'text_span_end'=>8,
  //             'sentence_text'=>'...'], ...], ...]
  // ------------------------------------------------------------
  public function findEvidenceByEdgeIds(array $edgeIds): array {
    if (empty($edgeIds)) {
      return [];
    }
    // ? を edge_id の個数ぶん並べる（値は下で1件ずつ bindValue する）
    $placeholders = implode(', ', array_fill(0, count($edgeIds), '?'));
    $stmt = $this->pdo->prepare(
      'SELECT edge_id, sentence_id, text_span_start, text_span_end, sentence_text
         FROM evidence
        WHERE edge_id IN (' . $placeholders . ')
        ORDER BY edge_id, id'
    );
    $pos = 1;
    foreach ($edgeIds as $eid) {
      $stmt->bindValue($pos++, (int)$eid, PDO::PARAM_INT);
    }
    $stmt->execute();

    $grouped = [];
    foreach ($stmt->fetchAll() as $ev) {
      $grouped[(int)$ev['edge_id']][] = $ev;
    }
    return $grouped;
  }
}

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
}

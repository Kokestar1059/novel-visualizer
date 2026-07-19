<?php
// ============================================================
// QueryBuilder.php — AI生成のクエリJSONを「安全な prepared statement」に変換して実行する
//   出典: idea.md §8.4 / Issue #7
//
//   設計方針（CLAUDE.md §5/§6・ADR-005 が最重要）:
//     - AIに直接SQLを書かせない。AIが出せるのは action と params だけ。
//       ここで action を「ホワイトリスト」で解釈し、対応する固定SQLのみ実行する。
//     - 未知の action は実行せず InvalidArgumentException を投げる。
//     - params の各値は必ず bindValue でバインドする（文字列結合でSQLを組まない）。
//     - AI由来の値（edge_type 等）はDBに実在する値かを照合してから使う（多層防御）。
//       ＝プロンプトで縛る＋ここでも縛る。プロンプトインジェクションで変な値が来ても弾く。
//     - 一次データ（nodes/edges＝事実）のみ返す。二次データ（llm_groupings）には触れない（ADR-004）。
//
//   #7 のスコープ: action は "filter_edges" の1種のみ（edge_type での絞り込み）。
//   center_node / max_depth（N-hop）は Issue #9 で拡張予定。
//
//   使い方:
//     $pdo = require __DIR__ . '/../config/db.php';
//     $qb  = new QueryBuilder($pdo);
//     $result = $qb->run($queryJson);   // ['work_id'=>1,'elements'=>['nodes'=>[...],'edges'=>[...]]]
// ============================================================

class QueryBuilder {
  private PDO $pdo;

  // 許可する action の一覧（ホワイトリスト）。ここに無い action は実行しない。
  private const ALLOWED_ACTIONS = ['filter_edges'];

  public function __construct(PDO $pdo) {
    $this->pdo = $pdo;
  }

  // ------------------------------------------------------------
  // run — クエリJSON（連想配列）を受け取り、ホワイトリストで振り分けて実行する。
  //   $query 例: ['action'=>'filter_edges','params'=>['work_id'=>1,'edge_type'=>'恋慕']]
  //   戻り値  : graph_data.php と同じ Cytoscape 形式
  //             ['work_id'=>int, 'elements'=>['nodes'=>[...], 'edges'=>[...]]]
  //   不正な入力・未知 action は InvalidArgumentException（呼び出し側で400にする）。
  // ------------------------------------------------------------
  public function run(array $query): array {
    // --- action の検証（ホワイトリスト方式・ADR-005） ---
    $action = $query['action'] ?? null;
    if (!is_string($action) || !in_array($action, self::ALLOWED_ACTIONS, true)) {
      throw new InvalidArgumentException('unknown action');
    }

    $params = $query['params'] ?? [];
    if (!is_array($params)) {
      throw new InvalidArgumentException('invalid params');
    }

    // action ごとの固定処理へ振り分け（AIはここの分岐先を選ぶだけ。SQLは書けない）。
    switch ($action) {
      case 'filter_edges':
        return $this->filterEdges($params);
      default:
        // ALLOWED_ACTIONS に載っているのに分岐が無い＝実装漏れ。安全側で弾く。
        throw new InvalidArgumentException('unknown action');
    }
  }

  // ------------------------------------------------------------
  // filterEdges — 指定作品のエッジを edge_type で絞り込み、その両端ノードと共に返す。
  //   params:
  //     work_id   int    必須。対象作品
  //     edge_type string 任意。指定時はその関係種別のみ。省略/空なら全エッジ（＝全体表示）
  //   ★edge_type はAI由来の値。DBに実在する種別だけを許可し、bindValue で束縛する。
  //     実在しない種別が来たら「該当なし」（空グラフ）を返す（誤クエリでも安全に空になる）。
  // ------------------------------------------------------------
  private function filterEdges(array $params): array {
    // --- work_id（必須・正の整数） ---
    $workId = filter_var($params['work_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($workId === false) {
      throw new InvalidArgumentException('invalid work_id');
    }

    // --- edge_type（任意）。文字列以外・空文字は「指定なし」扱い ---
    $edgeType = $params['edge_type'] ?? null;
    if ($edgeType !== null && !is_string($edgeType)) {
      throw new InvalidArgumentException('invalid edge_type');
    }
    if ($edgeType === '') {
      $edgeType = null;
    }

    // --- エッジ取得（prepared statement。edge_type はあるときだけ束縛） ---
    if ($edgeType === null) {
      // 指定なし＝全エッジ（＝実質「全体表示に戻す」）
      $stmt = $this->pdo->prepare(
        'SELECT id, source_node_id, target_node_id, edge_type, weight, method
           FROM edges
          WHERE work_id = :work_id
          ORDER BY id'
      );
      $stmt->bindValue(':work_id', $workId, PDO::PARAM_INT);
    } else {
      $stmt = $this->pdo->prepare(
        'SELECT id, source_node_id, target_node_id, edge_type, weight, method
           FROM edges
          WHERE work_id = :work_id
            AND edge_type = :edge_type
          ORDER BY id'
      );
      $stmt->bindValue(':work_id',  $workId, PDO::PARAM_INT);
      $stmt->bindValue(':edge_type', $edgeType, PDO::PARAM_STR);
    }
    $stmt->execute();
    $edges = $stmt->fetchAll();

    // --- 絞り込んだエッジに登場するノードだけを集める ---
    //   （関係が消えて孤立したノードは出さない＝「絞り込み後のグラフ」になる）
    $nodeIds = [];
    foreach ($edges as $e) {
      $nodeIds[(int)$e['source_node_id']] = true;
      $nodeIds[(int)$e['target_node_id']] = true;
    }
    $nodes = $this->findNodesByIds($workId, array_keys($nodeIds));

    // --- Cytoscape 形式へ整形（graph_data.php と完全に同じ形にする） ---
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

    return [
      'work_id'  => $workId,
      'elements' => ['nodes' => $cyNodes, 'edges' => $cyEdges],
    ];
  }

  // ------------------------------------------------------------
  // findNodesByIds — 指定作品の、指定id群のノードをまとめて取得（一次データ）。
  //   IN (?, ?, ...) のプレースホルダは id 数ぶん動的生成し、値は全て bindValue する
  //   （＝文字列結合でSQLを組まない。GraphRepository::findEvidenceByEdgeIds と同じ作法）。
  // ------------------------------------------------------------
  private function findNodesByIds(int $workId, array $nodeIds): array {
    if (empty($nodeIds)) {
      return [];
    }
    $placeholders = implode(', ', array_fill(0, count($nodeIds), '?'));
    $stmt = $this->pdo->prepare(
      'SELECT id, label, node_type, frequency
         FROM nodes
        WHERE work_id = ?
          AND id IN (' . $placeholders . ')
        ORDER BY id'
    );
    $pos = 1;
    $stmt->bindValue($pos++, $workId, PDO::PARAM_INT);
    foreach ($nodeIds as $nid) {
      $stmt->bindValue($pos++, (int)$nid, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchAll();
  }

  // ------------------------------------------------------------
  // distinctEdgeTypes — 指定作品に実在する edge_type の一覧を返す。
  //   query_llm.php がプロンプトに「選んでよい種別」として渡すために使う（ADR-002）。
  //   ＝AIには実在する選択肢しか見せない。ここで得た値だけが正当な edge_type。
  // ------------------------------------------------------------
  public function distinctEdgeTypes(int $workId): array {
    $stmt = $this->pdo->prepare(
      'SELECT DISTINCT edge_type
         FROM edges
        WHERE work_id = :work_id
          AND edge_type IS NOT NULL
          AND edge_type <> \'\'
        ORDER BY edge_type'
    );
    $stmt->bindValue(':work_id', $workId, PDO::PARAM_INT);
    $stmt->execute();
    // 1列だけ取り出して素の配列にする
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
  }
}

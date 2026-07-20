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
//   #9 で "get_neighbors"（center_node から max_depth ホップの近傍＝N-hop）を追加。
//     ★N-hop は再帰CTEではなく PHP側 BFS ループで実装する（CLAUDE.md §2）。
//       本番Sakuraのバージョンが未確認（5.7系だと再帰CTE不可）でも動くようにするため。
//       研究用・単一作品でグラフは小さいので、PHPループでも性能は問題にならない。
//
//   使い方:
//     $pdo = require __DIR__ . '/../config/db.php';
//     $qb  = new QueryBuilder($pdo);
//     $result = $qb->run($queryJson);   // ['work_id'=>1,'elements'=>['nodes'=>[...],'edges'=>[...]]]
// ============================================================

class QueryBuilder {
  private PDO $pdo;

  // 許可する action の一覧（ホワイトリスト）。ここに無い action は実行しない。
  private const ALLOWED_ACTIONS = ['filter_edges', 'get_neighbors'];

  // N-hop 探索の最大深さ（暴走・巨大部分グラフ防止の上限）。これを超える指定はここまで丸める。
  private const MAX_DEPTH = 4;

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
      case 'get_neighbors':
        return $this->getNeighbors($params);
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

    // --- Cytoscape 形式へ整形して返す（graph_data.php と完全に同じ形） ---
    return $this->buildResult($workId, $nodes, $edges);
  }

  // ------------------------------------------------------------
  // getNeighbors — center_node を中心に max_depth ホップ以内の近傍だけを返す（N-hop・#9）。
  //   params:
  //     work_id     int    必須。対象作品
  //     center_node string 必須。中心ノードの「ラベル」（AI由来）。実在しなければ空グラフを返す
  //     max_depth   int    任意。1〜MAX_DEPTH にクランプ。省略時は1
  //   実装（CLAUDE.md §2）:
  //     再帰CTEを使わず PHP側 BFS で訪問ノード集合を深さ方向に広げる。
  //     エッジは無向として扱う（source/target どちらからでも隣接とみなす）。
  //     最後に「訪問ノード集合の両端に閉じたエッジ（誘導部分グラフ）」を返す。
  //   ★center_node もAI由来。ラベル→id はDB照合（prepared）で解決し、無ければ安全に空を返す。
  // ------------------------------------------------------------
  private function getNeighbors(array $params): array {
    // --- work_id（必須・正の整数） ---
    $workId = filter_var($params['work_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($workId === false) {
      throw new InvalidArgumentException('invalid work_id');
    }

    // --- center_node（必須・文字列ラベル） ---
    $centerLabel = $params['center_node'] ?? null;
    if (!is_string($centerLabel) || $centerLabel === '') {
      throw new InvalidArgumentException('invalid center_node');
    }

    // --- max_depth（任意・1〜MAX_DEPTH にクランプ。省略/不正は1） ---
    $depth = filter_var($params['max_depth'] ?? 1, FILTER_VALIDATE_INT);
    if ($depth === false || $depth < 1) {
      $depth = 1;
    } elseif ($depth > self::MAX_DEPTH) {
      $depth = self::MAX_DEPTH;
    }

    // --- 中心ノードのラベル→id 解決（実在しなければ空グラフ＝安全側） ---
    $centerId = $this->resolveNodeIdByLabel($workId, $centerLabel);
    if ($centerId === null) {
      return $this->buildResult($workId, [], []);
    }

    // --- BFS で depth ホップまで訪問ノードを広げる ---
    //   visited: 到達済みノードid集合（キー=id）。frontier: 今回の層で新たに広げる起点。
    $visited  = [$centerId => true];
    $frontier = [$centerId];
    for ($d = 0; $d < $depth && !empty($frontier); $d++) {
      $rows = $this->neighborsOf($workId, $frontier);
      $next = [];
      foreach ($rows as $r) {
        foreach ([(int)$r['source_node_id'], (int)$r['target_node_id']] as $nid) {
          if (!isset($visited[$nid])) {
            $visited[$nid] = true;
            $next[] = $nid;
          }
        }
      }
      $frontier = $next;
    }

    // --- 訪問ノード集合に「閉じた」エッジ（両端とも集合内）だけを集める＝誘導部分グラフ ---
    $visitedIds = array_keys($visited);
    $edges = $this->edgesWithinNodeSet($workId, $visitedIds);
    $nodes = $this->findNodesByIds($workId, $visitedIds);

    return $this->buildResult($workId, $nodes, $edges);
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
  // buildResult — nodes/edges の生行を Cytoscape 形式へ整形して返す（graph_data.php と同一形式）。
  //   filterEdges / getNeighbors から共通で使う（整形ロジックの重複を避ける）。
  // ------------------------------------------------------------
  private function buildResult(int $workId, array $nodes, array $edges): array {
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
  // resolveNodeIdByLabel — 指定作品で label に一致するノードの id を返す（無ければ null）。
  //   center_node（AI由来ラベル）を安全に id へ解決するための照合（prepared statement）。
  //   同名ラベルが複数ある場合は最小 id を採用（決定論的・再現性のため）。
  // ------------------------------------------------------------
  private function resolveNodeIdByLabel(int $workId, string $label): ?int {
    $stmt = $this->pdo->prepare(
      'SELECT id FROM nodes
        WHERE work_id = :work_id AND label = :label
        ORDER BY id
        LIMIT 1'
    );
    $stmt->bindValue(':work_id', $workId, PDO::PARAM_INT);
    $stmt->bindValue(':label',   $label,  PDO::PARAM_STR);
    $stmt->execute();
    $id = $stmt->fetchColumn();
    return ($id === false) ? null : (int)$id;
  }

  // ------------------------------------------------------------
  // neighborsOf — frontier のノード群に接続するエッジを、両方向（source/target）で取得する。
  //   BFS の1層ぶん。IN(...) は id 数ぶんプレースホルダを動的生成し、値は全て bindValue する
  //   （文字列結合でSQLを組まない）。同じ id リストを source用・target用に2回束縛する。
  // ------------------------------------------------------------
  private function neighborsOf(int $workId, array $frontierIds): array {
    if (empty($frontierIds)) {
      return [];
    }
    $placeholders = implode(', ', array_fill(0, count($frontierIds), '?'));
    $stmt = $this->pdo->prepare(
      'SELECT id, source_node_id, target_node_id
         FROM edges
        WHERE work_id = ?
          AND (source_node_id IN (' . $placeholders . ')
               OR target_node_id IN (' . $placeholders . '))'
    );
    $pos = 1;
    $stmt->bindValue($pos++, $workId, PDO::PARAM_INT);
    foreach ($frontierIds as $nid) {   // source_node_id IN (...) 用
      $stmt->bindValue($pos++, (int)$nid, PDO::PARAM_INT);
    }
    foreach ($frontierIds as $nid) {   // target_node_id IN (...) 用
      $stmt->bindValue($pos++, (int)$nid, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchAll();
  }

  // ------------------------------------------------------------
  // edgesWithinNodeSet — 両端とも指定ノード集合に含まれるエッジ（誘導部分グラフ）を返す。
  //   N-hop の最終描画用。集合の「外」へ飛び出す半端なエッジは出さない。
  // ------------------------------------------------------------
  private function edgesWithinNodeSet(int $workId, array $nodeIds): array {
    if (empty($nodeIds)) {
      return [];
    }
    $placeholders = implode(', ', array_fill(0, count($nodeIds), '?'));
    $stmt = $this->pdo->prepare(
      'SELECT id, source_node_id, target_node_id, edge_type, weight, method
         FROM edges
        WHERE work_id = ?
          AND source_node_id IN (' . $placeholders . ')
          AND target_node_id IN (' . $placeholders . ')
        ORDER BY id'
    );
    $pos = 1;
    $stmt->bindValue($pos++, $workId, PDO::PARAM_INT);
    foreach ($nodeIds as $nid) {   // source_node_id IN (...) 用
      $stmt->bindValue($pos++, (int)$nid, PDO::PARAM_INT);
    }
    foreach ($nodeIds as $nid) {   // target_node_id IN (...) 用
      $stmt->bindValue($pos++, (int)$nid, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchAll();
  }

  // ------------------------------------------------------------
  // distinctNodeLabels — 指定作品に実在するノードのラベル一覧を頻度降順で返す（上限つき）。
  //   query_llm.php が get_neighbors の center_node 候補として「実在ラベル」だけをAIに渡すために使う
  //   （ADR-002＝AIには実在する選択肢しか見せない）。件数上限でプロンプト肥大を防ぐ。
  // ------------------------------------------------------------
  public function distinctNodeLabels(int $workId, int $limit = 200): array {
    $limit = ($limit < 1) ? 1 : (($limit > 1000) ? 1000 : $limit);
    $stmt = $this->pdo->prepare(
      'SELECT label
         FROM nodes
        WHERE work_id = :work_id
          AND label IS NOT NULL
          AND label <> \'\'
        GROUP BY label
        ORDER BY MAX(frequency) DESC, label
        LIMIT :lim'
    );
    $stmt->bindValue(':work_id', $workId, PDO::PARAM_INT);
    $stmt->bindValue(':lim',     $limit,  PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
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

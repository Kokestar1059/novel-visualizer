<?php
// ============================================================
// GroupingRepository.php — AIによるテーマ別グルーピング（二次データ）のDBアクセスを集約するクラス
//   出典: idea.md §8.5・§9 / Issue #8
//
//   設計方針（CLAUDE.md §5/§6・ADR-002/004/006 が最重要）:
//     - 二次データ（llm_groupings＝AI解釈）のみを扱う。
//       一次データ（nodes/edges/evidence＝事実）は読み取り検証にしか使わず、絶対に書き換えない（ADR-004）。
//       ＝GraphRepository（一次データ）とは物理的に別クラスに分ける。
//     - SQLはすべて prepared statements。値は必ず bindValue する（文字列結合でSQLを組まない・§6）。
//     - AI由来の node_id は「その作品に実在する id」だけを採用する（多層防御）。
//       実在しない id は捨てる＝AIが存在しないノードを作っても本体には一切影響しない（ADR-006）。
//
//   使い方:
//     $pdo  = require __DIR__ . '/../config/db.php';
//     $repo = new GroupingRepository($pdo);
//     $validIds = $repo->nodeIdSet($workId);            // 検証用の実在ノードid集合
//     $repo->saveProposals($workId, $proposals);        // 案を洗い替え保存（冪等）
//     $sets = $repo->findProposals($workId);            // 保存済みの案を取得（描画用）
// ============================================================

class GroupingRepository {
  private PDO $pdo;

  public function __construct(PDO $pdo) {
    $this->pdo = $pdo;
  }

  // ------------------------------------------------------------
  // nodeIdSet — 指定作品に実在するノードの id 集合を返す（キー＝id, 値＝true）。
  //   AI出力の検証に使う：AIが返した node_id がこの集合に無ければ捨てる（ADR-006 多層防御）。
  //   ＝AIが存在しないノードを分類対象に挙げても、本体データには一切影響しない。
  // ------------------------------------------------------------
  public function nodeIdSet(int $workId): array {
    $stmt = $this->pdo->prepare(
      'SELECT id FROM nodes WHERE work_id = :work_id'
    );
    $stmt->bindValue(':work_id', $workId, PDO::PARAM_INT);
    $stmt->execute();
    $set = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $id) {
      $set[(int)$id] = true;
    }
    return $set;
  }

  // ------------------------------------------------------------
  // saveProposals — 指定作品のグルーピング案を「洗い替え」で保存する（冪等）。
  //   ★import.php と同じ思想：再実行するたびに、その作品の既存 llm_groupings を
  //     全削除してから新しい案を入れ直す（＝毎回まっさら。案の重複が溜まらない）。
  //   ★トランザクションで囲み、途中失敗時はロールバックする（中途半端な二次データを残さない）。
  //   ★nodes/edges/evidence には一切触れない（ADR-004）。触るのは llm_groupings のみ。
  //
  //   $proposals の形（groupings_llm.php で検証済みの構造を受け取る）:
  //     [
  //       [ 'proposal_set' => 1,
  //         'groups' => [
  //           [ 'group_label' => '対立関係', 'description' => '...',
  //             'node_ids' => [3, 4] ],
  //           ...
  //         ] ],
  //       ...
  //     ]
  //   ※node_ids は呼び出し側（groupings_llm.php）で実在idに絞り込み済みである前提だが、
  //     ここでも nodeIdSet で二重に検証してから INSERT する（多層防御）。
  //   戻り値: 実際に INSERT した行数（node×group の割り当て件数）。
  // ------------------------------------------------------------
  public function saveProposals(int $workId, array $proposals): int {
    $validIds = $this->nodeIdSet($workId);

    $del = $this->pdo->prepare('DELETE FROM llm_groupings WHERE work_id = :work_id');

    $ins = $this->pdo->prepare(
      'INSERT INTO llm_groupings
         (work_id, group_label, node_id, proposal_set, description)
       VALUES
         (:work_id, :group_label, :node_id, :proposal_set, :description)'
    );

    $inserted = 0;
    $this->pdo->beginTransaction();
    try {
      // --- 既存の案を全削除（この作品ぶんのみ） ---
      $del->bindValue(':work_id', $workId, PDO::PARAM_INT);
      $del->execute();

      // --- 新しい案を INSERT ---
      foreach ($proposals as $p) {
        $proposalSet = filter_var($p['proposal_set'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($proposalSet === false) {
          continue;   // 案番号が不正な案はスキップ（安全側）
        }
        $groups = $p['groups'] ?? [];
        if (!is_array($groups)) {
          continue;
        }
        foreach ($groups as $g) {
          $groupLabel  = isset($g['group_label']) && is_string($g['group_label']) ? $g['group_label'] : '';
          $description = isset($g['description']) && is_string($g['description']) ? $g['description'] : '';
          $nodeIds     = $g['node_ids'] ?? [];
          if (!is_array($nodeIds) || $groupLabel === '') {
            continue;
          }
          foreach ($nodeIds as $nid) {
            $nid = (int)$nid;
            // ★実在ノードだけを保存（AIが捏造した id はここで捨てる・ADR-006）
            if (!isset($validIds[$nid])) {
              continue;
            }
            $ins->bindValue(':work_id',      $workId,      PDO::PARAM_INT);
            $ins->bindValue(':group_label',  $groupLabel,  PDO::PARAM_STR);
            $ins->bindValue(':node_id',      $nid,         PDO::PARAM_INT);
            $ins->bindValue(':proposal_set', $proposalSet, PDO::PARAM_INT);
            $ins->bindValue(':description',  $description, PDO::PARAM_STR);
            $ins->execute();
            $inserted++;
          }
        }
      }

      $this->pdo->commit();
    } catch (Throwable $ex) {
      // 途中で失敗したら二次データを中途半端に残さない（本体データはそもそも触っていない）
      $this->pdo->rollBack();
      throw $ex;
    }
    return $inserted;
  }

  // ------------------------------------------------------------
  // findProposals — 指定作品の保存済みグルーピング案を、案（proposal_set）ごとにまとめて返す。
  //   groupings_data.php が「保存済みの案」を描画用JSONにするために使う（AIを再度叩かない）。
  //   戻り値（proposal_set 昇順・その中は group_label 昇順）:
  //     [
  //       [ 'proposal_set' => 1,
  //         'groups' => [
  //           [ 'group_label' => '対立関係', 'description' => '...', 'node_ids' => [3,4] ],
  //           ...
  //         ] ],
  //       ...
  //     ]
  // ------------------------------------------------------------
  public function findProposals(int $workId): array {
    $stmt = $this->pdo->prepare(
      'SELECT proposal_set, group_label, description, node_id
         FROM llm_groupings
        WHERE work_id = :work_id
        ORDER BY proposal_set, group_label, node_id'
    );
    $stmt->bindValue(':work_id', $workId, PDO::PARAM_INT);
    $stmt->execute();

    // proposal_set → group_label でグルーピングして組み立てる。
    // 説明（description）はグループ単位で1つ（同一グループの各行で同じ値が入っている）。
    $sets = [];   // proposal_set => ['groups' => [group_label => ['group_label','description','node_ids'=>[]]]]
    foreach ($stmt->fetchAll() as $row) {
      $ps    = (int)$row['proposal_set'];
      $label = (string)$row['group_label'];
      if (!isset($sets[$ps])) {
        $sets[$ps] = [];
      }
      if (!isset($sets[$ps][$label])) {
        $sets[$ps][$label] = [
          'group_label' => $label,
          'description' => (string)$row['description'],
          'node_ids'    => [],
        ];
      }
      $sets[$ps][$label]['node_ids'][] = (int)$row['node_id'];
    }

    // 連想配列 → 添字配列（JSONで配列として出す）。proposal_set は昇順（ORDER BYで担保済み）。
    $result = [];
    foreach ($sets as $ps => $groupsByLabel) {
      $result[] = [
        'proposal_set' => $ps,
        'groups'       => array_values($groupsByLabel),
      ];
    }
    return $result;
  }
}

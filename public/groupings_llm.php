<?php
// ============================================================
// groupings_llm.php — 確定済みノードを Azure OpenAI に「テーマ別分類（複数案）」させ、
//                     結果を llm_groupings（二次データ）に保存して返すAPI
//   出典: idea.md §8.5・§9 / Issue #8
//
//   設計方針（CLAUDE.md §5/§6・ADR-002/004/005/006 が最重要）:
//     - AIの役割は「確定済みノードの"分類し直し"」だけ（ADR-006）。
//       ★AIに渡すのは「ノードのラベル・種別・エッジ種別」のみ。原文（evidence.sentence_text）は絶対に渡さない。
//       ★AIに新しいノード・関係・事実を生成させない。既存ノードをグループに割り振るだけ。
//     - AIは複数の分類案（proposal_set）を返す。各案に「分類基準の説明」を添えさせる。
//     - ★多層防御：AIの出力はサーバ側で必ず再検証してから保存する。
//         - node_id は「その作品に実在する id」だけ採用（捏造idは捨てる）
//         - 案数・グループ数・ラベル長は上限で丸める（濫用・肥大化対策）
//     - 保存先は llm_groupings のみ。nodes/edges/evidence には一切触れない（ADR-004）。
//     - AIに直接SQLを書かせない。保存は GroupingRepository の prepared statement 経由（ADR-005）。
//
//   セキュリティ（idea.md §10・query_llm.php と同じ作法）:
//     - APIキーは config/llm.php のみ。レスポンス・ログに出さない。
//     - 要ログイン(401 JSON)・POST限定。上流エラー本文はクライアントに転送しない。
//     - Azure呼び出しは TLS 検証ON・タイムアウトあり。
//
//   リクエスト（JSONボディ）: {"work_id": 1}
//   レスポンス（保存済みの案）:
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

// --- 2. POST 限定 ---
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  header('Allow: POST');
  echo json_encode(['error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
  exit;
}

require_once __DIR__ . '/../lib/GraphRepository.php';
require_once __DIR__ . '/../lib/GroupingRepository.php';

// 案・グループ数などの上限（濫用・肥大化・コスト対策）。サーバ側で必ず丸める。
const MAX_PROPOSALS   = 3;    // 分類案は最大3案
const MAX_GROUPS      = 8;    // 1案あたりのグループは最大8
const MAX_LABEL_LEN   = 40;   // グループ名の最大文字数
const MAX_DESC_LEN    = 200;  // 分類基準の説明の最大文字数

try {
  // --- 3. リクエストボディ（work_id）を安全にパース ---
  $raw = file_get_contents('php://input', false, null, 0, 8 * 1024);   // 最大8KB
  $body = ($raw === false || $raw === '') ? [] : json_decode($raw, true);
  if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_body'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // --- 4. work_id をサーバ側で解決（AIには決めさせない） ---
  $pdo       = require __DIR__ . '/../config/db.php';
  $graphRepo = new GraphRepository($pdo);
  $groupRepo = new GroupingRepository($pdo);

  $workId = filter_var($body['work_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  if ($workId === false) {
    $workId = $graphRepo->latestWorkId();
  }
  if ($workId === null) {
    // 作品が1件も無い＝分類対象が無い。空の案を返す（200）。
    echo json_encode(['work_id' => null, 'proposals' => []], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // --- 5. AIに渡す「確定済みノード」を取得（★ラベル・種別のみ。原文は渡さない・ADR-006） ---
  $nodes = $graphRepo->findNodes($workId);
  if (empty($nodes)) {
    // ノードが無ければ分類しようがない。空の案を返す（AIは呼ばない＝無駄な課金をしない）。
    echo json_encode(['work_id' => $workId, 'proposals' => []], JSON_UNESCAPED_UNICODE);
    exit;
  }
  // AIに見せるのは {id, label, node_type} だけ。id で分類させる（同名ラベルの曖昧さ回避）。
  $nodeList = [];
  $validIds = [];
  foreach ($nodes as $n) {
    $id = (int)$n['id'];
    $validIds[$id] = true;
    $nodeList[] = [
      'id'        => $id,
      'label'     => (string)$n['label'],
      'node_type' => (string)($n['node_type'] ?? ''),
    ];
  }

  // --- 6. 設定読込。プレースホルダのままなら設定エラーを明示（誤送信防止・query_llm と同じ） ---
  $llm = require __DIR__ . '/../config/llm.php';
  if (!is_array($llm)
      || empty($llm['endpoint']) || empty($llm['api_key']) || empty($llm['deployment'])
      || strpos((string)$llm['api_key'], 'YOUR_') === 0
      || strpos((string)$llm['endpoint'], 'YOUR_') !== false) {
    error_log('groupings_llm: config/llm.php is not configured (placeholder values).');
    http_response_code(503);
    echo json_encode(['error' => 'llm_not_configured'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // --- 7. Azure OpenAI 呼び出し（確定済みノード → 複数の分類案JSON） ---
  $aiJson = callAzureOpenAI($llm, buildMessages($nodeList));
  if ($aiJson === null) {
    http_response_code(502);
    echo json_encode(['error' => 'llm_error'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // --- 8. AI出力をサーバ側で再検証して、保存できる安全な構造に正規化（多層防御） ---
  //   - 実在する node_id だけ採用（捏造idは捨てる）
  //   - 案数・グループ数・ラベル/説明長を上限で丸める
  $proposals = sanitizeProposals($aiJson, $validIds);
  if (empty($proposals)) {
    // AIが有効な案を返さなかった（実在ノードの割り当てが1件も無い等）。
    error_log('groupings_llm: AI returned no valid proposals; nothing saved.');
    http_response_code(422);
    echo json_encode(['error' => 'no_valid_grouping'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // --- 9. llm_groupings に洗い替え保存（本体データには一切触れない・ADR-004） ---
  $groupRepo->saveProposals($workId, $proposals);

  // --- 10. 保存済みの案を読み直して返す（DBに入った=検証済みの状態をそのまま返す） ---
  echo json_encode(
    ['work_id' => $workId, 'proposals' => $groupRepo->findProposals($workId)],
    JSON_UNESCAPED_UNICODE
  );
} catch (Throwable $ex) {
  // 予期せぬ失敗。詳細は返さず500 JSONのみ（キー等が漏れないように）。
  error_log('groupings_llm: ' . $ex->getMessage());
  http_response_code(500);
  echo json_encode(['error' => 'internal_error'], JSON_UNESCAPED_UNICODE);
  exit;
}

// ============================================================
// 以下ヘルパー（この画面専用）
// ============================================================

// ------------------------------------------------------------
// buildMessages — Azure OpenAI に送る messages 配列を組み立てる。
//   ★プロンプトの肝（idea.md §8.5・ADR-006）:
//     - 渡すのは「ノード一覧（id/label/node_type）」だけ。原文・エビデンスは渡さない。
//     - 複数の分類案（proposal_set）を返させ、各案に分類基準の説明を添えさせる。
//     - 既存ノードを分類し直すだけ。新しいノード・関係・事実を創作させない。
//     - node_id は「渡した一覧に在る id」だけを使わせる（実在しない id は禁止）。
//     - 出力は指定スキーマのJSONのみ（前置き・```なし。json_object モードでも明示）。
// ------------------------------------------------------------
function buildMessages(array $nodeList): array {
  // ノード一覧はJSON配列の文字列にして曖昧さをなくす
  $nodesJson = json_encode(array_values($nodeList), JSON_UNESCAPED_UNICODE);

  $system =
    "あなたは、日本語小説の登場人物・単語（ノード）を『テーマ別に分類する』解析補助です。\n" .
    "与えられたノード一覧を、複数の観点で分類し直し、下記スキーマの JSON にのみ翻訳してください。\n" .
    "\n" .
    "【厳守事項】\n" .
    "1. 出力は JSON オブジェクトのみ。前置き・説明文・コードフェンス(```)を一切含めない。\n" .
    "2. 分類案（proposals）は1〜" . MAX_PROPOSALS . "案。互いに異なる観点で分類する\n" .
    "   （例：案1「登場人物の対立軸」、案2「物語での役割」、案3「登場する場面」）。\n" .
    "3. 各ノードは、渡した一覧の id でのみ指し示す。一覧に無い id・新しいノード・新しい関係を絶対に創作しない。\n" .
    "4. 各グループに group_label（短いテーマ名）と description（そのグループの分類基準の説明）を付ける。\n" .
    "5. 1つの案の中で、全ノードを無理に分類しなくてよい（当てはまらないノードは含めなくてよい）。\n" .
    "\n" .
    "【ノード一覧】\n" .
    $nodesJson . "\n" .
    "\n" .
    "【出力スキーマ】\n" .
    "{\n" .
    "  \"proposals\": [\n" .
    "    { \"proposal_set\": 1,\n" .
    "      \"groups\": [\n" .
    "        { \"group_label\": <テーマ名の文字列>,\n" .
    "          \"description\": <分類基準の説明の文字列>,\n" .
    "          \"node_ids\": [<一覧内のidの数値>, ...] }\n" .
    "      ] }\n" .
    "  ]\n" .
    "}";

  return [
    ['role' => 'system', 'content' => $system],
    ['role' => 'user',   'content' => 'このノード一覧をテーマ別に分類してください。'],
  ];
}

// ------------------------------------------------------------
// sanitizeProposals — AIが返したJSONを、保存できる安全な構造に正規化する（多層防御）。
//   ★AIの言い分をそのまま信用しない。ここで:
//     - proposals は最大 MAX_PROPOSALS 件、proposal_set は 1..n に採番し直す（AI申告値は使わない）
//     - groups は1案あたり最大 MAX_GROUPS 件、node_ids は実在idのみ・重複除去
//     - group_label / description は文字列化＋長さ上限で丸める
//     - 有効な割り当て（実在ノード）が1件も無いグループ・案は捨てる
//   戻り値: GroupingRepository::saveProposals が受け取る構造の配列。
// ------------------------------------------------------------
function sanitizeProposals(array $aiJson, array $validIds): array {
  $rawProposals = $aiJson['proposals'] ?? null;
  if (!is_array($rawProposals)) {
    return [];
  }

  $out = [];
  $setNo = 0;
  foreach ($rawProposals as $rawP) {
    if ($setNo >= MAX_PROPOSALS) {
      break;
    }
    if (!is_array($rawP)) {
      continue;
    }
    $rawGroups = $rawP['groups'] ?? null;
    if (!is_array($rawGroups)) {
      continue;
    }

    $groups = [];
    foreach ($rawGroups as $rawG) {
      if (count($groups) >= MAX_GROUPS) {
        break;
      }
      if (!is_array($rawG)) {
        continue;
      }
      $label = isset($rawG['group_label']) && is_string($rawG['group_label']) ? trim($rawG['group_label']) : '';
      $desc  = isset($rawG['description'])  && is_string($rawG['description'])  ? trim($rawG['description'])  : '';
      if ($label === '') {
        continue;   // 無名グループは捨てる
      }
      $label = mb_substr($label, 0, MAX_LABEL_LEN);
      $desc  = mb_substr($desc,  0, MAX_DESC_LEN);

      // node_ids は実在idのみ・重複除去（★AIが捏造した id はここで落ちる）
      $rawIds = $rawG['node_ids'] ?? [];
      $nodeIds = [];
      $seen = [];
      if (is_array($rawIds)) {
        foreach ($rawIds as $nid) {
          $nid = filter_var($nid, FILTER_VALIDATE_INT);
          if ($nid === false || !isset($validIds[$nid]) || isset($seen[$nid])) {
            continue;
          }
          $seen[$nid] = true;
          $nodeIds[]  = $nid;
        }
      }
      if (empty($nodeIds)) {
        continue;   // 実在ノードの割り当てが無いグループは捨てる
      }

      $groups[] = [
        'group_label' => $label,
        'description' => $desc,
        'node_ids'    => $nodeIds,
      ];
    }

    if (empty($groups)) {
      continue;   // 有効グループが無い案は捨てる
    }
    $setNo++;
    $out[] = [
      'proposal_set' => $setNo,   // ★案番号はサーバ側で 1..n に振り直す（AI申告値は使わない）
      'groups'       => $groups,
    ];
  }
  return $out;
}

// ------------------------------------------------------------
// callAzureOpenAI — Azure OpenAI Chat Completions を呼び、AIが返したJSONを連想配列で返す。
//   ★query_llm.php と同一実装（TLS検証ON・タイムアウト・上流本文は非転送・JSON限定）。
//   参考: https://learn.microsoft.com/en-us/azure/ai-foundry/openai/how-to/json-mode
// ------------------------------------------------------------
function callAzureOpenAI(array $llm, array $messages): ?array {
  // エンドポイントは「ホスト部分のみ」を使う（Foundryのプロジェクトパスが貼られても捨てる）。
  $parts  = parse_url((string)$llm['endpoint']);
  $scheme = $parts['scheme'] ?? 'https';
  $host   = $parts['host'] ?? '';
  if ($host === '') {
    error_log('groupings_llm: invalid endpoint (host not found).');
    return null;
  }
  $base = $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
  $url = $base
       . '/openai/deployments/' . rawurlencode((string)$llm['deployment'])
       . '/chat/completions?api-version=' . rawurlencode((string)$llm['api_version']);

  $payload = json_encode([
    'messages'        => $messages,
    'temperature'     => isset($llm['temperature']) ? (float)$llm['temperature'] : 0,
    'response_format' => ['type' => 'json_object'],   // JSON以外を返させない
  ], JSON_UNESCAPED_UNICODE);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
      'Content-Type: application/json',
      'api-key: ' . $llm['api_key'],   // ★このヘッダ以外にキーを出さない
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_TIMEOUT        => isset($llm['timeout']) ? (int)$llm['timeout'] : 30,
    CURLOPT_CONNECTTIMEOUT => 10,
  ]);
  $resBody = curl_exec($ch);
  $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlErr = curl_error($ch);
  curl_close($ch);

  if ($resBody === false) {
    error_log('groupings_llm: curl error: ' . $curlErr);
    return null;
  }
  if ($status < 200 || $status >= 300) {
    error_log('groupings_llm: Azure returned HTTP ' . $status);
    return null;
  }

  $decoded = json_decode($resBody, true);
  $content = $decoded['choices'][0]['message']['content'] ?? null;
  if (!is_string($content)) {
    error_log('groupings_llm: unexpected Azure response shape.');
    return null;
  }
  $groupingJson = json_decode($content, true);
  if (!is_array($groupingJson)) {
    error_log('groupings_llm: AI content was not valid JSON.');
    return null;
  }
  return $groupingJson;
}

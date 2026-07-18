<?php
// ============================================================
// query_llm.php — 自然言語入力を Azure OpenAI で「クエリJSON」に翻訳するAPI
//   出典: idea.md §8（AI連携）・§11 ステップ7 / Issue #7
//
//   設計方針（CLAUDE.md §5/§6・最重要）:
//     - AIの役割は「自然言語 → 固定スキーマのクエリJSON」への"翻訳"のみ（ADR-002）。
//       新しい事実・エンティティ・関係は絶対に生成させない。
//     - AIに直接SQLを書かせない。AIが出せるのは action と params だけ（実行は execute_query→QueryBuilder）。
//     - AIには「DBに実在する edge_type の一覧」だけを選択肢として渡す（実在しない値を選ばせない）。
//     - ★多層防御: AIの出力は必ずサーバ側で再検証する。
//         - action は "filter_edges" 固定（AIが別のものを言っても採用しない）
//         - edge_type は実在リストに無ければ null に丸める（ハルシネーション/プロンプトインジェクション対策）
//         - work_id はAIの言い分を信用せず、サーバが解決した値で上書きする
//
//   セキュリティ（idea.md §10）:
//     - APIキーは config/llm.php のみ（.gitignore済）。レスポンス・ログに一切出さない。
//     - 要ログイン(401 JSON)・POST限定・入力文字数上限（コスト/濫用対策）。
//     - 上流APIのエラー本文はクライアントに転送しない（情報漏えい防止）。汎用エラーのみ返す。
//     - Azure呼び出しは TLS 検証ON・タイムアウトあり。
//
//   リクエスト（JSONボディ）: {"text":"恋慕の関係だけ見せて", "work_id": 1}
//   レスポンス（クエリJSON）: {"action":"filter_edges","params":{"work_id":1,"edge_type":"恋慕"}}
//     → フロントはこの本文をそのまま execute_query.php に POST して再描画する。
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

require_once __DIR__ . '/../lib/QueryBuilder.php';

try {
  // --- 3. リクエストボディ（自然言語＋work_id）を安全にパース ---
  $raw = file_get_contents('php://input', false, null, 0, 8 * 1024);   // 最大8KB
  $body = ($raw === false || $raw === '') ? null : json_decode($raw, true);
  if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_body'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // 自然言語テキスト（必須・空不可・長すぎる入力は弾く＝コスト/濫用対策）
  $text = $body['text'] ?? '';
  if (!is_string($text)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_text'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $text = trim($text);
  if ($text === '') {
    http_response_code(400);
    echo json_encode(['error' => 'empty_text'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if (mb_strlen($text) > 200) {
    http_response_code(400);
    echo json_encode(['error' => 'text_too_long'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // --- 4. work_id をサーバ側で解決（AIには決めさせない） ---
  $pdo = require __DIR__ . '/../config/db.php';
  $qb  = new QueryBuilder($pdo);

  $workId = filter_var($body['work_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  if ($workId === false) {
    // graph_data.php と同じく最新作品にフォールバック（GraphRepository を使う）
    require_once __DIR__ . '/../lib/GraphRepository.php';
    $workId = (new GraphRepository($pdo))->latestWorkId();
  }
  if ($workId === null) {
    // 作品が1件も無い＝絞り込みようがない。全体表示相当のクエリを返す。
    echo json_encode(
      ['action' => 'filter_edges', 'params' => ['work_id' => null, 'edge_type' => null]],
      JSON_UNESCAPED_UNICODE
    );
    exit;
  }

  // --- 5. AIに渡す「実在する edge_type の選択肢」を取得（ADR-002） ---
  $allowedTypes = $qb->distinctEdgeTypes($workId);   // 例: ['友人','師事','恋慕', ...]

  // --- 6. 設定読込。プレースホルダのままなら設定エラーを明示（誤送信防止） ---
  $llm = require __DIR__ . '/../config/llm.php';
  if (!is_array($llm)
      || empty($llm['endpoint']) || empty($llm['api_key']) || empty($llm['deployment'])
      || strpos((string)$llm['api_key'], 'YOUR_') === 0
      || strpos((string)$llm['endpoint'], 'YOUR_') !== false) {
    // config/llm.php が未設定（見本のまま）。上流に投げず、設定を促すエラーを返す。
    error_log('query_llm: config/llm.php is not configured (placeholder values).');
    http_response_code(503);
    echo json_encode(['error' => 'llm_not_configured'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // --- 7. Azure OpenAI 呼び出し（自然言語 → クエリJSON） ---
  $aiJson = callAzureOpenAI($llm, buildMessages($text, $allowedTypes));
  if ($aiJson === null) {
    // 上流エラー（詳細は error_log 済み）。クライアントには汎用エラーのみ。
    http_response_code(502);
    echo json_encode(['error' => 'llm_error'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // --- 8. AI出力をサーバ側で再検証して、安全なクエリJSONに正規化（多層防御） ---
  //   - action は filter_edges 固定
  //   - edge_type は実在リストに在るものだけ採用。無ければ null（＝全体表示）に丸める
  //   - work_id はサーバ解決値で上書き（AIの言い分は使わない）
  $aiEdgeType = null;
  if (isset($aiJson['params']) && is_array($aiJson['params'])) {
    $cand = $aiJson['params']['edge_type'] ?? null;
    if (is_string($cand) && in_array($cand, $allowedTypes, true)) {
      $aiEdgeType = $cand;
    } elseif (is_string($cand) && $cand !== '') {
      // 実在しない種別をAIが返した＝ハルシネーション。採用せず記録のみ（本体データは汚さない）。
      error_log('query_llm: AI returned unknown edge_type; coerced to null.');
    }
  }

  echo json_encode(
    ['action' => 'filter_edges', 'params' => ['work_id' => $workId, 'edge_type' => $aiEdgeType]],
    JSON_UNESCAPED_UNICODE
  );
} catch (Throwable $ex) {
  // 予期せぬ失敗。詳細は返さず500 JSONのみ（キー等が漏れないように）。
  error_log('query_llm: ' . $ex->getMessage());
  http_response_code(500);
  echo json_encode(['error' => 'internal_error'], JSON_UNESCAPED_UNICODE);
  exit;
}

// ============================================================
// 以下ヘルパー（この画面専用）
// ============================================================

// ------------------------------------------------------------
// buildMessages — Azure OpenAI に送る messages 配列を組み立てる。
//   ★プロンプトの肝（idea.md §8.2）:
//     - 出力は指定スキーマのJSONのみ（前置き・```なし。json_object モードでも明示する）
//     - edge_type は「渡した一覧」からのみ選ぶ。無ければ null
//     - 新しい事実・エンティティ・関係を創作しない
//   原文は一切渡さない。渡すのは「関係種別の一覧」だけ（ADR-002/原則4）。
// ------------------------------------------------------------
function buildMessages(string $text, array $allowedTypes): array {
  // 選択肢はJSON配列の文字列にして曖昧さをなくす
  $typesJson = json_encode(array_values($allowedTypes), JSON_UNESCAPED_UNICODE);

  $system =
    "あなたは、日本語小説の関係グラフを絞り込むための『クエリ翻訳器』です。\n" .
    "ユーザーの自然言語による指示を、下記スキーマの JSON にのみ翻訳してください。\n" .
    "\n" .
    "【厳守事項】\n" .
    "1. 出力は JSON オブジェクトのみ。前置き・説明・コードフェンス(```)を一切含めない。\n" .
    "2. action は必ず \"filter_edges\" とする。他の値は禁止。\n" .
    "3. params.edge_type は、次の『関係種別リスト』の中の値を1つだけ選ぶ。該当が無ければ null。\n" .
    "   関係種別リスト: {$typesJson}\n" .
    "4. リストに無い関係種別・存在しない登場人物・新しい事実を絶対に創作しない。\n" .
    "5. 「全部」「全体」「すべて」など種別を絞らない指示のときは edge_type を null にする。\n" .
    "\n" .
    "【出力スキーマ】\n" .
    "{ \"action\": \"filter_edges\", \"params\": { \"edge_type\": <リスト内の文字列 または null> } }";

  return [
    ['role' => 'system', 'content' => $system],
    ['role' => 'user',   'content' => $text],
  ];
}

// ------------------------------------------------------------
// callAzureOpenAI — Azure OpenAI Chat Completions を呼び、AIが返したJSONを連想配列で返す。
//   失敗時（通信エラー・非200・JSON不正）は null を返す（詳細は error_log に記録）。
//   ★セキュリティ: TLS検証ON・タイムアウトあり・上流本文はクライアントに出さない。
//   参考: https://learn.microsoft.com/en-us/azure/ai-foundry/openai/how-to/json-mode
// ------------------------------------------------------------
function callAzureOpenAI(array $llm, array $messages): ?array {
  // エンドポイントURL（デプロイ名・api-version はURLエンコードして組み立てる）
  $endpoint = rtrim((string)$llm['endpoint'], '/');
  $url = $endpoint
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
    CURLOPT_SSL_VERIFYPEER => true,    // 証明書を必ず検証（中間者攻撃対策）
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_TIMEOUT        => isset($llm['timeout']) ? (int)$llm['timeout'] : 20,
    CURLOPT_CONNECTTIMEOUT => 10,
  ]);
  $resBody = curl_exec($ch);
  $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlErr = curl_error($ch);
  curl_close($ch);

  if ($resBody === false) {
    error_log('query_llm: curl error: ' . $curlErr);
    return null;
  }
  if ($status < 200 || $status >= 300) {
    // 上流のエラー本文はクライアントに転送しない（キー・内部情報漏えい防止）。ログのみ。
    error_log('query_llm: Azure returned HTTP ' . $status);
    return null;
  }

  // Chat Completions のレスポンス → choices[0].message.content（これがAI生成のJSON文字列）
  $decoded = json_decode($resBody, true);
  $content = $decoded['choices'][0]['message']['content'] ?? null;
  if (!is_string($content)) {
    error_log('query_llm: unexpected Azure response shape.');
    return null;
  }

  // ★content 自体を安全にJSONパース（try/catch相当。失敗しても例外にしない）
  $queryJson = json_decode($content, true);
  if (!is_array($queryJson)) {
    error_log('query_llm: AI content was not valid JSON.');
    return null;
  }
  return $queryJson;
}

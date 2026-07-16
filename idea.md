# 青空文庫テキストマイニング・関係性可視化ツール 仕様書（idea.md）

> **このドキュメントを読むあなた（Claude Code）へ**
> あなたはこのプロジェクトの開発担当者です。このプロジェクトの発注者（以下「ユーザー」）は
> 専門学校でPHPの基礎（フォーム処理・PDOによるMySQLのCRUD・セッションによるログイン）を
> 学習した学生であり、あなたに前提知識の共有はされていません。
> **このドキュメント単体で実装の全体像が分かるように書いてあります。**
> 不明点や設計判断が必要な箇所は、勝手に進めず必ずユーザーに確認してください。
> **重要な作業ルール：一度に着手するのは1機能（1 issue）まで。全部を一気に実装しないこと。**
> 実装の前に必ずプラン（何をどの順で作るか）を提示し、ユーザーの承認を得てからコードを書くこと。

---

## 0. このアプリは何か（一言で）

青空文庫などの著作権フリーの日本語小説を対象に、**「どの単語・登場人物が、どう関係し合っているか」を統計的に解析し、その関係をネットワーク図（点と線）で可視化する研究用ツール**です。

論文・記事への引用を想定しているため、**最優先事項は「解析結果の正確性・再現性・検証可能性」**です。「AIがそれっぽく答える」ことは目的ではなく、むしろ厳しく避けます（後述する設計原則を厳守してください）。

---

## 1. 全体像を先にイメージする（初見でも分かるように）

このアプリは、大きく **2つの世界** に分かれています。ここを最初に理解してください。

### 世界A：解析パイプライン（Python・ローカル実行のみ）

小説のテキストファイルを **形態素解析**（＝文章を単語に分解し、品詞や係り受けを判定する処理）し、
「単語（ノード）」と「単語同士の関係（エッジ）」を抽出して、**JSONファイルとして出力**する部分です。

- 使う言語：**Python**（GiNZA / spaCy というライブラリを使う）
- 実行する場所：**ユーザーのローカルPC（手元のPC）のターミナルのみ**
- **この部分はWebサーバー（Sakura Server）には絶対にアップロードしません**（理由は後述）
- リポジトリの中には含めます（Git管理はする。ただしデプロイはしない）

### 世界B：Webアプリ（PHP + MySQL・サーバーで動く）

世界Aが吐き出したJSONを **MySQLに取り込み**、ブラウザ上で **ネットワーク図として可視化** し、
**自然言語で「恋愛関係だけ見せて」等と指示するとグラフを絞り込める** 部分です。

- 使う言語：**PHP 8.x + MySQL**（ユーザーが学校で学んだCRUDパターンをそのまま踏襲する）
- 実行する場所：**Sakura Server（さくらのレンタルサーバー）**
- ログイン認証つき（後述。ユーザーが直近で学習したセッション認証を使う）

### 2つの世界のつながり（データの流れ）

```
【世界A：ローカルPC・Python】
  小説.txt
    ↓ analyze.py（GiNZA で形態素解析・関係抽出）
  nodes.json / edges.json（← エビデンス付き。ここまでが世界A）
    │
    │ ★この JSON ファイルだけを Sakura にアップロード（FTP）
    ↓
【世界B：Sakura Server・PHP + MySQL】
  import.php（CLI実行）で JSON を MySQL に取り込む
    ↓
  MySQL（＝唯一の「事実」を保持するデータストア）
    ↓                                      ↑
  PHP API 層（グラフ取得・クエリ実行）      Azure OpenAI（GPT）
    ↓                                      ↑ 自然言語→クエリJSON変換のみ
  ブラウザ（HTML/JS + Cytoscape.js でネットワーク図を描画）
```

---

## 2. 設計原則（最重要・絶対厳守）

このアプリの存在意義に関わる部分です。**ここを破る実装は、たとえ動いても採用しません。**

### 原則1：解析とAIを完全に分離する

- **解析層（＝事実）**：形態素解析・係り受け解析・共起統計など、**決定論的な手法のみ**を使う。
  同じ入力からは必ず同じ出力が出る（＝再現性がある）。**ここにAI（LLM）は一切関与させない。**
- **AI層（＝操作の翻訳）**：AI（Azure OpenAIのGPT）は「ユーザーの自然言語による指示」を
  「あらかじめ決められた形式のクエリJSON」に**翻訳するだけ**。
  **新しい事実（存在しない関係やエンティティ）を生成させることは絶対にしない。**

### 原則2：すべてのエッジ（関係）にエビデンスを持たせる

「太郎」と「花子」に関係がある、と判定したなら、
「原文の何文目・どの位置からその関係を抽出したのか」を必ず記録し、原文に遡って検証できるようにする。
これが研究・論文用途では生命線です。

### 原則3：Provenance（出所）を視覚的に分離する

- **一次データ**（統計的に抽出された確実な関係）→ グラフ上で **実線** で描画
- **二次データ**（AIによる解釈的なグルーピング）→ グラフ上で **破線 or 別色** で描画
- 論文引用時は一次データのみを「事実」として扱えるようにする。
- 凡例（レジェンド）に必ず「実線＝統計的抽出／破線＝AI解釈」を明示し、混同を防ぐ。

### 原則4：AIの役割は「分類・翻訳」に限定する

- AIに生テキストを読ませて「関係性を答えさせる」ことは**しない**。
- すでに確定したノード・エッジのリスト（ラベルと関係のみ。原文は渡さない）だけを見せて、
  「フィルタ条件を組み立てる」「テーマごとに分類する」といった作業だけをさせる。

> **なぜここまで厳格なのか（背景）**：
> AIが間違えること自体より、「間違えたかどうかが後から検証できない状態」が最も危険だからです。
> このアプリは「AIが本体データを書き換える経路をそもそも作らない」ことで、
> AIがどれだけ自由に提案しても本体（事実）が汚染されない構造にします。

---

## 3. スコープ（今回作る範囲 / 作らない範囲）

### v1で作る範囲（今回の実装対象）

1. 解析済みJSON（nodes.json / edges.json）をMySQLにインポートするPHPスクリプト（CLI実行）
2. **ログイン認証**（セッションベース。ログインしないと以下の画面は見られない）
3. ノード・エッジ・エビデンスを閲覧するPHP + MySQL のCRUD一式
   （ユーザーが学校で学んだ index / insert / select / detail / update / delete のパターンを踏襲）
4. グラフ探索用API（全体取得、N-hop取得、フィルタ検索）
5. フロントエンドでのネットワーク可視化（Cytoscape.js）
6. 自然言語入力 → Azure OpenAI でクエリJSON生成 → 既存グラフへクエリ実行 → 再描画
7. AIによるテーマ別グルーピング（複数案の提示・別レイヤー描画）

### v1では作らない（将来検討）

- **形態素解析パイプラインそのもの**（Pythonで別途オフライン実行する前提。世界Aのスクリプトは
  リポジトリに含めるが、その中身の高度化は本仕様のスコープ外。まずはダミーJSONでも動くようにする）
- 複数ユーザーの権限管理（ログイン機能は付けるが、単一の利用者を想定。管理者/一般の区別は最小限）
- pgvector等による意味的クラスタリングなどの高度な機能
- リアルタイム協働編集
- React化（v1は素のHTML/CSS/JS。React化はv2以降で検討）

---

## 4. 技術スタック

| 領域 | 採用技術 | 補足 |
|---|---|---|
| 解析（世界A） | Python 3.x + GiNZA / spaCy | ローカル実行のみ。Sakuraにはデプロイしない |
| バックエンド（世界B） | PHP 8.x + PDO | **prepared statements 必須**。直接文字列結合でSQLを組まない |
| DB | MySQL | **バージョン要確認**（下記の注意を参照） |
| フロントエンド | 素のHTML/CSS/JavaScript + Cytoscape.js | Cytoscape.jsはCDN読み込みでOK（ビルド不要） |
| AI | **Azure OpenAI（GPTモデル）** | 「自然言語→クエリJSON変換」専用。JSON限定出力を強制する |
| ホスティング | Sakura Server（FTP / FileZilla） | DB接続情報・APIキーは設定ファイルに分離し `.gitignore` 対象 |
| ローカル開発 | XAMPP | PHP/MySQLのローカル動作確認用 |

> **【実装前に必ず確認すること】MySQLのバージョン**
> MySQLが **8.0以上か5.7系か** で、N-hop探索（何ホップ先まで辿るか）のクエリの
> 書き方が変わります。
> - 8.0以上 → **再帰CTE**（`WITH RECURSIVE`）でN-hop探索を書ける
> - 5.7系 → 再帰CTEが使えないので、**PHP側でループ処理**してN-hopを辿る実装にフォールバックする
>
> **現時点で判明していること**：
> - ローカル開発環境（XAMPP）は **MySQL 8.2.4** → 再帰CTEが使える。ローカルでの開発・検証は
>   `WITH RECURSIVE` を使った実装で進めてよい。
> - **本番のSakura ServerのMySQLバージョンは未確認**。デプロイ前に必ず確認すること。
>   もしSakura側が5.7系だった場合、ローカルで動いた再帰CTEのクエリが本番で動かないため、
>   その時点でPHP側ループへのフォールバックを検討する（ユーザーに確認する）。

> **【重要】ライブラリのバージョン確認**
> Cytoscape.js等のライブラリを使う際は、Context7等で**最新の安定版**を確認し、
> プレリリース版（beta/rc等）を誤って指定していないかチェックしてください。

---

## 5. ディレクトリ構成

**この構成が「世界A（デプロイしない）」と「世界B（デプロイする）」の分離を体現しています。**

```
📁 プロジェクトルート（Gitリポジトリ）
│
├── 📁 pipeline/                 ← 【世界A】Python解析。ローカル実行のみ。Sakuraには上げない
│   ├── analyze.py               形態素解析→JSON出力（v1ではダミー生成でもOK）
│   ├── requirements.txt         GiNZA等の依存
│   └── output/
│       ├── nodes.json           ← 解析成果物。これだけSakuraにアップしてPHPが読む
│       └── edges.json
│
├── 📁 public/                   ← 【世界B】Sakuraにデプロイするのはこのフォルダの中身
│   ├── login.php                ログインフォーム（画面）
│   ├── login_act.php            ログイン認証処理
│   ├── logout.php               ログアウト処理
│   ├── functions.php            共通関数（loginCheck() などをここに置く）
│   ├── index.php                グラフ全体を表示するトップページ（要ログイン）
│   ├── import.php               JSONをMySQLに取り込むスクリプト（CLI実行専用）
│   ├── graph_data.php           ノード・エッジをJSONで返すAPI（初期表示用）
│   ├── node_detail.php          特定ノードの詳細（隣接ノード・エビデンス一覧）を返すAPI
│   ├── query_llm.php            自然言語入力を受け取りAzure OpenAIでクエリJSONを生成
│   ├── execute_query.php        クエリJSONを受け取りDBに対して実行し結果をJSONで返す
│   └── assets/                  CSS・JS・Cytoscape関連
│
├── 📁 config/                   ← 秘密情報。Gitにも含めない（.gitignore対象）
│   ├── db.php                   DB接続設定
│   └── llm.php                  Azure OpenAIのエンドポイント・APIキー設定
│
├── 📁 lib/                      ← 【世界B】PHPのクラス群（Sakuraにデプロイする）
│   ├── QueryBuilder.php         AI生成のクエリJSONを安全なprepared statementに変換
│   └── GraphRepository.php      ノード・エッジ・エビデンスのCRUDをまとめるクラス
│
├── 📁 sql/
│   └── schema.sql               CREATE TABLE 一式（下記6節）
│
├── .gitignore                   config/ を必ず含める
├── CLAUDE.md                    プロジェクトの憲法（技術標準・作業ルール）
└── README.md                    人間向けドキュメント
```

> **デプロイのルール（重要）**
> - Sakura（FTP）にアップロードするのは **`public/` の中身 と `config/`（本番用の値に差し替えたもの）と `lib/`** のみ。
> - **`pipeline/`（Python一式）はアップロードしない。** ただしGitリポジトリには含める。
> - `.gitignore` には `config/` を入れる（DB接続情報・APIキーをGitHubに上げないため）。
>   `pipeline/` は「Gitには含めたいがSakuraには上げない」ので、`.gitignore`には**入れない**。
>   デプロイ対象の切り分けは「FTPで public/ 以下だけを上げる」という運用で担保する。
> - 秘密情報のスキャンには gitleaks を使い、`config/` の中身が誤ってコミットされていないか検査する。

---

## 6. データベース設計

以下のテーブルを作成します。`sql/schema.sql` にまとめてください。

> **設計の考え方**：`nodes` / `edges` / `evidence` が「事実」を保持する本体テーブルです。
> `llm_groupings` は「AIの解釈」を保持する**物理的に独立したテーブル**で、本体とは決して混ぜません。
> これが原則3（Provenanceの分離）をDBレベルで担保する仕組みです。

### 6.1 users（ログイン用）

ユーザーが学校で使ったテストユーザー構成（login_id / login_pw / name / is_admin）を踏襲します。

```sql
CREATE TABLE users (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  login_id  VARCHAR(255) NOT NULL UNIQUE,
  login_pw  VARCHAR(255) NOT NULL,           -- v1.5でpassword_hash()化を検討（下記10節）
  name      VARCHAR(255) NOT NULL,
  is_admin  TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### 6.2 works（作品）

```sql
CREATE TABLE works (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  title     VARCHAR(255) NOT NULL,           -- 例：「こころ」
  author    VARCHAR(255),                    -- 例：「夏目漱石」
  source    VARCHAR(255),                    -- 例：「青空文庫」＋URL等
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### 6.3 nodes（ノード＝単語・登場人物など）

```sql
CREATE TABLE nodes (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  work_id   INT NOT NULL,
  label     VARCHAR(255) NOT NULL,           -- 例：「太郎」
  node_type VARCHAR(64),                     -- 例：person / place / term など
  frequency INT DEFAULT 0,                   -- 出現頻度
  FOREIGN KEY (work_id) REFERENCES works(id)
);
```

### 6.4 edges（エッジ＝関係。すべて「事実」）

```sql
CREATE TABLE edges (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  work_id       INT NOT NULL,
  source_node_id INT NOT NULL,               -- 関係の起点ノード
  target_node_id INT NOT NULL,               -- 関係の終点ノード
  edge_type     VARCHAR(64),                 -- 例：共起 / 敵対 / 頼る など
  weight        DECIMAL(6,4) DEFAULT 0,      -- 関係の強さ（PMI・Dice係数など）
  method        VARCHAR(64),                 -- 抽出手法（co_occurrence / dependency_parse など）
  FOREIGN KEY (work_id) REFERENCES works(id),
  FOREIGN KEY (source_node_id) REFERENCES nodes(id),
  FOREIGN KEY (target_node_id) REFERENCES nodes(id)
);
```

### 6.5 evidence（エビデンス＝各エッジの原文根拠）

```sql
CREATE TABLE evidence (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  edge_id      INT NOT NULL,
  sentence_id  INT,                          -- 原文の何文目か
  text_span_start INT,                        -- 抽出箇所の開始位置
  text_span_end   INT,                        -- 抽出箇所の終了位置
  sentence_text TEXT,                          -- 原文そのもの（検証用）
  FOREIGN KEY (edge_id) REFERENCES edges(id)
);
```

### 6.6 llm_groupings（AIによる解釈＝二次データ。本体と物理分離）

```sql
CREATE TABLE llm_groupings (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  work_id      INT NOT NULL,
  group_label  VARCHAR(255),                 -- AIが付けたテーマ名（例：「対立関係」）
  node_id      INT NOT NULL,                 -- そのグループに属するノード
  proposal_set INT DEFAULT 1,                -- 「案1」「案2」を区別する番号（複数案対応）
  description  TEXT,                          -- この案の分類基準の説明（AIが生成）
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (work_id) REFERENCES works(id),
  FOREIGN KEY (node_id) REFERENCES nodes(id)
);
```

---

## 7. ログイン認証の仕様（世界B）

ユーザーが直近の講義で学んだ**セッションベースのログイン**をそのまま採用します。
以下のパターンを踏襲してください。

### 7.1 認証の流れ

1. `login.php`：ログインフォーム（`action="login_act.php"` / `method="post"`）
2. `login_act.php`：
   - `session_start()`
   - `$_POST` で login_id / login_pw を取得
   - PDOで `users` テーブルを照合（prepared statement）
   - 一致すれば `$_SESSION['chk_ssid'] = session_id();` を保存して `index.php` へリダイレクト
   - 不一致なら `login.php` に戻す
3. ログイン必須ページ（index.php / graph_data.php / node_detail.php など）の冒頭で `loginCheck()` を呼ぶ
4. `logout.php`：`$_SESSION = [];` → Cookie削除 → `session_destroy()` → `login.php` へリダイレクト

### 7.2 functions.php に置く共通関数

```php
// ログインチェック＋セッションID更新（セッションハイジャック対策込み）
function loginCheck() {
  if (!isset($_SESSION['chk_ssid']) || $_SESSION['chk_ssid'] != session_id()) {
    exit('LOGIN ERROR');
  } else {
    session_regenerate_id(true);            // チェック通過ごとに鍵を新しくする
    $_SESSION['chk_ssid'] = session_id();
  }
}
```

各ページ冒頭での使い方：

```php
session_start();
require_once('functions.php');
loginCheck();
```

### 7.3 API系ファイルでの注意

`graph_data.php` などのAPI（JSONを返すファイル）も**ログイン必須**にします。
ただしAPIの場合、`exit('LOGIN ERROR')` のようなプレーンテキストではなく、
**401ステータス＋JSONエラー**（例：`{"error":"unauthorized"}`）を返す形にしてください。
フロント側（JS）がそのエラーを検知してログイン画面へ誘導できるようにするためです。

> **補足**：`session_regenerate_id(true)` は、このアプリのように**ページ遷移中心（同時並行の
> 非同期通信が少ない）構成**では毎回更新して問題ありません。ただしフロント側で複数のAPIを
> 同時並行で叩く画面（グラフ描画時に graph_data と node_detail を同時に叩く等）がある場合、
> 「片方が古いセッションIDのまま届いて弾かれる」競合が起きうるので注意してください。
> 実装時、初期描画で複数APIを**同時に**叩く設計にするなら、セッションID更新の頻度を
> 「ログイン時のみ」に緩めるか、初期ロードのAPI呼び出しを直列化するかを検討し、
> どちらにするかをユーザーに確認してください。

---

## 8. AI（Azure OpenAI）連携の仕様

### 8.1 基本方針

AIは「自然言語 → 固定スキーマのクエリJSON」への翻訳専用です。
**AIに自由なSQLを書かせることは絶対にしません**（SQLインジェクション・誤クエリのリスクを排除）。

### 8.2 Azure OpenAIの呼び出し（query_llm.php）

ユーザーはAzure OpenAIのAPI呼び出しに慣れています。以下の点を守ってください。

- エンドポイント・APIキー・デプロイ名（デプロイしたモデル名）は `config/llm.php` に分離する
- **JSON以外を出力させない**プロンプト設計にする（前置き・Markdownの```なども禁止し、純粋なJSONのみ）
- レスポンスは必ず「安全にJSONパース（try/catchで囲む）」してから使う
- プロンプトには以下を明示的に指示する：
  - 「入力されたノード・エッジのリストに存在しないものは絶対に出力しないこと」
  - 「新しい事実・エンティティ・関係性を創作しないこと」
  - 「出力は指定したスキーマのJSONのみとすること」

### 8.3 クエリJSONのスキーマ例（フィルタ操作）

```json
{
  "action": "filter_edges",
  "params": {
    "edge_type": "敵対",
    "center_node": "太郎",
    "max_depth": 2,
    "work_id": 1
  }
}
```

### 8.4 QueryBuilder.php の役割（超重要）

`QueryBuilder.php` は、AIが生成した上記JSONを受け取り、
**`action` と `params` をホワイトリスト方式で解釈して、対応する prepared statement を実行**します。

- 想定される `action`（filter_edges / get_neighbors など）だけを許可する
- 未知の `action` が来たら実行せずエラーにする
- `params` の各値は必ず bindValue でバインドする（文字列結合でSQLを組まない）
- **AIが直接SQLを書く・実行する経路は一切作らない**

### 8.5 テーマ別グルーピング（解釈的操作）のフロー

「テーマごとに並び替えて」のような曖昧な指示への対応です。

1. ユーザーが「テーマごとにグループ分けして」等と入力
2. PHPは対象ノード・エッジの**確定済みリストのみ**（ラベルと関係のみ。原文は渡さない）をAIに送る
3. AIは**複数の分類案（案1・案2…）**を返す。各案に「分類基準の説明」を添えさせる
   - 例：案1「登場人物の対立軸で分類」、案2「感情の起伏で分類」
   - **新しい関係の生成は禁止**（既存ノードを分類し直すだけ）
4. 結果は `llm_groupings` テーブルに `proposal_set`（案番号）付きで保存
   （`nodes`/`edges` とは物理的に別テーブル）
5. フロントは一次データ（edges）を**実線**、二次データ（llm_groupings）を**破線／別色**で描画
6. ユーザーは案を切り替えて表示できる。どの案も本体データには一切影響しない

> **ハルシネーション対策の要点（ユーザーの懸念への回答）**：
> 「1つの正解を断定させる」のではなく「複数の解釈候補を、事実とは区別できる形で提示する」ことで、
> AIの誤りが本体データを汚染しないようにします。AIがどの案を出そうと、それは常に「破線＝解釈」
> レイヤーに閉じ込められ、論文引用時は「実線＝事実」だけを使えばよい、という構造です。

---

## 9. フロントエンド仕様

- Cytoscape.js でノード・エッジを描画（CDN経由・ビルド不要）
- ノードをクリックすると `node_detail.php` を呼び出し、隣接ノードとエビデンス原文をサイドパネルに表示
- 自然言語入力欄（検索窓のようなUI）→ `query_llm.php` → `execute_query.php` の順に叩き、
  返ってきたノード・エッジ集合でグラフを再描画
- **凡例（レジェンド）に「実線＝統計的抽出／破線＝AI解釈」を必ず表示**し、誤解を防ぐ
- ログイン必須。未ログイン時にAPIが401を返したらログイン画面へ誘導する

---

## 10. セキュリティ・運用上の注意

- DB接続情報・Azure OpenAIのAPIキーは `config/db.php` `config/llm.php` に分離し `.gitignore` に追加
- すべてのSQLは prepared statements 経由のみ（AI生成JSON由来のものも含め、文字列結合禁止）
- AIへのプロンプトに「存在しない事実を創作しない」「入力リストにないものを出力しない」を明示
- `import.php` は **CLI実行専用**とし、Web経由で誰でも実行できないようにする
  （例：`php_sapi_name() === 'cli'` でないなら弾く。ローカル/管理者のみが実行）
- gitleaks で秘密情報の混入をスキャンする
- **（v1.5で検討）パスワードのハッシュ化**：v1では学習教材に合わせて平文照合でも可だが、
  本番運用に近づけるなら `password_hash()` で保存し `password_verify()` で照合する方式に移行する。
  その場合、照合SQLは `login_id` のみで1件取得し、PHP側で `password_verify()` する形になる。

---

## 11. 実装ステップ（Claude Codeでの進め方の目安）

**1ステップ＝1 issue** とし、各ステップ完了ごとにユーザーの確認を取ってから次へ進んでください。

1. `sql/schema.sql`（6節のCREATE TABLE一式）を作成し、ローカルのMySQLに適用できることを確認
2. サンプルの解析済みJSON（ダミーデータでOK）を用意し、`import.php`（CLI実行）でMySQLに取り込む
3. **ログイン認証**（login.php / login_act.php / logout.php / functions.php の loginCheck）を実装し、
   未ログインで index.php に直アクセスすると弾かれることを確認
4. `graph_data.php` で全体のノード・エッジをJSON出力（要ログイン。未認証時は401 JSON）
5. フロントエンドで Cytoscape.js による基本描画（まずは静的表示）
6. `node_detail.php` によるエビデンス表示（ノードクリック→サイドパネル）
7. `query_llm.php` + `QueryBuilder.php` による自然言語フィルタリング
   （まずは `filter_edges` の1アクションのみ）
8. `llm_groupings` によるテーマ別グルーピング（複数案）と、Provenance別スタイリング（実線/破線）
9. アクション（クエリJSONのスキーマ）を必要に応じて拡張

---

## 12. Claude Codeへの作業上のお願い（まとめ）

- **一度に1 issueだけ**着手する。全機能を一気に書かない。
- 実装前に必ず**プランを提示**し、ユーザーの承認を得てからコードを書く。
- **設計判断が必要な箇所**（MySQLバージョン、セッションID更新頻度など）は勝手に決めず確認する。
- ライブラリのバージョンは Context7 等で確認し、プレリリース版を避ける。
- 秘密情報（config/）を絶対にコミットしない。gitleaksでスキャンする。
- 設計原則（2節）は何があっても守る。「AIが本体データを生成・書き換える」経路は作らない。

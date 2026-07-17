# CLAUDE.md — novel-visualizer プロジェクトの憲法

> **このファイルについて**
> CLAUDE.md はAIエージェント（Claude Code）への「行動契約書」であり、README.md とは別物。
> セッション開始時に自動読み込みされ、全命令の最上位に位置する。
> 詳細な機能仕様は `idea.md`（全体仕様書）と `specs/` 配下に置き、必要時に参照する。
> **このファイルは安易に改変しない**。重要な方針変更時のみ更新し、更新ログに残す。

---

## 0. 成果物の役割分担（迷ったらここに戻る）

> **実装時の作業セット（最重要）**：作業内容と今後の進行は、
> **① 着手中の GitHub Issue ／ ② この CLAUDE.md ／ ③ git log** の3つだけを見れば分かるようにする。
> この3つに含まれない申し送りを別ファイルに散らさない。各 Issue は単体で完結させ、
> 積み残しは次 Issue の「参考/メモ」へ、確定した方針は CLAUDE.md へ、進捗は commit（先頭に `#issue番号`）へ集約する。

| 成果物 | 役割 | 実装時に毎回見るか | 更新頻度 |
|---|---|---|---|
| **GitHub Issue** | 機能単位の作業単位。目的/やること/完了条件/参考・メモ。**着手中の作業の一次情報** | ✅ 毎回 | 機能ごとに作成・close |
| **CLAUDE.md** | 技術標準・設計原則・Git規約・禁止事項・参照URL。**プロジェクトの憲法** | ✅ 毎回（自動読込） | 開始時に確定、重要な方針変更時のみ |
| **git log** | 現在地・完了済みの履歴。commit 先頭に `#issue番号` を含める規約 | ✅ 毎回 | 都度 |
| GitHub Milestone | フェーズ管理（= idea.md §11 の実装ステップ）。Issue を紐付ける | 進行確認時 | フェーズ開始時 |
| `idea.md` | 発注者が書いた全体仕様書（DB設計・各画面仕様の詳細）。**Issue から必要箇所を参照する仕様の出典** | 該当 Issue の実装時のみ | 仕様変更時のみ |
| `specs/` | 機能単位の詳細仕様（SDD用）。idea.md で足りない詳細が要る時だけ作成 | 該当機能の実装時のみ | 必要時のみ |
| `README.md` | **人間がプロジェクト内容を確認するための資料**。概要・セットアップ・使い方・デプロイ運用。<br>AIが開発中に都度参照する前提では書かない（開発の一次情報は上の3つ） | ❌（人間向け） | 機能追加時に随時 |
| メモリ | プロジェクトを超えて持ち越す個人の傾向（日本語希望・学習中など）。**技術方針はここに置かず本ファイルへ** | — | 傾向が変わった時のみ |

> キックオフ用の使い捨てテンプレート（`ai_dev_kickoff_template.md` / `CLAUDE_MD_TEMPLATE.md`）は
> 役割を本ファイル・README・issueテンプレートに移し終えたため、リポジトリには残さない。

---

## 1. プロジェクト概要（Project Overview）

```
プロジェクト名: novel-visualizer（青空文庫テキストマイニング・関係性可視化ツール）
目的: 著作権フリーの日本語小説を統計的に解析し、単語・登場人物の関係を
      ネットワーク図で可視化する研究用ツール。論文・記事への引用を想定。
対象ユーザー: 研究用途の単一利用者（発注者本人）。PHP基礎を学んだ学生。
最優先事項: 解析結果の「正確性・再現性・検証可能性」。AIが"それっぽく答える"ことは目的にしない。
ステータス: 開発中（v1）
```

このアプリは **2つの世界** に分かれる（詳細は idea.md §1）。

- **世界A（解析パイプライン）**: Python + GiNZA。ローカルPCでのみ実行。JSONを出力。**Sakuraには上げない**。
- **世界B（Webアプリ）**: PHP + MySQL + Cytoscape.js。Sakura Server で動く。世界Aが吐いたJSONを取り込み可視化する。

---

## 2. 技術スタック（Tech Stack）

```
解析（世界A）  : Python 3.x + GiNZA / spaCy   … ローカル実行のみ・Sakuraにデプロイしない
バックエンド    : PHP 8.x + PDO（prepared statements 必須。文字列結合でSQLを組まない）
DB            : MySQL系 … ローカル(XAMPP)=★MariaDB 10.4.28（再帰CTE可） / 本番Sakura=★未確認（下記注意）
フロントエンド  : 素のHTML/CSS/JavaScript + Cytoscape.js 3.x（CDN読込・ビルド不要）
AI           : Azure OpenAI（GPTモデル）… 「自然言語→クエリJSON変換」専用・JSON限定出力を強制
ホスティング    : Sakura Server（FTP / FileZilla）
ローカル開発    : XAMPP（PHP / MySQL のローカル動作確認）
パッケージ管理  : なし（v1は素のPHP。Composer不採用 → ADR-007）
```

> **★実装前・デプロイ前に必ず確認：DBバージョン**
> N-hop探索クエリの書き方がバージョンで変わる。
> - MySQL 8.0以上 / MariaDB 10.2.2以上 → `WITH RECURSIVE`（再帰CTE）で書ける
> - MySQL 5.7系 → 再帰CTE不可。**PHP側ループにフォールバック**する
> **ローカル(XAMPP)は MariaDB 10.4.28 と判明**（idea.md §4 の「MySQL 8.2.4」は誤り）。再帰CTEは使えるので
> ローカル開発は `WITH RECURSIVE` で進めてよい。ただし **MariaDB には MySQL 8 専用の照合順序
> `utf8mb4_0900_ai_ci` が無い**ため、DBの照合順序は両対応の **`utf8mb4_unicode_ci`** を使う。
> 本番Sakuraのバージョンは未確認。**デプロイ前に必ず確認し、5.7系なら実装方針をユーザーに相談すること。**

---

## 3. ディレクトリ構造（Project Structure）

「世界A（デプロイしない）」と「世界B（デプロイする）」の分離を構造で体現する（idea.md §5）。

```
novel-visualizer/
├── CLAUDE.md                    # このファイル（憲法）
├── idea.md                      # 全体仕様書（一次情報）
├── README.md                    # 人間向けドキュメント
├── .gitignore                   # config/ を除外（pipeline/ は除外しない）
├── .gitleaks.toml               # secret スキャン設定
│
├── pipeline/                    # 【世界A】Python解析。ローカル実行のみ。Sakuraには上げない
│   ├── analyze.py               #   形態素解析→JSON出力（v1はダミー生成でも可）
│   ├── requirements.txt
│   └── output/                  #   nodes.json / edges.json（★これだけSakuraにアップ）
│
├── public/                      # 【世界B】Sakuraにデプロイするのはこのフォルダの中身
│   ├── login.php login_act.php logout.php functions.php
│   ├── index.php                #   グラフ全体表示（要ログイン）
│   ├── import.php               #   JSON→MySQL取り込み（CLI実行専用）
│   ├── graph_data.php node_detail.php   # グラフ取得API（要ログイン・未認証は401 JSON）
│   ├── query_llm.php execute_query.php  # 自然言語→クエリJSON→実行
│   └── assets/                  #   CSS / JS / Cytoscape関連
│
├── config/                      # 秘密情報。Gitに含めない（.gitignore対象）
│   ├── db.php                   #   DB接続設定
│   └── llm.php                  #   Azure OpenAI エンドポイント・APIキー
│
├── lib/                         # 【世界B】PHPクラス群（Sakuraにデプロイする）
│   ├── QueryBuilder.php         #   AI生成クエリJSON→安全な prepared statement に変換
│   └── GraphRepository.php      #   ノード・エッジ・エビデンスのCRUD
│
├── sql/schema.sql               # CREATE TABLE 一式（idea.md §6）
├── specs/                       # 機能仕様書（SDD用。実装前にここへ書く）
├── docs/                        # 詳細設計を置く場所（肥大化防止のため無闇に増やさない）
└── .github/
    ├── ISSUE_TEMPLATE/feature.md
    └── workflows/gitleaks.yml
```

> **デプロイのルール**：FTPで上げるのは `public/` の中身・`config/`（本番値）・`lib/` のみ。
> **`pipeline/` はアップロードしない**が、Gitリポジトリには含める（`.gitignore` に入れない）。

---

## 4. コーディング規約（Coding Standards）

> **命名の方針（重要）**：一般的な2026デファクト（kebab-case/PSR-12/Composer）よりも、
> **発注者が学校で学んだ素のPHPパターンを優先する**。学習教材との整合と Sakura 運用の簡素さのため。

### 命名規則

| 対象 | 規則 | 例 |
|------|------|-----|
| PHPファイル | snake_case（学校パターン踏襲） | `login_act.php` `graph_data.php` |
| PHPクラス | PascalCase | `QueryBuilder` `GraphRepository` |
| 関数・変数 | camelCase | `loginCheck()` `$workId` |
| DBテーブル・カラム | snake_case | `llm_groupings` `source_node_id` |
| JSONキー | snake_case（DBと揃える） | `edge_type` `max_depth` |

### コードスタイル

- インデント: PHP/JS = 2スペース、SQL = 2スペース
- SQL: **prepared statements 経由のみ**。値は必ず bindValue/bindParam。**文字列結合でSQLを組まない**
- コメント言語: 日本語
- フォーマッター/リンター: v1では必須化しない（`php -l` の構文チェックは行う）

---

## 5. アーキテクチャ判断（Architecture Decisions）

> idea.md §2 の設計原則を ADR として固定する。**これを破る実装は動いても採用しない。**

- **ADR-001 世界A/世界Bの物理分離**: Python解析（世界A）は `pipeline/` に隔離し、ローカル実行のみ。
  Sakuraにはデプロイしない。両者の受け渡しは JSON ファイルのみ。
- **ADR-002 解析とAIの完全分離**: 解析層（=事実）は形態素解析・共起統計など**決定論的手法のみ**（同入力→同出力）。
  AI層は「自然言語→固定スキーマのクエリJSON」への**翻訳のみ**。**AIに新しい事実・関係・エンティティを生成させない。**
- **ADR-003 全エッジにエビデンス**: 各エッジ（関係）は「原文の何文目・どの位置から抽出したか」を
  `evidence` テーブルに保持し、原文へ遡って検証できるようにする。
- **ADR-004 Provenance（出所）の物理分離**: 一次データ = `nodes`/`edges`（統計抽出＝事実）、
  二次データ = `llm_groupings`（AI解釈）を**別テーブルで管理**。フロントでは一次=実線、二次=破線/別色で描画し、
  凡例に「実線＝統計的抽出／破線＝AI解釈」を必ず明示する。論文引用時は一次データのみを事実として扱う。
- **ADR-005 AIに直接SQLを書かせない**: AI生成の action/params を `QueryBuilder` が**ホワイトリスト方式**で解釈し、
  対応する prepared statement のみ実行する。未知の action はエラー。AIがSQLを書く・実行する経路は作らない。
- **ADR-006 テーマ別グルーピングは複数案＋別レイヤー**: AIには確定済みノード・エッジのラベルのみ渡し（原文は渡さない）、
  複数の分類案（proposal_set）を返させる。結果は `llm_groupings` に保存し本体データには一切影響させない。
- **ADR-007 Composer不採用（v1）**: 2026デファクトはComposer必須だが、学習教材との整合・Sakura運用簡素化のため
  v1は素のPHP。将来クラスが増えたら再検討する。
- **ADR-008 サブエージェント/Worktreeを使わない**: 小規模・単独学習プロジェクトのため過剰。
  1 issue = 順番に作業する運用でコンテキストを保つ。

---

## 6. 禁止事項（Prohibitions）

> ⚠️ 最優先ルール。理由を問わず従うこと。

- [ ] **1 issue超の一括実装禁止**: 一度に着手するのは1機能（1 issue）まで。全機能を一気に書かない。
- [ ] **無承認実装禁止**: 実装前に必ずプラン（何をどの順で作るか）を提示し、承認を得てからコードを書く。
- [ ] **設計判断の独断禁止**: MySQLバージョン・セッションID更新頻度など判断が要る箇所は勝手に決めず確認する。
- [ ] **AIに本体データを生成・書換させない**: `nodes`/`edges`/`evidence` をAIが作る・変える経路を一切作らない（ADR-002/005）。
- [ ] **SQL文字列結合禁止**: すべて prepared statements。AI生成JSON由来の値も必ず bindValue する。
- [ ] **秘密情報コミット禁止**: `config/`（db.php / llm.php）・APIキー・DB接続情報を絶対にコミットしない。ハードコードもしない。
- [ ] **import.php のWeb実行禁止**: CLI実行専用。`php_sapi_name() === 'cli'` でなければ弾く。
- [ ] **Provenanceの混同禁止**: 一次データと二次データを同じテーブル・同じ描画スタイルで混ぜない（ADR-004）。
- [ ] **pipeline/ のデプロイ禁止**: Sakuraには `public/` `config/`(本番値) `lib/` のみ上げる。

---

## 7. 必須ワークフロー（Workflows）

### 機能開発フロー（SDD＝仕様駆動開発）

```
1. specs/[feature-name].md（または GitHub Issue 本文）に仕様を書く
2. 人間（発注者）がレビュー・承認
3. 承認された仕様に基づき実装（スコープ外のファイルは変更しない）
4. 検証（§8 の完了条件を満たすか確認）
5. 各 issue 完了ごとに発注者の確認を取ってから次の issue へ進む
```

実装ステップの全体順序は idea.md §11 に従う（schema → import → ログイン → graph_data → 描画 → …）。

### Git/GitHubワークフロー

- ブランチ: `main` から `feature/#issue番号-短い説明` を切り、完了後PR → `main` にマージ
- コミット: メッセージ先頭に対応 issue 番号を含める（例: `#12 ノード検索APIを実装`）
- Milestone = 開発フェーズ（idea.md §11 のステップ）。issue を milestone に紐付ける
- 1 issue = 1 セッションを基本とし、コンテキストを汚さない。終了時は次への申し送りを issue の「参考/メモ」に残す

### コミットプレフィックス

```
feat: 新機能   fix: バグ修正   docs: ドキュメント
refactor: リファクタ（機能変更なし）   test: テスト   chore: ビルド・依存
```

---

## 8. テスト・検証方針（Testing / Verification）

v1 では PHPUnit を必須化しない。**各 issue の「完了条件」を検証可能な形で確認**する（idea.md §11）。

- **DB**: `sql/schema.sql` をローカルMySQLに適用できること
- **import**: `php import.php` でダミーJSONがMySQLに取り込めること（Web経由では弾かれること）
- **ログイン**: 未ログインで `index.php` に直アクセスすると弾かれること
- **API**: `graph_data.php` 等は未認証時に **401 + JSONエラー**（`{"error":"unauthorized"}`）を返すこと（curl で確認）
- **フロント**: Cytoscape.js 描画は **Playwright** でブラウザ上の挙動・コンソールエラーまで確認してから完了とする
- **AIフィルタ**: query_llm → execute_query が想定スキーマのJSONのみで動き、未知 action を弾くこと

---

## 9. 環境・設定（Environment）

**ローカル配信の方針（重要）**：リポジトリ本体は htdocs の外（`/Users/sengokukouki/novel-visualizer`）に置き、
**htdocs には `public/` を指す symlink を1本張る**。こうすると `config/`・`lib/`・`pipeline/` が
web root の外に残り、URL から触れない（＝本番Sakuraと同じ「config/lib は公開ディレクトリ外」構造になり、そのままデプロイできる）。

```bash
# 初回セットアップ（symlink。作成済み）
ln -s /Users/sengokukouki/novel-visualizer/public \
      /Applications/XAMPP/xamppfiles/htdocs/novel-visualizer
# → http://localhost/novel-visualizer/login.php で public/ が配信される

# XAMPP 同梱の php CLI（PATH未登録）。import.php は必ずこの php で実行
/Applications/XAMPP/xamppfiles/bin/php public/import.php

# DB接続・APIキーは config/ に分離（Git対象外・ハードコード厳禁）
#   config/db.php   … DB接続設定
#   config/llm.php  … Azure OpenAI エンドポイント・APIキー・デプロイ名
```

> **実装上の注意**：symlink 越しでも `config/`・`lib/` を正しく解決するため、public/ 内の require は
> **`require __DIR__ . '/../config/db.php'`** のように `__DIR__` 基準で書く（CWD依存の相対パスにしない）。
> Apache が symlink を辿らない場合のみ該当 Directory に `Options +FollowSymLinks` を付ける。

> 機密情報は必ず `config/` に置き、`.gitignore` で除外する。public リポジトリのため gitleaks で混入を検知する。

---

## 10. 外部参照（External References）

実装 issue の直前に **Context7 で正確なバージョンを再確認**する（プレリリース版を避ける）。

- Cytoscape.js（3.x 安定版・CDN読込）: https://js.cytoscape.org/ / https://github.com/cytoscape/cytoscape.js
- PHP PDO prepared statements: https://www.php.net/manual/en/pdo.prepared-statements.php
- Azure OpenAI JSON mode（`response_format: {"type":"json_object"}`）: https://learn.microsoft.com/en-us/azure/ai-foundry/openai/how-to/json-mode
- Azure OpenAI Chat Completions: https://learn.microsoft.com/en-us/azure/ai-foundry/openai/how-to/chatgpt
- GiNZA / spaCy（世界A・参考）: https://megagonlabs.github.io/ginza/ / https://spacy.io/
- gitleaks: https://github.com/gitleaks/gitleaks

---

## 11. コンパクション指示（Compaction Instructions）

コンテキスト圧縮時に必ず保持すること:

- 変更済みファイルの完全なリスト
- 未完了タスク（着手中の issue とそのステータス）
- 直前のテスト/検証結果、アクティブなエラーメッセージ
- 設計原則（§5 ADR）と禁止事項（§6）— これらは常に保持

---

## 12. 用語・表記統一（Terminology）

| 使う表記 | 意味 / 備考 |
|----------|-------------|
| 世界A / 世界B | 世界A=Python解析(ローカル)、世界B=PHP Webアプリ(Sakura) |
| ノード / エッジ / エビデンス | ノード=単語や登場人物、エッジ=関係、エビデンス=関係の原文根拠 |
| 一次データ（実線） | 統計的に抽出された事実（nodes/edges）。論文引用可 |
| 二次データ（破線） | AIによる解釈的グルーピング（llm_groupings）。事実ではない |
| Provenance | データの出所。一次/二次を視覚・DBの両面で分離する原則 |

---

## 更新ログ（Changelog）

| 日付 | 変更内容 |
|------|----------|
| 2026-07-14 | 初版作成（idea.md と AI駆動開発キックオフテンプレートに基づく） |

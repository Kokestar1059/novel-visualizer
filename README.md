# novel-visualizer

青空文庫などの著作権フリーな日本語小説を対象に、**単語・登場人物どうしの関係を統計的に解析し、
ネットワーク図（点と線）で可視化する研究用ツール**です。論文・記事への引用を想定しているため、
「解析結果の正確性・再現性・検証可能性」を最優先しています。

> **このREADMEは、人間がプロジェクト内容を確認するための資料です**（AIが開発中に都度参照する前提では書いていません）。
> 開発方針・設計原則・コーディング規約は [CLAUDE.md](CLAUDE.md) を、全体仕様は [idea.md](idea.md) を参照してください。

---

## 構成（2つの世界）

| | 世界A：解析パイプライン | 世界B：Webアプリ |
|---|---|---|
| 役割 | 小説テキストを形態素解析しJSON出力 | JSONを取り込み、ブラウザで可視化・絞り込み |
| 技術 | Python + GiNZA / spaCy | PHP 8.x + MySQL + Cytoscape.js |
| 実行場所 | ローカルPCのみ | Sakura Server |
| デプロイ | **しない**（Gitには含む） | `public/` `config/` `lib/` をFTPで配置 |

データの流れ： `小説.txt → analyze.py → nodes.json / edges.json → import.php → MySQL → PHP API → ブラウザ(Cytoscape.js)`

---

## セットアップ（ローカル開発 / XAMPP）

1. **XAMPP** をインストールし、Apache と MySQL を起動する
2. **`public/` だけを配信する**（リポジトリ本体は htdocs の外に置いたまま、`public/` を指す symlink を1本張る）
   ```bash
   ln -s /Users/sengokukouki/novel-visualizer/public \
         /Applications/XAMPP/xamppfiles/htdocs/novel-visualizer
   # → http://localhost/novel-visualizer/ で public/ が配信される
   ```
   > リポジトリを丸ごと htdocs に入れないこと。`config/`（秘密情報）を web root の外に隔離するためです。
3. **DB スキーマを作成**する
   ```bash
   mysql -u root -p < sql/schema.sql
   ```
4. **設定ファイルを用意**する（Git対象外。サンプルをコピーして値を埋める）
   ```
   config/db.php    … DB接続設定（ホスト/DB名/ユーザー/パスワード）
   config/llm.php   … Azure OpenAI のエンドポイント・APIキー・デプロイ名
   ```
   > `config/` は `.gitignore` 済み。**APIキー・DB接続情報は絶対にコミットしないでください。**
5. **解析済みJSONを取り込む**（世界A の成果物。v1はダミーJSONでも可）
   ```bash
   # XAMPP同梱の php CLI を使う（import.php は CLI 実行専用・ブラウザ不可）
   /Applications/XAMPP/xamppfiles/bin/php public/import.php
   ```
6. ブラウザで `http://localhost/novel-visualizer/login.php` にアクセスし、ログイン後に `index.php` でグラフを表示する

### 世界A（解析パイプライン）をローカルで動かす場合

```bash
cd pipeline
pip install -r requirements.txt
python analyze.py            # nodes.json / edges.json を output/ に出力
```

---

## 使い方

1. ログインする（未ログインでは各画面・APIは弾かれます）
2. トップ（`index.php`）でネットワーク図が表示される
3. ノードをクリックすると、隣接ノードと**エビデンス原文**がサイドパネルに出る
4. 検索窓に自然言語（例：「敵対関係だけ見せて」）を入力すると、AIがクエリに翻訳しグラフを絞り込む
5. 凡例の **実線＝統計的に抽出した事実／破線＝AIによる解釈** を確認して読む
   （論文引用時は実線＝事実のみを使ってください）

---

## デプロイ（Sakura Server / FTP）

- FTP（FileZilla等）でアップロードするのは **`public/` の中身・`config/`（本番用の値に差し替え）・`lib/`** のみ
- **`pipeline/`（Python一式）はアップロードしない**
- デプロイ前に **本番 MySQL のバージョンを必ず確認**する（5.7系だとN-hop探索の実装を変える必要あり。詳細は [CLAUDE.md](CLAUDE.md) §2）

---

## ライセンス / 注意

- 解析対象は著作権フリーの作品（青空文庫等）を想定しています。
- 本ツールの目的は研究・検証であり、AIの出力は常に「解釈（破線）」として事実（実線）と区別して扱います。

-- ============================================================
-- novel-visualizer データベーススキーマ
-- 出典: idea.md §6（データベース設計）
-- 対象: MariaDB 10.4+ / MySQL 8.0+（本番Sakuraのバージョンはデプロイ前に要確認）
--
-- 設計方針（CLAUDE.md §5）:
--   - nodes / edges / evidence … 一次データ（＝事実。統計的に抽出）
--   - llm_groupings           … 二次データ（＝AI解釈）。本体と物理的に別テーブル（ADR-004）
--   - 文字コードは日本語（青空文庫）対応のため utf8mb4 / utf8mb4_unicode_ci で統一
--   - 外部キー制約のため全テーブル ENGINE=InnoDB
-- ============================================================

CREATE DATABASE IF NOT EXISTS novel_visualizer
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE novel_visualizer;

-- ------------------------------------------------------------
-- 6.1 users（ログイン用）
--   学校パターン（login_id / login_pw / name / is_admin）を踏襲。
--   login_pw は v1では平文可、v1.5で password_hash() 化を検討（idea.md §10）。
-- ------------------------------------------------------------
CREATE TABLE users (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  login_id   VARCHAR(255) NOT NULL UNIQUE,
  login_pw   VARCHAR(255) NOT NULL,
  name       VARCHAR(255) NOT NULL,
  is_admin   TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6.2 works（作品）
-- ------------------------------------------------------------
CREATE TABLE works (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  title      VARCHAR(255) NOT NULL,          -- 例：「こころ」
  author     VARCHAR(255),                   -- 例：「夏目漱石」
  source     VARCHAR(255),                   -- 例：「青空文庫」＋URL等
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6.3 nodes（ノード＝単語・登場人物など）※一次データ
-- ------------------------------------------------------------
CREATE TABLE nodes (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  work_id    INT NOT NULL,
  label      VARCHAR(255) NOT NULL,          -- 例：「太郎」
  node_type  VARCHAR(64),                    -- 例：person / place / term など
  frequency  INT DEFAULT 0,                  -- 出現頻度
  FOREIGN KEY (work_id) REFERENCES works(id),
  INDEX idx_nodes_work (work_id),
  INDEX idx_nodes_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6.4 edges（エッジ＝関係）※一次データ（すべて「事実」）
--   N-hop探索（graph_data / execute_query）で source/target を辿るため索引を張る。
-- ------------------------------------------------------------
CREATE TABLE edges (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  work_id        INT NOT NULL,
  source_node_id INT NOT NULL,               -- 関係の起点ノード
  target_node_id INT NOT NULL,               -- 関係の終点ノード
  edge_type      VARCHAR(64),                -- 例：共起 / 敵対 / 頼る など
  weight         DECIMAL(6,4) DEFAULT 0,     -- 関係の強さ（PMI・Dice係数など）
  method         VARCHAR(64),                -- 抽出手法（co_occurrence / dependency_parse など）
  FOREIGN KEY (work_id)        REFERENCES works(id),
  FOREIGN KEY (source_node_id) REFERENCES nodes(id),
  FOREIGN KEY (target_node_id) REFERENCES nodes(id),
  INDEX idx_edges_work (work_id),
  INDEX idx_edges_source (source_node_id),
  INDEX idx_edges_target (target_node_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6.5 evidence（エビデンス＝各エッジの原文根拠）※一次データ
--   原文へ遡って検証できるよう、位置と原文そのものを保持（ADR-003）。
-- ------------------------------------------------------------
CREATE TABLE evidence (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  edge_id         INT NOT NULL,
  sentence_id     INT,                        -- 原文の何文目か
  text_span_start INT,                        -- 抽出箇所の開始位置
  text_span_end   INT,                        -- 抽出箇所の終了位置
  sentence_text   TEXT,                       -- 原文そのもの（検証用）
  FOREIGN KEY (edge_id) REFERENCES edges(id),
  INDEX idx_evidence_edge (edge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6.6 llm_groupings（AIによる解釈＝二次データ）
--   ★本体（nodes/edges/evidence）とは物理的に分離する（ADR-004）。
--   proposal_set で「案1」「案2」を区別し、複数の解釈候補を保持する。
-- ------------------------------------------------------------
CREATE TABLE llm_groupings (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  work_id      INT NOT NULL,
  group_label  VARCHAR(255),                 -- AIが付けたテーマ名（例：「対立関係」）
  node_id      INT NOT NULL,                 -- そのグループに属するノード
  proposal_set INT DEFAULT 1,                -- 「案1」「案2」を区別する番号（複数案対応）
  description  TEXT,                          -- この案の分類基準の説明（AIが生成）
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (work_id) REFERENCES works(id),
  FOREIGN KEY (node_id) REFERENCES nodes(id),
  INDEX idx_groupings_work (work_id),
  INDEX idx_groupings_proposal (work_id, proposal_set)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

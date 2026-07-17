-- ============================================================
-- seed_users.sql — ログイン動作確認用のテストユーザー
--   出典: idea.md §7（ログイン認証）/ Issue #3
--   ★v1は login_pw を平文で保持（password_hash化はv1.5で検討。idea.md §10）。
--     そのため秘密情報ではなく、Gitに含めてよい（本番用の実在パスワードは置かない）。
--   適用: schema.sql 適用後に
--     /Applications/XAMPP/xamppfiles/bin/mysql -u root novel_visualizer < sql/seed_users.sql
-- ============================================================

USE novel_visualizer;

-- テストユーザー（login_id: testuser / login_pw: pass1234）
-- 再適用しても重複しないよう login_id で UPSERT する。
INSERT INTO users (login_id, login_pw, name, is_admin)
VALUES ('testuser', 'pass1234', 'テスト太郎', 1)
ON DUPLICATE KEY UPDATE
  login_pw = VALUES(login_pw),
  name     = VALUES(name),
  is_admin = VALUES(is_admin);

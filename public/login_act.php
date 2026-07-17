<?php
// ============================================================
// login_act.php — ログイン認証処理
//   出典: idea.md §7.1
//   ★照合SQLは prepared statement のみ（文字列結合でSQLを組まない。CLAUDE.md §6）
//   ★v1は login_pw を平文比較（password_hash化はv1.5で検討。idea.md §10）
//   ★config/db.php は __DIR__ 基準で require（symlink越しでも解決できるように。CLAUDE.md §9）
// ============================================================
session_start();

// POST以外での直アクセスはフォームへ戻す
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: login.php');
  exit;
}

$loginId = $_POST['login_id'] ?? '';
$loginPw = $_POST['login_pw'] ?? '';

// 未入力はエラーとして戻す
if ($loginId === '' || $loginPw === '') {
  header('Location: login.php?err=1');
  exit;
}

/** @var PDO $pdo */
$pdo = require __DIR__ . '/../config/db.php';

// login_id で1件引き、pwは PHP 側で比較（prepared statement）
$stmt = $pdo->prepare('SELECT id, login_id, login_pw, name FROM users WHERE login_id = :login_id LIMIT 1');
$stmt->bindValue(':login_id', $loginId, PDO::PARAM_STR);
$stmt->execute();
$user = $stmt->fetch();

// ユーザーが存在し、かつパスワードが一致するか（v1は平文比較）
if ($user !== false && hash_equals((string)$user['login_pw'], (string)$loginPw)) {
  // 認証成功：セッション固定攻撃対策にIDを更新してから鍵を保存
  session_regenerate_id(true);
  $_SESSION['chk_ssid'] = session_id();
  $_SESSION['user_id']  = $user['id'];
  $_SESSION['name']     = $user['name'];
  header('Location: index.php');
  exit;
}

// 認証失敗：フォームへ戻す
header('Location: login.php?err=1');
exit;

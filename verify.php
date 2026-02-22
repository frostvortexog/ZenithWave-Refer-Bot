<?php
$BOT_TOKEN    = getenv("BOT_TOKEN");
$DATABASE_URL = getenv("DATABASE_URL");
$BOT_USERNAME = getenv("BOT_USERNAME");

if (!$DATABASE_URL || !$BOT_USERNAME) exit("Missing ENV");

$db = parse_url($DATABASE_URL);
$pdo = new PDO(
  "pgsql:host={$db['host']};port={$db['port']};dbname=".ltrim($db['path'],'/'),
  $db['user'],
  $db['pass'],
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$user_id = isset($_GET["u"]) ? (int)$_GET["u"] : 0;
if ($user_id <= 0) exit("Invalid user");

// device hash (simple device-lock style)
$device = hash("sha256", ($_SERVER["REMOTE_ADDR"] ?? "")."|".($_SERVER["HTTP_USER_AGENT"] ?? ""));

// 1 device -> 1 telegram id rule:
// If device hash already used by another user, block.
$st = $pdo->prepare("SELECT user_id FROM users WHERE device_hash=:d AND user_id<>:u LIMIT 1");
$st->execute([":d"=>$device, ":u"=>$user_id]);
$exists = $st->fetchColumn();

if ($exists) {
  echo "<h2>❌ Verification failed</h2><p>This device is already linked to another Telegram ID.</p>";
  exit;
}

$pdo->prepare("UPDATE users SET verified=TRUE, device_hash=:d WHERE user_id=:u")
    ->execute([":d"=>$device, ":u"=>$user_id]);

$botLink = "https://t.me/".$BOT_USERNAME;

echo "<!doctype html>
<html>
<head><meta charset='utf-8'><meta name='viewport' content='width=device-width, initial-scale=1'>
<title>Verified</title></head>
<body style='font-family:Arial;padding:20px'>
<h2>✅ Verification Complete</h2>
<p>Now return to Telegram and press <b>Check Verification</b>.</p>
<a href='$botLink' style='display:inline-block;padding:12px 16px;background:#2AABEE;color:white;text-decoration:none;border-radius:8px'>
Return to Bot
</a>
</body>
</html>";

<?php
// ============================================================
// verify.php - Token based verification + device lock
// - Validates token (expires in 5 min)
// - Locks 1 device to 1 Telegram ID
// - Also blocks the same device verifying multiple TG IDs
// - Marks users.web_verified = true
// ============================================================

$DB_URL = getenv("DATABASE_URL");
if (!$DB_URL) { http_response_code(500); echo "Missing DATABASE_URL"; exit; }

$db = parse_url($DB_URL);
$dsn = "pgsql:host={$db['host']};port={$db['port']};dbname=" . ltrim($db['path'], '/');
$pdo = new PDO($dsn, $db['user'], $db['pass'], [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$token = $_GET["token"] ?? "";
$token = trim($token);

if (!$token) { http_response_code(400); echo "Invalid token"; exit; }

$stmt = $pdo->prepare("SELECT user_id, expires_at FROM verify_tokens WHERE token=?");
$stmt->execute([$token]);
$row = $stmt->fetch();

if (!$row) { http_response_code(400); echo "Invalid token"; exit; }
if (strtotime($row["expires_at"]) < time()) { http_response_code(400); echo "Token expired"; exit; }

$user_id = (int)$row["user_id"];

// Device fingerprint (simple + effective for your use-case)
$ua = $_SERVER["HTTP_USER_AGENT"] ?? "no-ua";
$ip = $_SERVER["REMOTE_ADDR"] ?? "no-ip";

// If you want less false blocks on mobile networks, you can remove $ip,
// but you asked "1 device => 1 id", so we keep both:
$device_hash = hash("sha256", $ua . "|" . $ip);

// Fetch existing
$u = $pdo->prepare("SELECT id, device_hash, web_verified FROM users WHERE id=?");
$u->execute([$user_id]);
$user = $u->fetch();

if (!$user) { http_response_code(400); echo "User not found"; exit; }

// If this TG already has a device, must match
if (!empty($user["device_hash"]) && $user["device_hash"] !== $device_hash) {
  http_response_code(403);
  echo "This Telegram ID is already linked to another device.";
  exit;
}

// Block device used by another Telegram ID (anti-fraud)
$check = $pdo->prepare("SELECT id FROM users WHERE device_hash=? AND id<>? AND web_verified=true LIMIT 1");
$check->execute([$device_hash, $user_id]);
$other = $check->fetchColumn();

if ($other) {
  http_response_code(403);
  echo "This device is already linked to another Telegram ID.";
  exit;
}

// Mark verified + save device hash, and consume the token
$pdo->beginTransaction();
$pdo->prepare("UPDATE users SET web_verified=true, device_hash=? WHERE id=?")->execute([$device_hash, $user_id]);
$pdo->prepare("DELETE FROM verify_tokens WHERE token=?")->execute([$token]);
$pdo->commit();

// Redirect back to Telegram (user will still press Check Verification)
$botUser = getenv("BOT_USERNAME") ?: "";
$tgLink = $botUser ? ("https://t.me/" . $botUser) : "https://t.me/";

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Verified</title>
  <meta http-equiv="refresh" content="2;url=<?php echo htmlspecialchars($tgLink, ENT_QUOTES); ?>">
  <style>
    body { font-family: Arial, sans-serif; padding: 24px; }
    .box { max-width: 520px; margin:auto; border:1px solid #ddd; border-radius:12px; padding:20px; }
    .ok { font-size:18px; }
  </style>
</head>
<body>
  <div class="box">
    <div class="ok">✅ Verification Successful</div>
    <p>Redirecting you back to Telegram…</p>
    <p>If it doesn’t open automatically, click:</p>
    <p><a href="<?php echo htmlspecialchars($tgLink, ENT_QUOTES); ?>">Return to Telegram</a></p>
  </div>
</body>
</html>

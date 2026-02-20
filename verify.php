<?php
$DB_URL = getenv("DATABASE_URL");
$db = parse_url($DB_URL);
$dsn = "pgsql:host={$db['host']};port={$db['port']};dbname=" . ltrim($db['path'], '/');
$pdo = new PDO($dsn, $db['user'], $db['pass'], [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function deviceHash() {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
  return hash('sha256', $ip . '|' . $ua);
}

$token = $_GET["token"] ?? "";
if (!$token) die("Missing token");

$stmt = $pdo->prepare("SELECT token,user_id,expires_at FROM verify_tokens WHERE token=?");
$stmt->execute([$token]);
$row = $stmt->fetch();
if (!$row) die("Invalid token");
if (strtotime($row["expires_at"]) < time()) die("Token expired. Go back to Telegram and verify again.");

$user_id = (int)$row["user_id"];
$device = deviceHash();
$ip = $_SERVER["REMOTE_ADDR"] ?? "";

// Device already used by other account?
$chk = $pdo->prepare("SELECT id FROM users WHERE device_hash=? AND id<>? LIMIT 1");
$chk->execute([$device, $user_id]);
if ($chk->fetch()) {
  die("This device is already linked to another Telegram account.");
}

// Mark verified + save device
$pdo->beginTransaction();
$pdo->prepare("UPDATE users SET web_verified=true, device_hash=?, ip_address=? WHERE id=?")
    ->execute([$device, $ip, $user_id]);
$pdo->prepare("DELETE FROM verify_tokens WHERE token=?")
    ->execute([$token]);
$pdo->commit();

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Verified</title>
  <style>
    body{font-family:system-ui,Arial;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;background:#0b1220;color:#fff}
    .card{max-width:520px;padding:24px;border-radius:16px;background:rgba(255,255,255,.08);box-shadow:0 10px 30px rgba(0,0,0,.35)}
    .btn{display:inline-block;margin-top:16px;padding:12px 16px;border-radius:12px;background:#22c55e;color:#071018;text-decoration:none;font-weight:700}
    .muted{opacity:.8}
  </style>
</head>
<body>
  <div class="card">
    <h2>✅ Verification Successful</h2>
    <p class="muted">Return to Telegram and tap <b>Check Verification</b>.</p>
    <a class="btn" href="https://t.me/">Open Telegram</a>
  </div>
</body>
</html>

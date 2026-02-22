<?php
// ============================================================
// FINAL ENTERPRISE Referral Bot (Webhook + Web Verify) - PHP + Supabase Postgres
// Single file: index.php
//
// ✅ Multi force-join channels (admin managed)
// ✅ Web verification (token based) + device lock (1 device = 1 TG ID)
// ✅ Referral credit after verification
// ✅ Auto deduction if referred user leaves (cron protected)
// ✅ Withdraw with secure row locking + SKIP LOCKED
// ✅ Full Admin Panel: add/remove/list channels, add coupons, stock, redeems log, change withdraw points
// ✅ Safe migrations + try/catch => prevents 502 webhook crashes
// ============================================================

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

// ---------------- ENV ----------------
$BOT_TOKEN    = getenv("BOT_TOKEN") ?: "";
$DB_URL       = getenv("DATABASE_URL") ?: "";
$ADMIN_IDS    = array_filter(array_map('trim', explode(',', getenv("ADMIN_IDS") ?: "")));
$BOT_USERNAME = getenv("BOT_USERNAME") ?: "";
$BASE_URL     = rtrim(getenv("BASE_URL") ?: "", "/");
$CRON_SECRET  = getenv("CRON_SECRET") ?: "";

// Reward types
$REWARD_TYPES = ["500","1000","2000","4000"];

// Always respond fast to webhook even if misconfigured
function fast200(string $body = "OK"): void {
  http_response_code(200);
  header("Content-Type: text/plain; charset=utf-8");
  echo $body;
  exit;
}

// If GET health check
if ($_SERVER["REQUEST_METHOD"] === "GET" && empty($_GET)) {
  fast200("OK");
}

// ---------------- DB ----------------
function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  $DB_URL = getenv("DATABASE_URL") ?: "";
  if (!$DB_URL) {
    // Return a dummy, but we must not crash webhook
    fast200("Missing DATABASE_URL");
  }
  $db = parse_url($DB_URL);
  $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname=" . ltrim($db['path'] ?? "", '/');
  $pdo = new PDO($dsn, $db['user'] ?? "", $db['pass'] ?? "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

function migrate(): void {
  $pdo = db();

  // Users
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
      id BIGINT PRIMARY KEY,
      username TEXT DEFAULT '',
      points INT NOT NULL DEFAULT 0,
      ref_by BIGINT NULL,
      credited_ref BOOLEAN NOT NULL DEFAULT FALSE,
      web_verified BOOLEAN NOT NULL DEFAULT FALSE,
      created_at TIMESTAMP NOT NULL DEFAULT NOW()
    );
  ");

  // Settings (also used for state storage)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS settings (
      key TEXT PRIMARY KEY,
      value TEXT NOT NULL DEFAULT ''
    );
  ");

  // Force channels
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS force_channels (
      id BIGSERIAL PRIMARY KEY,
      chat_id TEXT UNIQUE NOT NULL,
      invite_link TEXT NULL,
      is_active BOOLEAN NOT NULL DEFAULT TRUE,
      created_at TIMESTAMP NOT NULL DEFAULT NOW()
    );
  ");

  // Coupons
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS coupons (
      id BIGSERIAL PRIMARY KEY,
      type TEXT NOT NULL,
      code TEXT UNIQUE NOT NULL,
      used BOOLEAN NOT NULL DEFAULT FALSE,
      used_by BIGINT NULL,
      used_at TIMESTAMP NULL,
      created_at TIMESTAMP NOT NULL DEFAULT NOW()
    );
  ");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_coupons_type_used ON coupons(type, used);");

  // Redeem logs
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS redeem_logs (
      id BIGSERIAL PRIMARY KEY,
      user_id BIGINT NOT NULL,
      username TEXT NOT NULL,
      coupon_type TEXT NOT NULL,
      code TEXT NOT NULL,
      points_used INT NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT NOW()
    );
  ");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_redeem_logs_created_at ON redeem_logs(created_at DESC);");

  // Verify tokens
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS verify_tokens (
      token TEXT PRIMARY KEY,
      user_id BIGINT NOT NULL,
      expires_at TIMESTAMP NOT NULL,
      used BOOLEAN NOT NULL DEFAULT FALSE,
      created_at TIMESTAMP NOT NULL DEFAULT NOW()
    );
  ");

  // Device locks: 1 device_id => 1 telegram user
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS device_locks (
      device_id TEXT PRIMARY KEY,
      user_id BIGINT NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT NOW()
    );
  ");

  // Default withdraw points if not set
  foreach (["500"=>1,"1000"=>2,"2000"=>3,"4000"=>4] as $t=>$v) {
    $stmt = $pdo->prepare("INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO NOTHING");
    $stmt->execute(["points_$t", (string)$v]);
  }
}

// ---------------- Telegram helper ----------------
function bot(string $method, array $data = []): ?array {
  $BOT_TOKEN = getenv("BOT_TOKEN") ?: "";
  if (!$BOT_TOKEN) return null;

  $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $data,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 8,
  ]);
  $res = curl_exec($ch);
  curl_close($ch);
  if (!$res) return null;
  $j = json_decode($res, true);
  return is_array($j) ? $j : null;
}

// ---------------- Utils ----------------
function isAdmin(int $uid): bool {
  $ADMIN_IDS = array_filter(array_map('trim', explode(',', getenv("ADMIN_IDS") ?: "")));
  return in_array((string)$uid, $ADMIN_IDS, true);
}

function rl_ok(int $user_id, string $bucket, int $max = 12, int $window = 10): bool {
  static $mem = [];
  $now = time();
  $k = $user_id . ":" . $bucket;
  if (!isset($mem[$k])) $mem[$k] = [];
  $mem[$k] = array_values(array_filter($mem[$k], fn($t) => ($now - $t) < $window));
  if (count($mem[$k]) >= $max) return false;
  $mem[$k][] = $now;
  return true;
}

function ensureUser(int $user_id, string $username): void {
  $pdo = db();
  $stmt = $pdo->prepare("
    INSERT INTO users(id, username)
    VALUES(?, ?)
    ON CONFLICT(id) DO UPDATE SET username=EXCLUDED.username
  ");
  $stmt->execute([$user_id, $username]);
}

function setState(int $user_id, ?string $state, $meta = null): void {
  $pdo = db();
  if ($state === null) {
    $pdo->prepare("DELETE FROM settings WHERE key=?")->execute(["state_$user_id"]);
    return;
  }
  $pdo->prepare("
    INSERT INTO settings(key, value)
    VALUES(?, ?)
    ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value
  ")->execute(["state_$user_id", json_encode(["s"=>$state, "m"=>$meta], JSON_UNESCAPED_SLASHES)]);
}

function getState(int $user_id): array {
  $pdo = db();
  $stmt = $pdo->prepare("SELECT value FROM settings WHERE key=?");
  $stmt->execute(["state_$user_id"]);
  $v = $stmt->fetchColumn();
  if (!$v) return ["s"=>null, "m"=>null];
  $j = json_decode((string)$v, true);
  return is_array($j) ? $j : ["s"=>null, "m"=>null];
}

function mainMenuKeyboard(): array {
  return [
    "keyboard" => [
      [["text"=>"Stats"], ["text"=>"Referral Link"]],
      [["text"=>"Withdraw"]],
    ],
    "resize_keyboard" => true
  ];
}

function adminKeyboard(): array {
  return [
    "keyboard" => [
      [["text"=>"Add Channel"], ["text"=>"Remove Channel"]],
      [["text"=>"List Channels"]],
      [["text"=>"Add Coupon"], ["text"=>"Stock"]],
      [["text"=>"Redeems Log"]],
      [["text"=>"Change Withdraw Points"]],
      [["text"=>"User Menu"]],
    ],
    "resize_keyboard" => true
  ];
}

// ---------------- Force channels (DB) ----------------
function getForceChannels(): array {
  $pdo = db();
  return $pdo->query("SELECT chat_id, invite_link FROM force_channels WHERE is_active=true ORDER BY id ASC")->fetchAll() ?: [];
}

function inlineForceJoinKeyboard(): array {
  $rows = getForceChannels();
  $kb = [];
  foreach ($rows as $r) {
    $chat_id = (string)$r["chat_id"];
    $invite  = $r["invite_link"] ? (string)$r["invite_link"] : "";

    if (strpos($chat_id, "@") === 0) {
      $url = $invite ?: ("https://t.me/" . str_replace("@", "", $chat_id));
      $kb[] = [[ "text"=>"📢 Join $chat_id", "url"=>$url ]];
    } else {
      // private channel id needs invite link
      $kb[] = [[ "text"=>"📢 Join Private Channel", "url"=>($invite ?: "https://t.me/") ]];
    }
  }

  $kb[] = [[ "text"=>"✅ Joined All Channels", "callback_data"=>"check_join" ]];
  return ["inline_keyboard"=>$kb];
}

function isJoinedAll(int $user_id): bool {
  $rows = getForceChannels();
  if (!$rows) return true;

  foreach ($rows as $r) {
    $ch = (string)$r["chat_id"];
    $res = bot("getChatMember", ["chat_id"=>$ch, "user_id"=>$user_id]);
    $status = $res["result"]["status"] ?? null;
    if (!in_array($status, ["member","administrator","creator"], true)) return false;
  }
  return true;
}

// ---------------- Withdraw points + stock ----------------
function getWithdrawPoints(string $type): int {
  $pdo = db();
  $stmt = $pdo->prepare("SELECT value FROM settings WHERE key=?");
  $stmt->execute(["points_$type"]);
  $v = $stmt->fetchColumn();
  return $v !== false ? (int)$v : 1;
}

function setWithdrawPoints(string $type, int $points): void {
  $pdo = db();
  $pdo->prepare("
    INSERT INTO settings(key,value)
    VALUES(?,?)
    ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value
  ")->execute(["points_$type", (string)$points]);
}

function stockCount(string $type): int {
  $pdo = db();
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM coupons WHERE type=? AND used=false");
  $stmt->execute([$type]);
  return (int)$stmt->fetchColumn();
}

// ---------------- Web verify token ----------------
function generateToken(int $user_id): string {
  $pdo = db();
  $token = bin2hex(random_bytes(32));
  $expires = date("Y-m-d H:i:s", time() + 300); // 5 min
  $pdo->prepare("INSERT INTO verify_tokens(token, user_id, expires_at) VALUES(?,?,?)")
      ->execute([$token, $user_id, $expires]);
  return $token;
}

function inlineWebVerifyKeyboard(string $verifyUrl): array {
  return ["inline_keyboard" => [
    [[ "text"=>"🌐 Verify Now", "url"=>$verifyUrl ]],
    [[ "text"=>"✅ Check Verification", "callback_data"=>"check_web" ]]
  ]];
}

// ============================================================
// WEB VERIFY (served from SAME index.php)
// - GET  ?verify=1&token=...
// - POST ?verify_action=1  (token + device_id)
// ============================================================
function serveVerifyPage(string $token): void {
  $BOT_USERNAME = getenv("BOT_USERNAME") ?: "";
  $safeToken = htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

  header("Content-Type: text/html; charset=utf-8");
  echo "<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
  echo "<title>Verification</title>";
  echo "<style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;margin:0;background:#0b1220;color:#fff}
    .wrap{max-width:520px;margin:40px auto;padding:20px}
    .card{background:#121a2b;border:1px solid #24324d;border-radius:14px;padding:18px}
    button{width:100%;padding:14px;border:0;border-radius:12px;background:#3b82f6;color:#fff;font-size:16px;font-weight:700;cursor:pointer}
    .muted{opacity:.85;font-size:13px;line-height:1.35}
    .ok{color:#34d399;font-weight:700}
    .bad{color:#fb7185;font-weight:700}
    a{color:#93c5fd}
  </style></head><body><div class='wrap'><div class='card'>";
  echo "<h2>🌐 Web Verification</h2>";
  echo "<p class='muted'>Click <b>Verify Now</b>. After success, return to Telegram and tap <b>Check Verification</b>.</p>";
  echo "<button id='btn'>✅ Verify Now</button>";
  echo "<p id='msg' class='muted' style='margin-top:12px'></p>";
  echo "<p class='muted' style='margin-top:10px'>If Telegram doesn’t open automatically, open your bot: <a href='https://t.me/{$BOT_USERNAME}'>@{$BOT_USERNAME}</a></p>";
  echo "</div></div>
  <script>
    function getDeviceId(){
      try{
        let k='zw_device_id';
        let v=localStorage.getItem(k);
        if(!v){
          v = (crypto.randomUUID ? crypto.randomUUID() : (Date.now()+''+Math.random())).replace(/[^a-zA-Z0-9-]/g,'');
          localStorage.setItem(k,v);
        }
        return v;
      }catch(e){
        return 'fallback-'+(Date.now()+''+Math.random());
      }
    }
    const token = '{$safeToken}';
    document.getElementById('btn').addEventListener('click', async ()=>{
      const msg = document.getElementById('msg');
      msg.textContent='⏳ Verifying...';
      try{
        const device_id = getDeviceId();
        const r = await fetch('?verify_action=1', {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({token, device_id})
        });
        const j = await r.json();
        if(j && j.ok){
          msg.innerHTML = '<span class=\"ok\">✅ Verified!</span> Redirecting to Telegram...';
          setTimeout(()=>{
            window.location.href = 'https://t.me/{$BOT_USERNAME}';
          }, 800);
        }else{
          msg.innerHTML = '<span class=\"bad\">❌ '+(j.error||'Verification failed')+'</span>';
        }
      }catch(e){
        msg.innerHTML = '<span class=\"bad\">❌ Network error</span>';
      }
    });
  </script></body></html>";
  exit;
}

function handleVerifyAction(): void {
  try {
    migrate();
    $pdo = db();
    $raw = file_get_contents("php://input");
    $j = json_decode($raw ?: "{}", true);
    $token = isset($j["token"]) ? (string)$j["token"] : "";
    $device_id = isset($j["device_id"]) ? (string)$j["device_id"] : "";

    if (strlen($token) < 10 || strlen($device_id) < 6) {
      http_response_code(400);
      echo json_encode(["ok"=>false,"error"=>"Bad request"]);
      exit;
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT token,user_id,expires_at,used FROM verify_tokens WHERE token=? FOR UPDATE");
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) {
      $pdo->rollBack();
      echo json_encode(["ok"=>false,"error"=>"Invalid token"]);
      exit;
    }
    if ((bool)$row["used"]) {
      $pdo->rollBack();
      echo json_encode(["ok"=>false,"error"=>"Token already used"]);
      exit;
    }
    if (strtotime((string)$row["expires_at"]) < time()) {
      $pdo->rollBack();
      echo json_encode(["ok"=>false,"error"=>"Token expired"]);
      exit;
    }

    $user_id = (int)$row["user_id"];

    // Device lock: device_id must map to only one TG user
    $stmt2 = $pdo->prepare("SELECT user_id FROM device_locks WHERE device_id=? FOR UPDATE");
    $stmt2->execute([$device_id]);
    $dl = $stmt2->fetchColumn();
    if ($dl !== false && (int)$dl !== $user_id) {
      $pdo->rollBack();
      echo json_encode(["ok"=>false,"error"=>"This device is already registered"]);
      exit;
    }

    // Ensure device lock exists
    $pdo->prepare("INSERT INTO device_locks(device_id,user_id) VALUES(?,?) ON CONFLICT(device_id) DO NOTHING")
        ->execute([$device_id, $user_id]);

    // Mark user verified
    $pdo->prepare("UPDATE users SET web_verified=true WHERE id=?")->execute([$user_id]);

    // Mark token used
    $pdo->prepare("UPDATE verify_tokens SET used=true WHERE token=?")->execute([$token]);

    $pdo->commit();

    echo json_encode(["ok"=>true]);
    exit;

  } catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(["ok"=>false,"error"=>"Server error"]);
    exit;
  }
}

// Route verify page / action
if (isset($_GET["verify"]) && $_GET["verify"] === "1") {
  $token = (string)($_GET["token"] ?? "");
  if (!$token) fast200("Missing token");
  serveVerifyPage($token);
}
if (isset($_GET["verify_action"]) && $_GET["verify_action"] === "1") {
  handleVerifyAction();
}

// ============================================================
// CRON: auto-deduct points if referred user leaves channels
// URL: /index.php?cron=check_refs&key=CRON_SECRET
// ============================================================
if (isset($_GET["cron"]) && $_GET["cron"] === "check_refs") {
  try {
    if (!$CRON_SECRET || (($_GET["key"] ?? "") !== $CRON_SECRET)) {
      http_response_code(403);
      echo "forbidden";
      exit;
    }
    migrate();
    $pdo = db();

    $rows = $pdo->query("SELECT id, ref_by, credited_ref FROM users WHERE ref_by IS NOT NULL")->fetchAll();
    $deducted = 0;

    foreach ($rows as $r) {
      $uid = (int)$r["id"];
      $refBy = (int)$r["ref_by"];
      $credited = (bool)$r["credited_ref"];
      if (!$credited) continue;

      if (!isJoinedAll($uid)) {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET points = GREATEST(points - 1, 0) WHERE id=?")->execute([$refBy]);
        $pdo->prepare("UPDATE users SET credited_ref=false, ref_by=NULL WHERE id=?")->execute([$uid]);
        $pdo->commit();
        $deducted++;
      }
    }

    echo "OK deducted=$deducted";
    exit;
  } catch (Throwable $e) {
    http_response_code(200);
    echo "OK deducted=0";
    exit;
  }
}

// ============================================================
// TELEGRAM WEBHOOK
// ============================================================
try {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    fast200("OK");
  }

  // Fail-safe: if ENV missing, still return 200 to prevent Telegram 502 loops
  if (!$BOT_TOKEN || !$DB_URL || !$BOT_USERNAME || !$BASE_URL) {
    fast200("Missing ENV");
  }

  migrate();
  $pdo = db();

  $update = json_decode(file_get_contents("php://input") ?: "{}", true);
  if (!$update) fast200("OK");

  $message  = $update["message"] ?? null;
  $callback = $update["callback_query"] ?? null;

  $user_id  = $message["from"]["id"] ?? $callback["from"]["id"] ?? null;
  $username = $message["from"]["username"] ?? $callback["from"]["username"] ?? "NoUsername";
  $chat_id  = $message["chat"]["id"] ?? $callback["message"]["chat"]["id"] ?? $user_id;

  if (!$user_id) fast200("OK");
  $user_id = (int)$user_id;

  ensureUser($user_id, (string)$username);

  // ---------------- CALLBACKS ----------------
  if ($callback) {
    if (!rl_ok($user_id, "cb", 18, 10)) {
      bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Slow down"]);
      fast200();
    }
    $data = (string)($callback["data"] ?? "");

    // Joined channels => send web verify link
    if ($data === "check_join") {
      if (!isJoinedAll($user_id)) {
        bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Join all channels first"]);
        fast200();
      }
      $token = generateToken($user_id);
      $verifyUrl = $BASE_URL . "/index.php?verify=1&token=" . $token;

      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"🌐 Web Verification Required\n\n1) Tap Verify Now\n2) Complete verification\n3) Come back and tap Check Verification",
        "reply_markup"=>json_encode(inlineWebVerifyKeyboard($verifyUrl))
      ]);
      fast200();
    }

    // Check web verification => unlock menu + credit referral once
    if ($data === "check_web") {
      if (!isJoinedAll($user_id)) {
        bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Join all channels first"]);
        fast200();
      }

      $stmt = $pdo->prepare("SELECT web_verified, ref_by, credited_ref FROM users WHERE id=?");
      $stmt->execute([$user_id]);
      $u = $stmt->fetch();

      if (!$u || !$u["web_verified"]) {
        bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Not verified yet"]);
        fast200();
      }

      // credit referral once
      if (!$u["credited_ref"] && !empty($u["ref_by"])) {
        $refBy = (int)$u["ref_by"];
        if ($refBy !== $user_id) {
          $pdo->beginTransaction();
          $pdo->prepare("UPDATE users SET points = points + 1 WHERE id=?")->execute([$refBy]);
          $pdo->prepare("UPDATE users SET credited_ref=true WHERE id=?")->execute([$user_id]);
          $pdo->commit();
        } else {
          $pdo->prepare("UPDATE users SET ref_by=NULL, credited_ref=false WHERE id=?")->execute([$user_id]);
        }
      }

      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"✅ Verification Completed!\n\nUse the menu below:",
        "reply_markup"=>json_encode(mainMenuKeyboard())
      ]);
      fast200();
    }

    // Withdraw callback
    if (strpos($data, "wd:") === 0) {
      $type = substr($data, 3);

      // must be verified + joined
      $stmt = $pdo->prepare("SELECT web_verified, points FROM users WHERE id=?");
      $stmt->execute([$user_id]);
      $u = $stmt->fetch();

      if (!$u || !$u["web_verified"]) {
        bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Verify first"]);
        fast200();
      }
      if (!isJoinedAll($user_id)) {
        bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Join all channels first"]);
        fast200();
      }

      $required = getWithdrawPoints($type);

      try {
        $pdo->beginTransaction();

        $stmtU = $pdo->prepare("SELECT points FROM users WHERE id=? FOR UPDATE");
        $stmtU->execute([$user_id]);
        $uu = $stmtU->fetch();
        if (!$uu) { $pdo->rollBack(); fast200(); }

        if ((int)$uu["points"] < $required) {
          $pdo->rollBack();
          bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Not enough referrals/points"]);
          fast200();
        }

        // SKIP LOCKED = no waiting, faster + safer
        $stmtC = $pdo->prepare("
          SELECT id, code
          FROM coupons
          WHERE type=? AND used=false
          ORDER BY id ASC
          FOR UPDATE SKIP LOCKED
          LIMIT 1
        ");
        $stmtC->execute([$type]);
        $c = $stmtC->fetch();

        if (!$c) {
          $pdo->rollBack();
          bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Out of stock"]);
          fast200();
        }

        $pdo->prepare("UPDATE coupons SET used=true, used_by=?, used_at=NOW() WHERE id=?")->execute([$user_id, $c["id"]]);
        $pdo->prepare("UPDATE users SET points = points - ? WHERE id=?")->execute([$required, $user_id]);
        $pdo->prepare("INSERT INTO redeem_logs(user_id, username, coupon_type, code, points_used) VALUES (?,?,?,?,?)")
            ->execute([$user_id, (string)$username, $type, $c["code"], $required]);

        $pdo->commit();

        $safeCode = htmlspecialchars((string)$c["code"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
        bot("sendMessage", [
          "chat_id"=>$chat_id,
          "text"=>"🎉 Coupon Redeemed!\n\nType: {$type} off on {$type}\nPoints Used: {$required}\n\nCode:\n<code>{$safeCode}</code>",
          "parse_mode"=>"HTML"
        ]);

        // notify admins
        foreach ($ADMIN_IDS as $aid) {
          bot("sendMessage", [
            "chat_id"=>$aid,
            "text"=>"🔔 Withdraw Alert\nUser: @" . (string)$username . " (ID: $user_id)\nReward: {$type} off on {$type}\nPoints Deducted: {$required}\nCode: " . (string)$c["code"]
          ]);
        }

      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Try again"]);
      }

      fast200();
    }

    // Admin callbacks
    if (isAdmin($user_id)) {
      if (strpos($data, "admin_add_type:") === 0) {
        $type = substr($data, strlen("admin_add_type:"));
        setState($user_id, "ADMIN_ADD_COUPONS", ["type"=>$type]);
        bot("sendMessage", [
          "chat_id"=>$chat_id,
          "text"=>"➕ Send coupon codes line by line for: {$type} off on {$type}\n\nExample:\nABC123\nDEF456\n..."
        ]);
        fast200();
      }

      if (strpos($data, "admin_points_type:") === 0) {
        $type = substr($data, strlen("admin_points_type:"));
        setState($user_id, "ADMIN_SET_POINTS", ["type"=>$type]);
        $cur = getWithdrawPoints($type);
        bot("sendMessage", [
          "chat_id"=>$chat_id,
          "text"=>"✏️ Send required points for: {$type} off on {$type}\nCurrent: {$cur}\n\nSend a number (example: 5)"
        ]);
        fast200();
      }
    }

    bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"OK"]);
    fast200();
  }

  // ---------------- MESSAGES (TEXT) ----------------
  $text = (string)($message["text"] ?? "");
  if ($text === "") fast200();

  if (!rl_ok($user_id, "msg", 15, 10)) fast200();

  // /start with referral
  if (strpos($text, "/start") === 0) {
    $parts = explode(" ", $text, 2);
    $ref = isset($parts[1]) ? trim($parts[1]) : "";

    if ($ref !== "" && ctype_digit($ref) && $ref !== (string)$user_id) {
      $pdo->prepare("UPDATE users SET ref_by=? WHERE id=? AND ref_by IS NULL")->execute([(int)$ref, $user_id]);
    }

    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"👋 Welcome!\n\nStep 1: Join required channels\nStep 2: Tap ✅ Joined All Channels\nStep 3: Complete web verification",
      "reply_markup"=>json_encode(inlineForceJoinKeyboard())
    ]);
    fast200();
  }

  // Admin entry
  if ($text === "/admin" && isAdmin($user_id)) {
    setState($user_id, null);
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"👑 Admin Panel",
      "reply_markup"=>json_encode(adminKeyboard())
    ]);
    fast200();
  }

  // User menu
  if ($text === "User Menu") {
    setState($user_id, null);
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"✅ User Menu",
      "reply_markup"=>json_encode(mainMenuKeyboard())
    ]);
    fast200();
  }

  // ---------------- Admin STATE handling FIRST ----------------
  if (isAdmin($user_id)) {
    $st = getState($user_id);

    if ($st["s"] === "ADMIN_ADD_CHANNEL") {
      $ch = trim($text);
      if (!(strpos($ch, "@") === 0 || strpos($ch, "-100") === 0)) {
        bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Invalid. Send @channel OR -100xxxxxxxxxx"]);
        fast200();
      }
      setState($user_id, "ADMIN_ADD_CHANNEL_LINK", ["chat_id"=>$ch]);
      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"✅ Now send invite link.\n\nFor public channel: type <b>skip</b>\nFor private: paste full invite link (https://t.me/+xxxx)",
        "parse_mode"=>"HTML"
      ]);
      fast200();
    }

    if ($st["s"] === "ADMIN_ADD_CHANNEL_LINK") {
      $ch = $st["m"]["chat_id"] ?? null;
      if (!$ch) { setState($user_id, null); fast200(); }

      $link = trim($text);
      if (strtolower($link) === "skip") $link = null;

      if (strpos((string)$ch, "-100") === 0 && (!$link || stripos($link, "t.me/") === false)) {
        bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Private channel needs a valid invite link (https://t.me/+...)"]);
        fast200();
      }

      $stmt = $pdo->prepare("
        INSERT INTO force_channels(chat_id, invite_link, is_active)
        VALUES (?, ?, true)
        ON CONFLICT (chat_id) DO UPDATE SET invite_link=EXCLUDED.invite_link, is_active=true
      ");
      $stmt->execute([(string)$ch, $link]);

      setState($user_id, null);
      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"✅ Channel added/updated: " . (string)$ch,
        "reply_markup"=>json_encode(adminKeyboard())
      ]);
      fast200();
    }

    if ($st["s"] === "ADMIN_REMOVE_CHANNEL") {
      $ch = trim($text);
      $pdo->prepare("DELETE FROM force_channels WHERE chat_id=?")->execute([$ch]);
      setState($user_id, null);

      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"✅ Removed (if existed): $ch",
        "reply_markup"=>json_encode(adminKeyboard())
      ]);
      fast200();
    }

    if ($st["s"] === "ADMIN_ADD_COUPONS") {
      $type = $st["m"]["type"] ?? null;
      if (!$type) { setState($user_id, null); fast200(); }

      $lines = preg_split("/\r\n|\n|\r/", trim($text));
      $lines = array_values(array_filter(array_map('trim', $lines), fn($x)=>$x!==""));
      if (count($lines) === 0) {
        bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"Send codes line by line (not empty)."]);
        fast200();
      }

      $pdo->beginTransaction();
      $stmtIns = $pdo->prepare("INSERT INTO coupons(type, code, used) VALUES (?,?,false)");
      $stmtChk = $pdo->prepare("SELECT 1 FROM coupons WHERE code=? LIMIT 1");

      $added = 0;
      foreach ($lines as $code) {
        $stmtChk->execute([$code]);
        if ($stmtChk->fetchColumn()) continue;
        $stmtIns->execute([(string)$type, $code]);
        $added++;
      }
      $pdo->commit();

      setState($user_id, null);
      $stock = stockCount((string)$type);

      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"✅ Added $added coupon(s) for {$type}.\n📦 New stock: $stock",
        "reply_markup"=>json_encode(adminKeyboard())
      ]);
      fast200();
    }

    if ($st["s"] === "ADMIN_SET_POINTS") {
      $type = $st["m"]["type"] ?? null;
      if (!$type) { setState($user_id, null); fast200(); }

      $val = trim($text);
      if (!ctype_digit($val) || (int)$val < 0 || (int)$val > 100000) {
        bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"Send a valid number (0 - 100000)."]);
        fast200();
      }

      setWithdrawPoints((string)$type, (int)$val);
      setState($user_id, null);

      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"✅ Updated: {$type} now requires ⭐{$val} points.",
        "reply_markup"=>json_encode(adminKeyboard())
      ]);
      fast200();
    }

    // ---------------- Admin menu buttons ----------------
    if ($text === "Add Channel") {
      setState($user_id, "ADMIN_ADD_CHANNEL", null);
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"➕ Send channel:\n\n@publicchannel\nOR\n-1001234567890"]);
      fast200();
    }

    if ($text === "Remove Channel") {
      setState($user_id, "ADMIN_REMOVE_CHANNEL", null);
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"➖ Send channel to remove:\n\n@channel OR -100xxxx"]);
      fast200();
    }

    if ($text === "List Channels") {
      $rows = $pdo->query("SELECT chat_id, invite_link, is_active FROM force_channels ORDER BY id ASC")->fetchAll();
      if (!$rows) {
        bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"No channels added yet."]);
        fast200();
      }
      $msg = "📢 Force Join Channels:\n\n";
      foreach ($rows as $r) {
        $msg .= "• {$r['chat_id']} " . ($r['is_active'] ? "✅" : "❌") . "\n";
      }
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>$msg, "reply_markup"=>json_encode(adminKeyboard())]);
      fast200();
    }

    if ($text === "Add Coupon") {
      $inline = [];
      foreach ($REWARD_TYPES as $t) {
        $inline[] = [[ "text"=>"➕ {$t} off on {$t}", "callback_data"=>"admin_add_type:$t" ]];
      }
      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"Select coupon type to add:",
        "reply_markup"=>json_encode(["inline_keyboard"=>$inline])
      ]);
      fast200();
    }

    if ($text === "Stock") {
      $lines = ["📦 Current Stock:"];
      foreach ($REWARD_TYPES as $t) {
        $lines[] = "• {$t} off on {$t}: " . stockCount($t);
      }
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>implode("\n", $lines)]);
      fast200();
    }

    if ($text === "Redeems Log") {
      $rows = $pdo->query("SELECT username, coupon_type, points_used, created_at FROM redeem_logs ORDER BY created_at DESC LIMIT 10")->fetchAll();
      if (!$rows) {
        bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"No redeems yet."]);
        fast200();
      }
      $msg = "🧾 Last 10 Redeems:\n\n";
      foreach ($rows as $r) {
        $msg .= "• @{$r['username']} | {$r['coupon_type']} | ⭐{$r['points_used']} | {$r['created_at']}\n";
      }
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>$msg]);
      fast200();
    }

    if ($text === "Change Withdraw Points") {
      $inline = [];
      foreach ($REWARD_TYPES as $t) {
        $cur = getWithdrawPoints($t);
        $inline[] = [[ "text"=>"✏️ {$t} (current ⭐{$cur})", "callback_data"=>"admin_points_type:$t" ]];
      }
      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"Select which reward points to change:",
        "reply_markup"=>json_encode(["inline_keyboard"=>$inline])
      ]);
      fast200();
    }
  }

  // ---------------- User actions require verification ----------------
  if (in_array($text, ["Stats","Referral Link","Withdraw"], true)) {
    $stmt = $pdo->prepare("SELECT web_verified FROM users WHERE id=?");
    $stmt->execute([$user_id]);
    $verified = (bool)$stmt->fetchColumn();

    if (!$verified) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Please complete verification first.\n\nStart again: /start"]);
      fast200();
    }
    if (!isJoinedAll($user_id)) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ You must stay joined to all required channels.\n\nJoin again:\n/start"]);
      fast200();
    }
  }

  // Stats
  if ($text === "Stats") {
    $stmt = $pdo->prepare("
      SELECT
        points,
        (SELECT COUNT(*) FROM users WHERE ref_by=? AND credited_ref=true) AS refs
      FROM users
      WHERE id=?
    ");
    $stmt->execute([$user_id, $user_id]);
    $row = $stmt->fetch();

    $points = (int)($row["points"] ?? 0);
    $refs   = (int)($row["refs"] ?? 0);

    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"📊 Stats\n\n👥 Referrals: $refs\n⭐ Points: $points"
    ]);
    fast200();
  }

  // Referral Link
  if ($text === "Referral Link") {
    $link = "https://t.me/{$BOT_USERNAME}?start={$user_id}";
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"🔗 Your referral link:\n$link"]);
    fast200();
  }

  // Withdraw menu
  if ($text === "Withdraw") {
    $inline = [];
    foreach ($REWARD_TYPES as $t) {
      $stock = stockCount($t);
      $req = getWithdrawPoints($t);
      $inline[] = [[
        "text" => "{$t} off on {$t} (Stock: {$stock}) ⭐{$req}",
        "callback_data" => "wd:$t"
      ]];
    }

    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"🎁 Withdraw Rewards\n(⭐ shows required points)\n\nSelect an option:",
      "reply_markup"=>json_encode(["inline_keyboard"=>$inline])
    ]);
    fast200();
  }

  // Fallback
  bot("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"Type /start to begin" . (isAdmin($user_id) ? "\nType /admin for admin panel" : "")
  ]);
  fast200();

} catch (Throwable $e) {
  // CRITICAL: never crash webhook (prevents 502)
  fast200("OK");
}

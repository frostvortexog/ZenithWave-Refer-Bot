<?php
// ============================================================
// FINAL ENTERPRISE Referral Bot (Webhook) - PHP + Supabase
// Features:
// - Force join channels (admin managed via DB)
// - Web verification (token-based) + device lock (verify.php)
// - Referral credit after full verification
// - Auto-deduct points if referred user leaves (cron protected)
// - Withdraw with secure row locking + SKIP LOCKED
// - Admin panel: channels, coupons, stock, redeems, withdraw points
// - Fast response + safe ordering + no duplicate state bugs
// ============================================================

// ---------------- ENV ----------------
$BOT_TOKEN    = getenv("BOT_TOKEN");
$DB_URL       = getenv("DATABASE_URL");
$ADMIN_IDS    = array_filter(array_map('trim', explode(',', getenv("ADMIN_IDS") ?: "")));
$BOT_USERNAME = getenv("BOT_USERNAME");
$BASE_URL     = rtrim(getenv("BASE_URL") ?: "", "/");
$CRON_SECRET  = getenv("CRON_SECRET");

if (!$BOT_TOKEN || !$DB_URL || !$BOT_USERNAME || !$BASE_URL) {
  http_response_code(500);
  echo "Missing ENV";
  exit;
}

// Reward types
$REWARD_TYPES = ["500","1000","2000","4000"];

// ---------------- DB ----------------
$db = parse_url($DB_URL);
$dsn = "pgsql:host={$db['host']};port={$db['port']};dbname=" . ltrim($db['path'], '/');
$pdo = new PDO($dsn, $db['user'], $db['pass'], [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// ---------------- Telegram helper ----------------
function bot($method, $data = []) {
  global $BOT_TOKEN;
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
  return $res ? json_decode($res, true) : null;
}

// ---------------- Utils ----------------
function isAdmin($uid) {
  global $ADMIN_IDS;
  return in_array((string)$uid, $ADMIN_IDS, true);
}

function rl_ok($user_id, $bucket, $max = 10, $window = 10) {
  // In-memory throttle (fast). For multi-instance scaling you'd store in Redis/DB.
  static $mem = [];
  $now = time();
  $k = $user_id . ":" . $bucket;
  if (!isset($mem[$k])) $mem[$k] = [];
  $mem[$k] = array_values(array_filter($mem[$k], fn($t) => ($now - $t) < $window));
  if (count($mem[$k]) >= $max) return false;
  $mem[$k][] = $now;
  return true;
}

function ensureUser($user_id, $username) {
  global $pdo;
  $stmt = $pdo->prepare("
    INSERT INTO users(id, username)
    VALUES(?, ?)
    ON CONFLICT(id) DO UPDATE SET username=EXCLUDED.username
  ");
  $stmt->execute([$user_id, $username]);
}

function setState($user_id, $state, $meta = null) {
  global $pdo;
  $pdo->prepare("
    INSERT INTO settings(key, value)
    VALUES(?, ?)
    ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value
  ")->execute(["state_$user_id", json_encode(["s"=>$state, "m"=>$meta], JSON_UNESCAPED_SLASHES)]);
}

function getState($user_id) {
  global $pdo;
  $stmt = $pdo->prepare("SELECT value FROM settings WHERE key=?");
  $stmt->execute(["state_$user_id"]);
  $v = $stmt->fetchColumn();
  if (!$v) return ["s"=>null, "m"=>null];
  $j = json_decode($v, true);
  return is_array($j) ? $j : ["s"=>null, "m"=>null];
}

function clearState($user_id) {
  global $pdo;
  $pdo->prepare("DELETE FROM settings WHERE key=?")->execute(["state_$user_id"]);
}

function mainMenuKeyboard() {
  return [
    "keyboard" => [
      [["text"=>"Stats"], ["text"=>"Referral Link"]],
      [["text"=>"Withdraw"]],
    ],
    "resize_keyboard" => true
  ];
}

function adminKeyboard() {
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

// ---------------- Force join channels (DB) ----------------
function getForceChannels() {
  global $pdo;
  return $pdo->query("SELECT chat_id, invite_link FROM force_channels WHERE is_active=true ORDER BY id ASC")->fetchAll() ?: [];
}

function inlineForceJoinKeyboard() {
  $rows = getForceChannels();
  $kb = [];

  if (!$rows) {
    $kb[] = [[ "text"=>"✅ Joined All Channels", "callback_data"=>"check_join" ]];
    return ["inline_keyboard"=>$kb];
  }

  foreach ($rows as $r) {
    $chat_id = $r["chat_id"];
    $invite  = $r["invite_link"];

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

function isJoinedAll($user_id) {
  $rows = getForceChannels();
  if (!$rows) return true;

  foreach ($rows as $r) {
    $ch = $r["chat_id"];
    $res = bot("getChatMember", ["chat_id"=>$ch, "user_id"=>$user_id]);
    $status = $res["result"]["status"] ?? null;
    if (!in_array($status, ["member","administrator","creator"], true)) return false;
  }
  return true;
}

// ---------------- Withdraw points + stock ----------------
function getWithdrawPoints($type) {
  global $pdo;
  $stmt = $pdo->prepare("SELECT value FROM settings WHERE key=?");
  $stmt->execute(["points_$type"]);
  $v = $stmt->fetchColumn();
  return $v !== false ? (int)$v : 1;
}

function setWithdrawPoints($type, $points) {
  global $pdo;
  $pdo->prepare("
    INSERT INTO settings(key,value)
    VALUES(?,?)
    ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value
  ")->execute(["points_$type", (string)$points]);
}

function stockCount($type) {
  global $pdo;
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM coupons WHERE type=? AND used=false");
  $stmt->execute([$type]);
  return (int)$stmt->fetchColumn();
}

// ---------------- Web verify token ----------------
function generateToken($user_id) {
  global $pdo;
  $token = bin2hex(random_bytes(32));
  $expires = date("Y-m-d H:i:s", time() + 300); // 5 min
  $pdo->prepare("INSERT INTO verify_tokens(token, user_id, expires_at) VALUES(?,?,?)")
      ->execute([$token, $user_id, $expires]);
  return $token;
}

function inlineWebVerifyKeyboard($verifyUrl) {
  return ["inline_keyboard" => [
    [[ "text"=>"🌐 Verify Now", "url"=>$verifyUrl ]],
    [[ "text"=>"✅ Check Verification", "callback_data"=>"check_web" ]]
  ]];
}

// ============================================================
// CRON: auto-deduct points if referred user leaves channels
// URL: /index.php?cron=check_refs&key=CRON_SECRET
// ============================================================
if (isset($_GET["cron"]) && $_GET["cron"] === "check_refs") {
  if (!$CRON_SECRET || (($_GET["key"] ?? "") !== $CRON_SECRET)) {
    http_response_code(403);
    echo "forbidden";
    exit;
  }

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
}

// ---------------- Read update ----------------
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) exit;

$message  = $update["message"] ?? null;
$callback = $update["callback_query"] ?? null;

$user_id  = $message["from"]["id"] ?? $callback["from"]["id"] ?? null;
$username = $message["from"]["username"] ?? $callback["from"]["username"] ?? "NoUsername";
$chat_id  = $message["chat"]["id"] ?? $callback["message"]["chat"]["id"] ?? $user_id;

if (!$user_id) exit;

ensureUser($user_id, $username);

// ============================================================
// CALLBACKS
// ============================================================
if ($callback) {
  if (!rl_ok($user_id, "cb", 15, 10)) {
    bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Slow down"]);
    exit;
  }

  $data = $callback["data"] ?? "";

  // Joined channels
  if ($data === "check_join") {
    if (!isJoinedAll($user_id)) {
      bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Join all channels first"]);
      exit;
    }

    $token = generateToken($user_id);
    $verifyUrl = $GLOBALS["BASE_URL"] . "/verify.php?token=" . $token;

    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"🌐 Web Verification Required\n\n1) Tap Verify Now\n2) Complete verification\n3) Come back and tap Check Verification",
      "reply_markup"=>json_encode(inlineWebVerifyKeyboard($verifyUrl))
    ]);
    exit;
  }

  // Check web verification
  if ($data === "check_web") {
    if (!isJoinedAll($user_id)) {
      bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Join all channels first"]);
      exit;
    }

    $stmt = $pdo->prepare("SELECT web_verified, ref_by, credited_ref FROM users WHERE id=?");
    $stmt->execute([$user_id]);
    $u = $stmt->fetch();

    if (!$u || !$u["web_verified"]) {
      bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Not verified yet"]);
      exit;
    }

    // Credit referral ONCE after verified
    if (!$u["credited_ref"] && !empty($u["ref_by"])) {
      $refBy = (int)$u["ref_by"];
      if ($refBy !== (int)$user_id) {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET points = points + 1 WHERE id=?")->execute([$refBy]);
        $pdo->prepare("UPDATE users SET credited_ref=true WHERE id=?")->execute([$user_id]);
        $pdo->commit();
      } else {
        // self-ref
        $pdo->prepare("UPDATE users SET ref_by=NULL, credited_ref=false WHERE id=?")->execute([$user_id]);
      }
    }

    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"✅ Verification Completed!\n\nUse the menu below:",
      "reply_markup"=>json_encode(mainMenuKeyboard())
    ]);
    exit;
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
      exit;
    }
    if (!isJoinedAll($user_id)) {
      bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Join all channels first"]);
      exit;
    }

    $required = getWithdrawPoints($type);

    try {
      $pdo->beginTransaction();

      // Lock user row
      $stmtU = $pdo->prepare("SELECT points FROM users WHERE id=? FOR UPDATE");
      $stmtU->execute([$user_id]);
      $uu = $stmtU->fetch();
      if (!$uu) { $pdo->rollBack(); exit; }

      if ((int)$uu["points"] < $required) {
        $pdo->rollBack();
        bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Not enough points"]);
        exit;
      }

      // Get one coupon safely (SKIP LOCKED avoids waiting if another tx locked it)
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
        exit;
      }

      $pdo->prepare("UPDATE coupons SET used=true, used_by=? WHERE id=?")->execute([$user_id, $c["id"]]);
      $pdo->prepare("UPDATE users SET points = points - ? WHERE id=?")->execute([$required, $user_id]);
      $pdo->prepare("INSERT INTO redeem_logs(user_id, username, coupon_type, code, points_used) VALUES (?,?,?,?,?)")
          ->execute([$user_id, $username, $type, $c["code"], $required]);

      $pdo->commit();

      $safeCode = htmlspecialchars($c["code"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"🎉 Coupon Redeemed!\n\nType: {$type} off on {$type}\nPoints Used: {$required}\n\nCode:\n<code>{$safeCode}</code>",
        "parse_mode"=>"HTML"
      ]);

      // notify admins
      foreach ($ADMIN_IDS as $aid) {
        bot("sendMessage", [
          "chat_id"=>$aid,
          "text"=>"🔔 Withdraw Alert\nUser: @$username (ID: $user_id)\nReward: {$type} off on {$type}\nPoints Deducted: {$required}\nCode: {$c['code']}"
        ]);
      }

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Try again"]);
    }

    exit;
  }

  // Admin callbacks (coupon type + points type)
  if (isAdmin($user_id)) {
    if (strpos($data, "admin_add_type:") === 0) {
      $type = substr($data, strlen("admin_add_type:"));
      setState($user_id, "ADMIN_ADD_COUPONS", ["type"=>$type]);
      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"➕ Send coupon codes line by line for: {$type} off on {$type}\n\nExample:\nABC123\nDEF456\n..."
      ]);
      exit;
    }

    if (strpos($data, "admin_points_type:") === 0) {
      $type = substr($data, strlen("admin_points_type:"));
      setState($user_id, "ADMIN_SET_POINTS", ["type"=>$type]);
      $cur = getWithdrawPoints($type);
      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"✏️ Send required points for: {$type} off on {$type}\nCurrent: {$cur}\n\nSend a number (example: 5)"
      ]);
      exit;
    }
  }

  bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"OK"]);
  exit;
}

// ============================================================
// MESSAGES (TEXT)
// ============================================================
$text = $message["text"] ?? "";
if (!$text) exit;

if (!rl_ok($user_id, "msg", 12, 10)) exit;

// /start with referral param
if (strpos($text, "/start") === 0) {
  $parts = explode(" ", $text, 2);
  $ref = isset($parts[1]) ? trim($parts[1]) : null;

  if ($ref && ctype_digit($ref) && $ref !== (string)$user_id) {
    $pdo->prepare("UPDATE users SET ref_by=? WHERE id=? AND ref_by IS NULL")->execute([(int)$ref, $user_id]);
  }

  bot("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"👋 Welcome!\n\nStep 1: Join required channels\nStep 2: Tap ✅ Joined All Channels\nStep 3: Complete web verification",
    "reply_markup"=>json_encode(inlineForceJoinKeyboard())
  ]);
  exit;
}

// ---------------- Admin Panel entry ----------------
if ($text === "/admin" && isAdmin($user_id)) {
  clearState($user_id);
  bot("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"👑 Admin Panel",
    "reply_markup"=>json_encode(adminKeyboard())
  ]);
  exit;
}

// Switch to user menu
if ($text === "User Menu") {
  clearState($user_id);
  bot("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"✅ User Menu",
    "reply_markup"=>json_encode(mainMenuKeyboard())
  ]);
  exit;
}

// ---------------- Admin state handling FIRST ----------------
if (isAdmin($user_id)) {
  $st = getState($user_id);

  // Add Channel flow
  if ($st["s"] === "ADMIN_ADD_CHANNEL") {
    $ch = trim($text);

    if (!(strpos($ch, "@") === 0 || strpos($ch, "-100") === 0)) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Invalid. Send @channel OR -100xxxxxxxxxx"]);
      exit;
    }

    setState($user_id, "ADMIN_ADD_CHANNEL_LINK", ["chat_id"=>$ch]);
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"✅ Now send invite link.\n\nFor public channel: type <b>skip</b>\nFor private: paste full invite link (https://t.me/+xxxx)",
      "parse_mode"=>"HTML"
    ]);
    exit;
  }

  if ($st["s"] === "ADMIN_ADD_CHANNEL_LINK") {
    $ch = $st["m"]["chat_id"] ?? null;
    if (!$ch) { clearState($user_id); exit; }

    $link = trim($text);
    if (strtolower($link) === "skip") $link = null;

    if (strpos($ch, "-100") === 0 && (!$link || stripos($link, "t.me/") === false)) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Private channel needs a valid invite link (https://t.me/+...)"]);
      exit;
    }

    $stmt = $pdo->prepare("
      INSERT INTO force_channels(chat_id, invite_link, is_active)
      VALUES (?, ?, true)
      ON CONFLICT (chat_id) DO UPDATE SET invite_link=EXCLUDED.invite_link, is_active=true
    ");
    $stmt->execute([$ch, $link]);

    clearState($user_id);
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"✅ Channel added/updated: $ch",
      "reply_markup"=>json_encode(adminKeyboard())
    ]);
    exit;
  }

  if ($st["s"] === "ADMIN_REMOVE_CHANNEL") {
    $ch = trim($text);
    $pdo->prepare("DELETE FROM force_channels WHERE chat_id=?")->execute([$ch]);
    clearState($user_id);

    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"✅ Removed (if existed): $ch",
      "reply_markup"=>json_encode(adminKeyboard())
    ]);
    exit;
  }

  // Admin add coupons (expects lines)
  if ($st["s"] === "ADMIN_ADD_COUPONS") {
    $type = $st["m"]["type"] ?? null;
    if (!$type) { clearState($user_id); exit; }

    $lines = preg_split("/\r\n|\n|\r/", trim($text));
    $lines = array_values(array_filter(array_map('trim', $lines), fn($x)=>$x!==""));

    if (count($lines) === 0) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"Send codes line by line (not empty)."]);
      exit;
    }

    $pdo->beginTransaction();
    $stmtIns = $pdo->prepare("INSERT INTO coupons(type, code, used) VALUES (?,?,false)");
    $stmtChk = $pdo->prepare("SELECT 1 FROM coupons WHERE code=? LIMIT 1");

    $added = 0;
    foreach ($lines as $code) {
      $stmtChk->execute([$code]);
      if ($stmtChk->fetchColumn()) continue;
      $stmtIns->execute([$type, $code]);
      $added++;
    }
    $pdo->commit();

    clearState($user_id);
    $stock = stockCount($type);

    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"✅ Added $added coupon(s) for {$type}.\n📦 New stock: $stock",
      "reply_markup"=>json_encode(adminKeyboard())
    ]);
    exit;
  }

  // Admin set withdraw points
  if ($st["s"] === "ADMIN_SET_POINTS") {
    $type = $st["m"]["type"] ?? null;
    if (!$type) { clearState($user_id); exit; }

    $val = trim($text);
    if (!ctype_digit($val) || (int)$val < 0 || (int)$val > 100000) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"Send a valid number (0 - 100000)."]);
      exit;
    }

    setWithdrawPoints($type, (int)$val);
    clearState($user_id);

    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"✅ Updated: {$type} now requires ⭐{$val} points.",
      "reply_markup"=>json_encode(adminKeyboard())
    ]);
    exit;
  }

  // Admin menu buttons (only if NOT in a state)
  if ($text === "Add Channel") {
    clearState($user_id);
    setState($user_id, "ADMIN_ADD_CHANNEL", null);
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"➕ Send channel:\n\n@publicchannel\nOR\n-1001234567890"]);
    exit;
  }

  if ($text === "Remove Channel") {
    clearState($user_id);
    setState($user_id, "ADMIN_REMOVE_CHANNEL", null);
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"➖ Send channel to remove:\n\n@channel OR -100xxxx"]);
    exit;
  }

  if ($text === "List Channels") {
    $rows = $pdo->query("SELECT chat_id, invite_link, is_active FROM force_channels ORDER BY id ASC")->fetchAll();
    if (!$rows) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"No channels added yet."]);
      exit;
    }
    $msg = "📢 Force Join Channels:\n\n";
    foreach ($rows as $r) {
      $msg .= "• {$r['chat_id']} " . ($r['is_active'] ? "✅" : "❌") . "\n";
    }
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>$msg]);
    exit;
  }

  if ($text === "Add Coupon") {
    global $REWARD_TYPES;
    clearState($user_id);
    $inline = [];
    foreach ($GLOBALS["REWARD_TYPES"] as $t) {
      $inline[] = [[ "text"=>"➕ {$t} off on {$t}", "callback_data"=>"admin_add_type:$t" ]];
    }
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"Select coupon type to add:",
      "reply_markup"=>json_encode(["inline_keyboard"=>$inline])
    ]);
    exit;
  }

  if ($text === "Stock") {
    $lines = ["📦 Current Stock:"];
    foreach ($GLOBALS["REWARD_TYPES"] as $t) {
      $lines[] = "• {$t} off on {$t}: " . stockCount($t);
    }
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>implode("\n", $lines)]);
    exit;
  }

  if ($text === "Redeems Log") {
    $rows = $pdo->query("SELECT username, coupon_type, points_used, created_at FROM redeem_logs ORDER BY created_at DESC LIMIT 10")->fetchAll();
    if (!$rows) {
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"No redeems yet."]);
      exit;
    }
    $msg = "🧾 Last 10 Redeems:\n\n";
    foreach ($rows as $r) {
      $msg .= "• @{$r['username']} | {$r['coupon_type']} | ⭐{$r['points_used']} | {$r['created_at']}\n";
    }
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>$msg]);
    exit;
  }

  if ($text === "Change Withdraw Points") {
    clearState($user_id);
    $inline = [];
    foreach ($GLOBALS["REWARD_TYPES"] as $t) {
      $cur = getWithdrawPoints($t);
      $inline[] = [[ "text"=>"✏️ {$t} (current ⭐{$cur})", "callback_data"=>"admin_points_type:$t" ]];
    }
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"Select which reward points to change:",
      "reply_markup"=>json_encode(["inline_keyboard"=>$inline])
    ]);
    exit;
  }
}

// ---------------- User actions require verification ----------------
if (in_array($text, ["Stats","Referral Link","Withdraw"], true)) {
  $stmt = $pdo->prepare("SELECT web_verified FROM users WHERE id=?");
  $stmt->execute([$user_id]);
  $verified = (bool)$stmt->fetchColumn();

  if (!$verified) {
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Please complete verification first.\n\nStart again: /start"]);
    exit;
  }
  if (!isJoinedAll($user_id)) {
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ You must stay joined to all required channels.\n\nJoin again:\n/start"]);
    exit;
  }
}

// Stats
if ($text === "Stats") {
  // referrals credited = users where ref_by = me AND credited_ref=true
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
  exit;
}

// Referral Link
if ($text === "Referral Link") {
  $link = "https://t.me/{$BOT_USERNAME}?start={$user_id}";
  bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"🔗 Your referral link:\n$link"]);
  exit;
}

// Withdraw menu
if ($text === "Withdraw") {
  $inline = [];
  foreach ($GLOBALS["REWARD_TYPES"] as $t) {
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
  exit;
}

// Fallback
bot("sendMessage", [
  "chat_id"=>$chat_id,
  "text"=>"Type /start to begin" . (isAdmin($user_id) ? "\nType /admin for admin panel" : "")
]);
exit;

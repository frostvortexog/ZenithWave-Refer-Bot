  <?php
  // =====================
  // ULTRA SECURE ENTERPRISE Referral Bot (Webhook)
  // - 3 Force Join Channels
  // - Token based web verification (verify.php)
  // - Device lock (1 device => 1 TG ID)
  // - Referral points credit + auto deduction (cron)
  // - User Menu: Stats / Referral Link / Withdraw
  // - Admin Panel: Add Coupon / Stock / Redeem Log / Change Withdraw Points
  // - Secure withdraw with FOR UPDATE (no double redeem)
  // - Rate limiting (basic)
  // =====================
  
  // ---------- ENV ----------
  $BOT_TOKEN = getenv("BOT_TOKEN");
  $DB_URL = getenv("DATABASE_URL");
  $ADMIN_IDS = array_filter(array_map('trim', explode(',', getenv("ADMIN_IDS") ?: "")));
  $BOT_USERNAME = getenv("BOT_USERNAME");
  $BASE_URL = rtrim(getenv("BASE_URL"), '/');
  $CRON_SECRET = getenv("CRON_SECRET");
  
  $FORCE_CHANNELS = array_filter(array_map('trim', explode(',', getenv("FORCE_CHANNELS") ?: "")));
  $FORCE_JOIN_LINK = getenv("FORCE_JOIN_LINK"); // invite link for private channel
  
  if (!$BOT_TOKEN || !$DB_URL || !$BOT_USERNAME || !$BASE_URL) {
    http_response_code(500);
    echo "Missing ENV";
    exit;
  }
  
  // ---------- DB ----------
  $db = parse_url($DB_URL);
  $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname=" . ltrim($db['path'], '/');
  $pdo = new PDO($dsn, $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  
  // ---------- Telegram helper ----------
  function bot($method, $data = []) {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $data,
      CURLOPT_CONNECTTIMEOUT => 2,
      CURLOPT_TIMEOUT => 6,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res ? json_decode($res, true) : null;
  }
  
  // ---------- Utils ----------
  function isAdmin($uid) {
    global $ADMIN_IDS;
    return in_array((string)$uid, $ADMIN_IDS, true);
  }
  
  function mainMenuKeyboard() {
    return [
      "keyboard" => [
        [["text" => "Stats"], ["text" => "Referral Link"]],
        [["text" => "Withdraw"]],
      ],
      "resize_keyboard" => true
    ];
  }
  
function adminKeyboard() {
  return [
    "keyboard" => [
      [["text" => "Add Coupon"], ["text" => "Stock"]],
      [["text" => "Redeems Log"], ["text" => "Change Withdraw Points"]],
      [["text" => "Force Channels"]], // ✅ NEW
      [["text" => "User Menu"]],
    ],
    "resize_keyboard" => true
  ];
}
  
  
  function inlineWebVerifyKeyboard($verifyUrl) {
    return ["inline_keyboard" => [
      [[ "text" => "🌐 Verify Now", "url" => $verifyUrl ]],
      [[ "text" => "✅ Check Verification", "callback_data" => "check_web" ]]
    ]];
  }
  
  function getWithdrawPoints($type) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE key=?");
    $stmt->execute(["points_$type"]);
    $v = $stmt->fetchColumn();
    return $v ? (int)$v : 1;
  }
  
  function setWithdrawPoints($type, $points) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value");
    $stmt->execute(["points_$type", (string)$points]);
  }
  
  function stockCount($type) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM coupons WHERE type=? AND used=false");
    $stmt->execute([$type]);
    return (int)$stmt->fetchColumn();
  }

 function getForceChannels() {
  global $pdo;
  $rows = $pdo->query("SELECT chat_id, invite_link FROM force_channels WHERE is_active=true ORDER BY id ASC")->fetchAll();
  return $rows ?: [];
}

function inlineForceJoinKeyboard() {
  $rows = getForceChannels();
  $kb = [];

  if (!$rows) {
    // If admin forgot to add channels, still allow start (or change to block)
    $kb[] = [[ "text" => "✅ Joined All Channels", "callback_data" => "check_join" ]];
    return ["inline_keyboard" => $kb];
  }

  foreach ($rows as $r) {
    $chat_id = $r["chat_id"];
    $invite  = $r["invite_link"];

    if (strpos($chat_id, "@") === 0) {
      $url = $invite ?: ("https://t.me/" . str_replace("@", "", $chat_id));
      $kb[] = [[ "text" => "📢 Join $chat_id", "url" => $url ]];
    } else {
      $kb[] = [[ "text" => "📢 Join Private Channel", "url" => $invite ?: "https://t.me/" ]];
    }
  }

  $kb[] = [[ "text" => "✅ Joined All Channels", "callback_data" => "check_join" ]];
  return ["inline_keyboard" => $kb];
}

function isJoinedAll($user_id) {
  $rows = getForceChannels();
  if (!$rows) return true;

  foreach ($rows as $r) {
    $ch = $r["chat_id"];
    $res = bot("getChatMember", ["chat_id" => $ch, "user_id" => $user_id]);
    $status = $res["result"]["status"] ?? null;
    if (!in_array($status, ["member", "administrator", "creator"], true)) return false;
  }
  return true;
}
  
  function rateLimitOk($user_id, $bucket, $max = 8, $windowSec = 10) {
    // Basic per-process throttling is not reliable across instances.
    // We use DB-less minimal approach. For enterprise scaling, store in Redis or DB.
    // Still helps reduce spam locally.
    static $mem = [];
    $now = time();
    $key = $user_id . ":" . $bucket;
    if (!isset($mem[$key])) $mem[$key] = [];
    $mem[$key] = array_values(array_filter($mem[$key], fn($t) => ($now - $t) < $windowSec));
    if (count($mem[$key]) >= $max) return false;
    $mem[$key][] = $now;
    return true;
  }
  
  
  function ensureUser($user_id, $username) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO users(id,username) VALUES(?,?) ON CONFLICT(id) DO UPDATE SET username=EXCLUDED.username");
    $stmt->execute([$user_id, $username]);
  }
  
  function setState($user_id, $state, $meta = null) {
    global $pdo;
    $pdo->prepare("INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value")
        ->execute(["state_$user_id", json_encode(["s"=>$state,"m"=>$meta], JSON_UNESCAPED_SLASHES)]);
  }
  
  function getState($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE key=?");
    $stmt->execute(["state_$user_id"]);
    $v = $stmt->fetchColumn();
    if (!$v) return ["s"=>null,"m"=>null];
    $j = json_decode($v, true);
    return is_array($j) ? $j : ["s"=>null,"m"=>null];
  }
  
  function clearState($user_id) {
    global $pdo;
    $pdo->prepare("DELETE FROM settings WHERE key=?")->execute(["state_$user_id"]);
  }
  
  function generateToken($user_id) {
    global $pdo;
    $token = bin2hex(random_bytes(32));
    $expires = date("Y-m-d H:i:s", time() + 300); // 5 min
    $pdo->prepare("INSERT INTO verify_tokens(token,user_id,expires_at) VALUES(?,?,?)")
        ->execute([$token, $user_id, $expires]);
    return $token;
  }
  
  // ---------- CRON: auto-deduct points if referred user leaves ----------
  if (isset($_GET["cron"]) && $_GET["cron"] === "check_refs") {
    if (!$CRON_SECRET || ($_GET["key"] ?? "") !== $CRON_SECRET) {
      http_response_code(403); echo "forbidden"; exit;
    }
  
    // Find users who were credited and have ref_by set, but are no longer in all channels.
    $rows = $pdo->query("SELECT id, ref_by, credited_ref FROM users WHERE ref_by IS NOT NULL")->fetchAll();
  
    $deducted = 0;
    foreach ($rows as $r) {
      $uid = (int)$r["id"];
      $refBy = (int)$r["ref_by"];
      $credited = (bool)$r["credited_ref"];
  
      // Only deduct if they were credited previously
      if (!$credited) continue;
  
      if (!isJoinedAll($uid)) {
        // Deduct 1 point from referrer (never go below 0)
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET points = GREATEST(points - 1, 0) WHERE id=?")->execute([$refBy]);
        // Mark as not credited anymore and detach ref_by to prevent repeated deductions
        $pdo->prepare("UPDATE users SET credited_ref=false, ref_by=NULL WHERE id=?")->execute([$uid]);
        $pdo->commit();
        $deducted++;
      }
    }
  
    echo "OK deducted=$deducted";
    exit;
  }
  
  // ---------- Read update ----------
  $update = json_decode(file_get_contents("php://input"), true);
  if (!$update) exit;
  
  $message = $update["message"] ?? null;
  $callback = $update["callback_query"] ?? null;
  
  $user_id = $message["from"]["id"] ?? $callback["from"]["id"] ?? null;
  $username = $message["from"]["username"] ?? $callback["from"]["username"] ?? "NoUsername";
  $chat_id = $message["chat"]["id"] ?? $callback["message"]["chat"]["id"] ?? $user_id;
  
  if (!$user_id) exit;
  
  ensureUser($user_id, $username);
  
  // ---------- Handle callbacks ----------
  if ($callback) {
    if (!rateLimitOk($user_id, "cb", 12, 10)) {
      bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Slow down"]);
      exit;
    }
  
    $data = $callback["data"] ?? "";
  
    // Check join
    if ($data === "check_join") {
      if (!isJoinedAll($user_id)) {
        bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Join all channels first"]);
        exit;
      }
  
      $token = generateToken($user_id);
      $verifyUrl = $GLOBALS["BASE_URL"] . "/verify.php?token=" . $token;
  
      bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "🌐 Web Verification Required\n\n1) Tap **Verify Now**\n2) Complete verification\n3) Come back and tap **Check Verification**",
        "parse_mode" => "Markdown",
        "reply_markup" => json_encode(inlineWebVerifyKeyboard($verifyUrl))
      ]);
      exit;
    }
  
    // Check web verification
    if ($data === "check_web") {
      // Must still be joined
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
  
      // Credit referral only once
      if (!$u["credited_ref"] && !empty($u["ref_by"])) {
        $refBy = (int)$u["ref_by"];
        if ($refBy !== (int)$user_id) { // anti self
          // Optional: anti-same-device / anti-same-ip is enforced in verify.php device lock;
          // Additionally, prevent credit if referrer = 0 / invalid
          $pdo->beginTransaction();
          $pdo->prepare("UPDATE users SET points = points + 1 WHERE id=?")->execute([$refBy]);
          $pdo->prepare("UPDATE users SET credited_ref=true WHERE id=?")->execute([$user_id]);
          $pdo->commit();
        } else {
          // self-ref: clear
          $pdo->prepare("UPDATE users SET ref_by=NULL, credited_ref=false WHERE id=?")->execute([$user_id]);
        }
      }
  
      bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "✅ Verification Completed!\n\nUse the menu below:",
        "reply_markup" => json_encode(mainMenuKeyboard())
      ]);
      exit;
    }
  
    // Withdraw selection callbacks
    if (strpos($data, "wd:") === 0) {
      $type = substr($data, 3); // 500/1000/2000/4000
  
      // Must be verified and joined
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
      $points = (int)$u["points"];
      $stock = stockCount($type);
  
      if ($stock <= 0) {
        bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Out of stock"]);
        exit;
      }
      if ($points < $required) {
        bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Not enough referrals/points"]);
        exit;
      }
  
      // Secure withdraw with row locking
      try {
        $pdo->beginTransaction();
  
        // Lock user row
        $stmtU = $pdo->prepare("SELECT points, username FROM users WHERE id=? FOR UPDATE");
        $stmtU->execute([$user_id]);
        $uu = $stmtU->fetch();
        if (!$uu) { $pdo->rollBack(); exit; }
  
        $pointsNow = (int)$uu["points"];
        if ($pointsNow < $required) {
          $pdo->rollBack();
          bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Not enough points"]);
          exit;
        }
  
        // Lock one coupon row
        $stmtC = $pdo->prepare("SELECT id, code FROM coupons WHERE type=? AND used=false LIMIT 1 FOR UPDATE");
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
  
        bot("sendMessage", [
          "chat_id" => $chat_id,
          "text" => "🎉 Coupon Redeemed!\n\n✅ Type: {$type} off on {$type}\n⭐ Points Used: {$required}\n\n🧾 Code:\n`{$c['code']}`",
          "parse_mode" => "Markdown"
        ]);
  
        // Notify admins
        foreach ($ADMIN_IDS as $aid) {
          bot("sendMessage", [
            "chat_id" => $aid,
            "text" => "🔔 Withdraw Alert\nUser: @$username (ID: $user_id)\nReward: {$type} off on {$type}\nPoints Deducted: {$required}\nCode: {$c['code']}"
          ]);
        }
  
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"Try again"]);
      }
  
      exit;
    }
  
    // Admin: select coupon type to add
    if (strpos($data, "admin_add_type:") === 0 && isAdmin($user_id)) {
      $type = substr($data, strlen("admin_add_type:"));
      setState($user_id, "ADMIN_ADD_COUPONS", ["type"=>$type]);
  
      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"➕ Send coupon codes line by line for: {$type} off on {$type}\n\nExample:\nABC123\nDEF456\n...",
      ]);
      exit;
    }
  
    // Admin: change points type
    if (strpos($data, "admin_points_type:") === 0 && isAdmin($user_id)) {
      $type = substr($data, strlen("admin_points_type:"));
      setState($user_id, "ADMIN_SET_POINTS", ["type"=>$type]);
  
      $current = getWithdrawPoints($type);
      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"✏️ Send required points for: {$type} off on {$type}\nCurrent: {$current}\n\nSend a number (example: 5)"
      ]);
      exit;
    }
  
    // Unknown callback
    bot("answerCallbackQuery", ["callback_query_id"=>$callback["id"], "text"=>"OK"]);
    exit;
  }
  
  // ---------- Handle messages (text) ----------
  $text = $message["text"] ?? "";
  if ($text) {
    if (!rateLimitOk($user_id, "msg", 10, 10)) exit;
  
    // /start with referral
    if (strpos($text, "/start") === 0) {
      $parts = explode(" ", $text, 2);
      $ref = isset($parts[1]) ? trim($parts[1]) : null;
  
      if ($ref && ctype_digit($ref) && $ref !== (string)$user_id) {
        // Save ref if not already set
        $pdo->prepare("UPDATE users SET ref_by=? WHERE id=? AND ref_by IS NULL")
            ->execute([(int)$ref, $user_id]);
      }
  
      bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "👋 Welcome!\n\n✅ Step 1: Join all 1 channels\n✅ Step 2: Tap *Joined All Channels*\n✅ Step 3: Complete web verification",
        "parse_mode" => "Markdown",
        "reply_markup" => json_encode(inlineForceJoinKeyboard())
      ]);
      exit;
    }
  
    // Admin entry
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
    if ($text === "User Menu" && isAdmin($user_id)) {
      clearState($user_id);
      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"✅ User Menu",
        "reply_markup"=>json_encode(mainMenuKeyboard())
      ]);
      exit;
    }
  
    // User actions require verification
    if (in_array($text, ["Stats","Referral Link","Withdraw"], true)) {
      $stmt = $pdo->prepare("SELECT web_verified FROM users WHERE id=?");
      $stmt->execute([$user_id]);
      $verified = (bool)$stmt->fetchColumn();
  
      if (!$verified) {
        bot("sendMessage", [
          "chat_id"=>$chat_id,
          "text"=>"❌ Please complete verification first.\n\nStart again: /start",
        ]);
        exit;
      }
      if (!isJoinedAll($user_id)) {
        bot("sendMessage", [
          "chat_id"=>$chat_id,
          "text"=>"❌ You must stay joined to all channels.\n\nJoin again and verify:\n/start",
        ]);
        exit;
      }
    }
  
    // Stats
  if ($text === "Stats") {
      $stmt = $pdo->prepare("
          SELECT 
              points,
              (SELECT COUNT(*) FROM users WHERE ref_by=?) AS refs
          FROM users
          WHERE id=?
      ");
      $stmt->execute([$user_id, $user_id]); // ✅ FIXED (2 values only)
      $row = $stmt->fetch();
  
      $points = (int)($row["points"] ?? 0);
      $refs   = (int)($row["refs"] ?? 0);
  
      bot("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "📊 Stats\n\n👥 Referrals: $refs\n⭐ Points: $points"
      ]);
      exit;
  }
  
    // Referral Link
    if ($text === "Referral Link") {
      $link = "https://t.me/{$BOT_USERNAME}?start={$user_id}";
      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"🔗 Your referral link:\n$link"
      ]);
      exit;
    }
  
    // Withdraw menu
    if ($text === "Withdraw") {
      $types = ["500","1000","2000","4000"];
      $inline = [];
  
      foreach ($types as $t) {
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
  
    // ================= ADMIN MENU =================
    if (isAdmin($user_id)) {

      // Force Channels
if ($text === "Force Channels") {
  clearState($user_id);

  $rows = $pdo->query("SELECT id, chat_id, invite_link, is_active FROM force_channels ORDER BY id ASC")->fetchAll();
  $msg = "📢 Force Join Channels:\n\n";
  if (!$rows) {
    $msg .= "No channels added yet.\n";
  } else {
    foreach ($rows as $r) {
      $msg .= "• {$r['chat_id']} " . ($r['is_active'] ? "✅" : "❌") . "\n";
    }
  }

  $msg .= "\nSend:\n";
  $msg .= "➕ addchannel\n";
  $msg .= "➖ removechannel\n";

  bot("sendMessage", [
    "chat_id" => $chat_id,
    "text" => $msg,
    "reply_markup" => json_encode(adminKeyboard())
  ]);
  exit;
}

// Add Channel (accept both)
if ($text === "addchannel" || $text === "add channel" || $text === "Add Channel") {
  setState($user_id, "ADMIN_ADD_CHANNEL", null);
  bot("sendMessage", [
    "chat_id" => $chat_id,
    "text" => "➕ Send channel:\n\n@publicchannel\nOR\n-1001234567890\n\nThen I will ask invite link (type 'skip' for public)."
  ]);
  exit;
}

// Remove Channel (accept both)
if ($text === "removechannel" || $text === "remove channel" || $text === "Remove Channel") {
  setState($user_id, "ADMIN_REMOVE_CHANNEL", null);
  bot("sendMessage", [
    "chat_id" => $chat_id,
    "text" => "➖ Send channel to remove:\n\n@channel OR -100xxxx"
  ]);
  exit;
}
  
      // Add Coupon
      if ($text === "Add Coupon") {
        clearState($user_id);
        $types=["500","1000","2000","4000"];
        $inline=[];
        foreach($types as $t){
          $inline[]=[[ "text"=>"➕ {$t} off on {$t}", "callback_data"=>"admin_add_type:$t" ]];
        }
        bot("sendMessage",[
          "chat_id"=>$chat_id,
          "text"=>"Select coupon type to add:",
          "reply_markup"=>json_encode(["inline_keyboard"=>$inline])
        ]);
        exit;
      }
  
      // Stock
      if ($text === "Stock") {
        $types=["500","1000","2000","4000"];
        $lines=["📦 Current Stock:"];
        foreach($types as $t){
          $lines[]="• {$t} off on {$t}: ".stockCount($t);
        }
        bot("sendMessage",[
          "chat_id"=>$chat_id,
          "text"=>implode("\n",$lines)
        ]);
        exit;
      }
  
      // Redeems Log
      if ($text === "Redeems Log") {
        $rows = $pdo->query("SELECT username, coupon_type, points_used, created_at FROM redeem_logs ORDER BY created_at DESC LIMIT 10")->fetchAll();
        if (!$rows) {
          bot("sendMessage",["chat_id"=>$chat_id,"text"=>"No redeems yet."]);
          exit;
        }
        $msg="🧾 Last 10 Redeems:\n\n";
        foreach($rows as $r){
          $msg .= "• @{$r['username']} | {$r['coupon_type']} | ⭐{$r['points_used']} | {$r['created_at']}\n";
        }
        bot("sendMessage",["chat_id"=>$chat_id,"text"=>$msg]);
        exit;
      }
  
      // Change Withdraw Points
      if ($text === "Change Withdraw Points") {
        clearState($user_id);
        $types=["500","1000","2000","4000"];
        $inline=[];
        foreach($types as $t){
          $cur=getWithdrawPoints($t);
          $inline[]=[[ "text"=>"✏️ {$t} (current ⭐{$cur})", "callback_data"=>"admin_points_type:$t" ]];
        }
        bot("sendMessage",[
          "chat_id"=>$chat_id,
          "text"=>"Select which reward points to change:",
          "reply_markup"=>json_encode(["inline_keyboard"=>$inline])
        ]);
        exit;
      }
  
     // ADMIN: Add channel step 1
if ($st["s"] === "ADMIN_ADD_CHANNEL") {
  $ch = trim($text);

  if (!(strpos($ch, "@") === 0 || strpos($ch, "-100") === 0)) {
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Invalid. Send @channel or -100xxxx"]);
    exit;
  }

  setState($user_id, "ADMIN_ADD_CHANNEL_LINK", ["chat_id"=>$ch]);
  bot("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"✅ Now send invite link.\n\nType: skip (for public)\nOr paste invite link (for private): https://t.me/+xxxxx"
  ]);
  exit;
}

// ADMIN: Add channel step 2 (invite link)
if ($st["s"] === "ADMIN_ADD_CHANNEL_LINK") {
  $ch = $st["m"]["chat_id"] ?? null;
  if (!$ch) { clearState($user_id); exit; }

  $link = trim($text);
  if (strtolower($link) === "skip") $link = null;

  if (strpos($ch, "-100") === 0 && (!$link || stripos($link, "t.me/") === false)) {
    bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"❌ Private channel needs valid invite link (https://t.me/+...)"]);
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

// ADMIN: Remove channel
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
      
      // State-based admin input handler
      $st = getState($user_id);
      if ($st["s"] === "ADMIN_ADD_COUPONS") {
        $type = $st["m"]["type"] ?? null;
        if (!$type) { clearState($user_id); exit; }
  
        // Add line-by-line codes
        $lines = preg_split("/\r\n|\n|\r/", trim($text));
        $lines = array_values(array_filter(array_map('trim', $lines), fn($x)=>$x!==""));
  
        if (count($lines) === 0) {
          bot("sendMessage",["chat_id"=>$chat_id,"text"=>"Send codes line by line (not empty)."]);
          exit;
        }
  
        // Insert
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO coupons(type,code,used) VALUES (?,?,false)");
        $added = 0;
        foreach($lines as $code){
          // avoid duplicates (optional): skip if already exists
          $chk = $pdo->prepare("SELECT 1 FROM coupons WHERE code=? LIMIT 1");
          $chk->execute([$code]);
          if ($chk->fetchColumn()) continue;
  
          $stmt->execute([$type,$code]);
          $added++;
        }
        $pdo->commit();
  
        clearState($user_id);
        $stock = stockCount($type);
  
        bot("sendMessage",[
          "chat_id"=>$chat_id,
          "text"=>"✅ Added $added coupon(s) for {$type}.\n📦 New stock: $stock",
          "reply_markup"=>json_encode(adminKeyboard())
        ]);
        exit;
      }
  
      if ($st["s"] === "ADMIN_SET_POINTS") {
        $type = $st["m"]["type"] ?? null;
        if (!$type) { clearState($user_id); exit; }
  
        $val = trim($text);
        if (!ctype_digit($val) || (int)$val < 0 || (int)$val > 100000) {
          bot("sendMessage",["chat_id"=>$chat_id,"text"=>"Send a valid number (0 - 100000)."]);
          exit;
        }
  
        setWithdrawPoints($type, (int)$val);
        clearState($user_id);
  
        bot("sendMessage",[
          "chat_id"=>$chat_id,
          "text"=>"✅ Updated: {$type} now requires ⭐{$val} points.",
          "reply_markup"=>json_encode(adminKeyboard())
        ]);
        exit;
      }
    }
  
    // Fallback
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"Type /start to begin" . (isAdmin($user_id) ? "\nType /admin for admin panel" : "")
    ]);
    exit;
  }

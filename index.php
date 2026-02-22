<?php
// =====================================================
// TELEGRAM REFERRAL BOT (Webhook) - FULL WORKING
// PHP + Render + Supabase Postgres (PDO)
// - Force Join (Admin can add channels dynamically)
// - Web Verification (verify.php)
// - Referral points add on verified + joined
// - Auto deduct if referred user leaves (safe + once)
// - Withdraw coupons with stock + points
// - Admin panel: Add coupon, Stock, Redeems log, Change points, Add channels
// =====================================================

// ---------------- ENV ----------------
$BOT_TOKEN    = getenv("BOT_TOKEN");
$DATABASE_URL = getenv("DATABASE_URL");
$ADMIN_IDS    = array_filter(array_map('trim', explode(',', getenv("ADMIN_IDS") ?: "")));
$BOT_USERNAME = getenv("BOT_USERNAME");
$BASE_URL     = rtrim(getenv("BASE_URL"), "/");

if (!$BOT_TOKEN || !$DATABASE_URL || !$BOT_USERNAME || !$BASE_URL) {
  http_response_code(500);
  exit("Missing ENV");
}

// ---------------- DB ----------------
$db = parse_url($DATABASE_URL);
$host = $db["host"] ?? "";
$port = $db["port"] ?? "5432";
$user = $db["user"] ?? "";
$pass = $db["pass"] ?? "";
$dbname = ltrim($db["path"] ?? "", "/");

$pdo = new PDO(
  "pgsql:host=$host;port=$port;dbname=$dbname",
  $user,
  $pass,
  [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]
);

// ---------------- Telegram API ----------------
function bot($method, $data = []) {
  global $BOT_TOKEN;
  $url = "https://api.telegram.org/bot$BOT_TOKEN/$method";
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
  curl_setopt($ch, CURLOPT_TIMEOUT, 6);
  $res = curl_exec($ch);
  curl_close($ch);
  return $res ? json_decode($res, true) : null;
}

function isAdmin($user_id) {
  global $ADMIN_IDS;
  return in_array((string)$user_id, $ADMIN_IDS, true);
}

function nowKeyboardUser() {
  return [
    "keyboard" => [
      [["text"=>"📊 Stats"]],
      [["text"=>"🔗 Referral Link"]],
      [["text"=>"💰 Withdraw"]],
    ],
    "resize_keyboard" => true
  ];
}

function nowKeyboardAdmin() {
  return [
    "keyboard" => [
      [["text"=>"➕ Add Coupon"]],
      [["text"=>"📦 Stock"]],
      [["text"=>"📜 Redeems Log"]],
      [["text"=>"⚙ Change Withdraw Points"]],
      [["text"=>"📢 Add Channels"]],
    ],
    "resize_keyboard" => true
  ];
}

// ---------------- Helpers ----------------
function normalizeChannel($input) {
  $t = trim($input);
  $t = str_replace(["https://t.me/", "http://t.me/", "t.me/"], "", $t);
  $t = ltrim($t, "@");
  // allow only valid username chars
  if (!preg_match('/^[A-Za-z0-9_]{5,64}$/', $t)) return null;
  return $t;
}

function getChannels() {
  global $pdo;
  return $pdo->query("SELECT username FROM channels ORDER BY id ASC")->fetchAll();
}

function checkForceJoin($user_id) {
  global $BOT_TOKEN;
  $channels = getChannels();
  foreach ($channels as $ch) {
    $chat = "@".$ch["username"];
    $r = @file_get_contents("https://api.telegram.org/bot$BOT_TOKEN/getChatMember?chat_id=".urlencode($chat)."&user_id=".$user_id);
    $res = $r ? json_decode($r, true) : null;
    if (!$res || empty($res["ok"])) return false;
    $status = $res["result"]["status"] ?? "left";
    if ($status === "left" || $status === "kicked") return false;
  }
  return true;
}

function sendForceJoinMessage($chat_id) {
  $channels = getChannels();
  if (count($channels) === 0) {
    // no channels configured, treat as joined
    return true;
  }
  $btn = [];
  foreach ($channels as $ch) {
    $btn[][] = ["text"=>"Join @".$ch["username"], "url"=>"https://t.me/".$ch["username"]];
  }
  $btn[][] = ["text"=>"✅ Joined All Channels", "callback_data"=>"joined_all"];
  bot("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"⚠️ You must join all channels first.\n\nThen click ✅ Joined All Channels",
    "reply_markup"=>json_encode(["inline_keyboard"=>$btn])
  ]);
  return false;
}

function sendVerificationMessage($chat_id) {
  global $BASE_URL;
  bot("sendMessage", [
    "chat_id"=>$chat_id,
    "text"=>"🔐 Web Verification Required\n\n1) Tap **Verify Now** (web)\n2) Tap **Check Verification** (bot)",
    "parse_mode"=>"Markdown",
    "reply_markup"=>json_encode([
      "inline_keyboard"=>[
        [["text"=>"✅ Verify Now", "url"=>"$BASE_URL/verify.php?u=$chat_id"]],
        [["text"=>"🔎 Check Verification", "callback_data"=>"check_verify"]],
      ]
    ])
  ]);
}

function ensureUser($user_id, $username, $ref_by = null) {
  global $pdo;
  $stmt = $pdo->prepare("INSERT INTO users(user_id, username, ref_by)
    VALUES(:uid,:un,:rb)
    ON CONFLICT (user_id) DO UPDATE SET username = EXCLUDED.username");
  $stmt->execute([
    ":uid"=>$user_id,
    ":un"=>$username,
    ":rb"=>$ref_by ? (int)$ref_by : null
  ]);
}

function getUser($user_id) {
  global $pdo;
  $st = $pdo->prepare("SELECT * FROM users WHERE user_id=:u");
  $st->execute([":u"=>$user_id]);
  return $st->fetch();
}

function getSetting($key, $default=null) {
  global $pdo;
  $st = $pdo->prepare("SELECT value FROM settings WHERE key=:k");
  $st->execute([":k"=>$key]);
  $v = $st->fetchColumn();
  return $v !== false ? $v : $default;
}

function setSetting($key, $value) {
  global $pdo;
  $st = $pdo->prepare("INSERT INTO settings(key,value) VALUES(:k,:v)
    ON CONFLICT (key) DO UPDATE SET value=EXCLUDED.value");
  $st->execute([":k"=>$key, ":v"=>(string)$value]);
}

function setAdminState($admin_id, $state, $meta="") {
  global $pdo;
  $st = $pdo->prepare("INSERT INTO admin_state(admin_id,state,meta,updated_at)
    VALUES(:a,:s,:m,NOW())
    ON CONFLICT (admin_id) DO UPDATE SET state=EXCLUDED.state, meta=EXCLUDED.meta, updated_at=NOW()");
  $st->execute([":a"=>$admin_id, ":s"=>$state, ":m"=>$meta]);
}

function getAdminState($admin_id) {
  global $pdo;
  $st = $pdo->prepare("SELECT state, meta FROM admin_state WHERE admin_id=:a");
  $st->execute([":a"=>$admin_id]);
  return $st->fetch();
}

function clearAdminState($admin_id) {
  global $pdo;
  $st = $pdo->prepare("DELETE FROM admin_state WHERE admin_id=:a");
  $st->execute([":a"=>$admin_id]);
}

// --------- Referral awarding + leaving deduct (safe) ---------
function awardReferralIfEligible($new_user_id) {
  global $pdo;
  $u = getUser($new_user_id);
  if (!$u) return;

  // Must be verified AND joined channels
  if (!$u["verified"]) return;
  if (!checkForceJoin($new_user_id)) return;

  // Already awarded
  if (!empty($u["points_added"])) return;

  $ref_by = $u["ref_by"];
  if (!$ref_by || (int)$ref_by === (int)$new_user_id) {
    // no ref or self ref
    $pdo->prepare("UPDATE users SET points_added=TRUE WHERE user_id=:u")->execute([":u"=>$new_user_id]);
    return;
  }

  // Award 1 point + 1 referral
  $pdo->prepare("UPDATE users SET points=points+1, referrals=referrals+1 WHERE user_id=:r")
      ->execute([":r"=>$ref_by]);

  // mark as awarded
  $pdo->prepare("UPDATE users SET points_added=TRUE WHERE user_id=:u")->execute([":u"=>$new_user_id]);
}

function deductIfLeftOnce($some_user_id) {
  global $pdo;

  $u = getUser($some_user_id);
  if (!$u) return;

  // Only if referral was already awarded AND has referrer AND not already deducted
  if (empty($u["points_added"])) return;
  if (empty($u["ref_by"])) return;
  if (!empty($u["left_deducted"])) return;

  // If user is NOT joined now -> deduct from referrer once
  if (!checkForceJoin($some_user_id)) {
    $ref_by = (int)$u["ref_by"];
    $pdo->prepare("UPDATE users SET points=GREATEST(points-1,0), referrals=GREATEST(referrals-1,0) WHERE user_id=:r")
        ->execute([":r"=>$ref_by]);
    $pdo->prepare("UPDATE users SET left_deducted=TRUE WHERE user_id=:u")
        ->execute([":u"=>$some_user_id]);
  }
}

// To keep webhook fast, we check only a small batch per update
function leaveCheckBatch() {
  global $pdo;
  $last = (int)getSetting("leave_check_offset", "0");

  $st = $pdo->prepare("SELECT user_id FROM users
    WHERE ref_by IS NOT NULL AND points_added=TRUE AND left_deducted=FALSE
    ORDER BY user_id ASC
    OFFSET :off LIMIT 15");
  $st->bindValue(":off", $last, PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll();

  if (!$rows) {
    setSetting("leave_check_offset", "0");
    return;
  }

  foreach ($rows as $r) {
    deductIfLeftOnce((int)$r["user_id"]);
  }

  setSetting("leave_check_offset", (string)($last + count($rows)));
}

// =====================================================
// ================== UPDATE PARSE ======================
// =====================================================
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) exit;

$message  = $update["message"] ?? null;
$callback = $update["callback_query"] ?? null;

// run small leave-check batch each update (keeps points accurate gradually)
leaveCheckBatch();

// =====================================================
// ================== CALLBACK HANDLER ==================
// =====================================================
if ($callback) {
  $cb_id   = $callback["id"];
  $from_id = $callback["from"]["id"];
  $data    = $callback["data"] ?? "";
  $chat_id = $callback["message"]["chat"]["id"] ?? $from_id;

  // Joined all channels button
  if ($data === "joined_all") {
    if (!checkForceJoin($from_id)) {
      bot("answerCallbackQuery", ["callback_query_id"=>$cb_id, "text"=>"❌ You still have not joined all channels!"]);
      sendForceJoinMessage($chat_id);
      exit;
    }
    bot("answerCallbackQuery", ["callback_query_id"=>$cb_id, "text"=>"✅ Joined! Now do verification."]);
    sendVerificationMessage($chat_id);
    exit;
  }

  // Check verification
  if ($data === "check_verify") {
    $u = getUser($from_id);
    if (!$u || !$u["verified"]) {
      bot("answerCallbackQuery", ["callback_query_id"=>$cb_id, "text"=>"❌ Not verified yet."]);
      exit;
    }

    // After verified, ensure still joined
    if (!checkForceJoin($from_id)) {
      bot("answerCallbackQuery", ["callback_query_id"=>$cb_id, "text"=>"⚠️ Join all channels again."]);
      sendForceJoinMessage($chat_id);
      exit;
    }

    // award referral if eligible
    awardReferralIfEligible($from_id);

    bot("answerCallbackQuery", ["callback_query_id"=>$cb_id, "text"=>"✅ Verified!"]);
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"✅ Verification complete!\n\nUse the buttons below.",
      "reply_markup"=>json_encode(nowKeyboardUser())
    ]);
    exit;
  }

  // Withdraw callbacks: wd_500 / wd_1000 / wd_2000 / wd_4000
  if (strpos($data, "wd_") === 0) {
    $type = str_replace("wd_", "", $data);
    $u = getUser($from_id);
    if (!$u || !$u["verified"]) {
      bot("answerCallbackQuery", ["callback_query_id"=>$cb_id, "text"=>"Verify first."]);
      exit;
    }
    if (!checkForceJoin($from_id)) {
      bot("answerCallbackQuery", ["callback_query_id"=>$cb_id, "text"=>"Join channels first."]);
      sendForceJoinMessage($chat_id);
      exit;
    }

    $need = (int)getSetting("points_$type", "0");
    if ($need <= 0) $need = 999999;

    if ((int)$u["points"] < $need) {
      bot("answerCallbackQuery", ["callback_query_id"=>$cb_id, "text"=>"❌ Not enough points."]);
      exit;
    }

    // Get one unused coupon
    global $pdo;
    $st = $pdo->prepare("SELECT id, code FROM coupons WHERE type=:t AND used=FALSE LIMIT 1");
    $st->execute([":t"=>$type]);
    $coupon = $st->fetch();

    if (!$coupon) {
      bot("answerCallbackQuery", ["callback_query_id"=>$cb_id, "text"=>"❌ Out of stock."]);
      exit;
    }

    // Mark used + deduct points (transaction)
    $pdo->beginTransaction();
    try {
      $pdo->prepare("UPDATE coupons SET used=TRUE WHERE id=:id")->execute([":id"=>$coupon["id"]]);
      $pdo->prepare("UPDATE users SET points=points-:p WHERE user_id=:u")->execute([":p"=>$need, ":u"=>$from_id]);

      $pdo->prepare("INSERT INTO redeem_logs(user_id, username, coupon_type, code, points_used)
        VALUES(:uid,:un,:ct,:cd,:pu)")
        ->execute([
          ":uid"=>$from_id,
          ":un"=>$u["username"],
          ":ct"=>$type,
          ":cd"=>$coupon["code"],
          ":pu"=>$need
        ]);

      $pdo->commit();
    } catch (Exception $e) {
      $pdo->rollBack();
      bot("answerCallbackQuery", ["callback_query_id"=>$cb_id, "text"=>"Error. Try again."]);
      exit;
    }

    bot("answerCallbackQuery", ["callback_query_id"=>$cb_id, "text"=>"✅ Withdraw success!"]);
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"🎁 Your coupon:\n\n`{$coupon["code"]}`",
      "parse_mode"=>"Markdown"
    ]);

    // Notify admins
    foreach ($GLOBALS["ADMIN_IDS"] as $admin) {
      bot("sendMessage", [
        "chat_id" => $admin,
        "text" => "✅ New Withdraw\nUser: @{$u["username"]}\nType: {$type} off\nPoints deducted: {$need}\nCode: {$coupon["code"]}"
      ]);
    }
    exit;
  }

  // -------- Admin panel callbacks --------
  if (!isAdmin($from_id)) {
    bot("answerCallbackQuery", ["callback_query_id"=>$cb_id, "text"=>"Not allowed."]);
    exit;
  }

  // Add coupon type selection: ac_type_500 etc
  if (strpos($data, "ac_type_") === 0) {
    $type = str_replace("ac_type_", "", $data);
    setAdminState($from_id, "awaiting_coupon_codes", $type);
    bot("answerCallbackQuery", ["callback_query_id"=>$cb_id, "text"=>"Send codes now"]);
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"➕ Add Coupon ($type off)\nSend codes **line by line** now.",
      "parse_mode"=>"Markdown"
    ]);
    exit;
  }

  // Change points type selection: cp_type_500 etc
  if (strpos($data, "cp_type_") === 0) {
    $type = str_replace("cp_type_", "", $data);
    setAdminState($from_id, "awaiting_points_value", $type);
    bot("answerCallbackQuery", ["callback_query_id"=>$cb_id, "text"=>"Send points number"]);
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"⚙ Change Withdraw Points\nType: $type off\n\nSend required points (number)."
    ]);
    exit;
  }

  bot("answerCallbackQuery", ["callback_query_id"=>$cb_id, "text"=>"OK"]);
  exit;
}

// =====================================================
// ================== MESSAGE HANDLER ===================
// =====================================================
if ($message) {
  $chat_id = $message["chat"]["id"];
  $from_id = $message["from"]["id"];
  $text    = trim($message["text"] ?? "");
  $username = $message["from"]["username"] ?? "NoUsername";

  // ---------- /start ----------
  if (strpos($text, "/start") === 0) {
    $parts = explode(" ", $text);
    $ref = $parts[1] ?? null;

    ensureUser($from_id, $username, $ref);

    // Force join first
    if (!checkForceJoin($from_id)) {
      sendForceJoinMessage($chat_id);
      exit;
    }

    // If already verified, show menu
    $u = getUser($from_id);
    if ($u && $u["verified"]) {
      awardReferralIfEligible($from_id);       // ensure points awarded
      deductIfLeftOnce($from_id);              // ensure not left
      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"✅ Welcome back!",
        "reply_markup"=>json_encode(nowKeyboardUser())
      ]);
      exit;
    }

    // ask verification
    sendVerificationMessage($chat_id);
    exit;
  }

  // ---------- Admin /admin ----------
  if ($text === "/admin" && isAdmin($from_id)) {
    clearAdminState($from_id);
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"👑 Admin Panel",
      "reply_markup"=>json_encode(nowKeyboardAdmin())
    ]);
    exit;
  }

  // ---------- Admin state processing ----------
  if (isAdmin($from_id)) {
    $st = getAdminState($from_id);

    // Admin is sending coupon codes
    if ($st && $st["state"] === "awaiting_coupon_codes") {
      $type = $st["meta"];
      $lines = preg_split("/\r\n|\n|\r/", $text);
      $lines = array_values(array_filter(array_map('trim', $lines)));

      if (count($lines) === 0) {
        bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"Send at least 1 code."]);
        exit;
      }

      $added = 0;
      $dup = 0;

      $pdo->beginTransaction();
      try {
        $ins = $pdo->prepare("INSERT INTO coupons(type,code,used) VALUES(:t,:c,FALSE)");
        foreach ($lines as $code) {
          try {
            $ins->execute([":t"=>$type, ":c"=>$code]);
            $added++;
          } catch (Exception $e) {
            $dup++;
          }
        }
        $pdo->commit();
      } catch (Exception $e) {
        $pdo->rollBack();
        bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"DB error adding codes."]);
        exit;
      }

      clearAdminState($from_id);

      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"✅ Added: $added\n♻️ Duplicates skipped: $dup\nType: $type",
        "reply_markup"=>json_encode(nowKeyboardAdmin())
      ]);
      exit;
    }

    // Admin is sending new points value
    if ($st && $st["state"] === "awaiting_points_value") {
      $type = $st["meta"];
      if (!preg_match('/^\d+$/', $text)) {
        bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"Send only a number (e.g. 10)."]);
        exit;
      }
      setSetting("points_$type", $text);
      clearAdminState($from_id);

      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"✅ Updated withdraw points for $type off = $text points",
        "reply_markup"=>json_encode(nowKeyboardAdmin())
      ]);
      exit;
    }

    // Admin is sending a channel username
    if ($st && $st["state"] === "awaiting_channel") {
      $ch = normalizeChannel($text);
      if (!$ch) {
        bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"Send a valid channel username like @channel or t.me/channel"]);
        exit;
      }
      $pdo->prepare("INSERT INTO channels(username) VALUES(:u) ON CONFLICT (username) DO NOTHING")
          ->execute([":u"=>$ch]);
      clearAdminState($from_id);

      $all = getChannels();
      $list = "";
      foreach ($all as $row) $list .= "@".$row["username"]."\n";

      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"✅ Channel added: @$ch\n\nCurrent force-join list:\n$list",
        "reply_markup"=>json_encode(nowKeyboardAdmin())
      ]);
      exit;
    }
  }

  // ---------- Admin panel buttons ----------
  if (isAdmin($from_id)) {

    if ($text === "➕ Add Coupon") {
      clearAdminState($from_id);
      $btn = [
        [["text"=>"500 off on 500",  "callback_data"=>"ac_type_500"]],
        [["text"=>"1000 off on 1000","callback_data"=>"ac_type_1000"]],
        [["text"=>"2000 off on 2000","callback_data"=>"ac_type_2000"]],
        [["text"=>"4000 off on 4000","callback_data"=>"ac_type_4000"]],
      ];
      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"Select coupon type to add:",
        "reply_markup"=>json_encode(["inline_keyboard"=>$btn])
      ]);
      exit;
    }

    if ($text === "📦 Stock") {
      $types = ["500","1000","2000","4000"];
      $msg = "📦 Stock:\n\n";
      foreach ($types as $t) {
        $stock = $pdo->query("SELECT COUNT(*) FROM coupons WHERE type='$t' AND used=FALSE")->fetchColumn();
        $msg .= "$t off: $stock\n";
      }
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>$msg]);
      exit;
    }

    if ($text === "📜 Redeems Log") {
      $rows = $pdo->query("SELECT username,coupon_type,points_used,created_at FROM redeem_logs ORDER BY id DESC LIMIT 10")->fetchAll();
      if (!$rows) {
        bot("sendMessage", ["chat_id"=>$chat_id, "text"=>"No redeems yet."]);
        exit;
      }
      $msg = "📜 Last 10 Redeems:\n\n";
      foreach ($rows as $r) {
        $u = $r["username"] ?: "NoUsername";
        $msg .= "@$u | {$r["coupon_type"]} | -{$r["points_used"]} pts | {$r["created_at"]}\n";
      }
      bot("sendMessage", ["chat_id"=>$chat_id, "text"=>$msg]);
      exit;
    }

    if ($text === "⚙ Change Withdraw Points") {
      clearAdminState($from_id);
      $btn = [
        [["text"=>"500 off on 500",  "callback_data"=>"cp_type_500"]],
        [["text"=>"1000 off on 1000","callback_data"=>"cp_type_1000"]],
        [["text"=>"2000 off on 2000","callback_data"=>"cp_type_2000"]],
        [["text"=>"4000 off on 4000","callback_data"=>"cp_type_4000"]],
      ];
      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"Select coupon type to change points:",
        "reply_markup"=>json_encode(["inline_keyboard"=>$btn])
      ]);
      exit;
    }

    if ($text === "📢 Add Channels") {
      setAdminState($from_id, "awaiting_channel", "");
      bot("sendMessage", [
        "chat_id"=>$chat_id,
        "text"=>"Send channel username now (public/private both supported if bot can check membership).\nExample:\n@mychannel OR https://t.me/mychannel"
      ]);
      exit;
    }
  }

  // ---------- User panel ----------
  $u = getUser($from_id);
  if (!$u) {
    ensureUser($from_id, $username, null);
    $u = getUser($from_id);
  }

  // Require verification for user menu actions
  if (in_array($text, ["📊 Stats","🔗 Referral Link","💰 Withdraw"], true)) {
    if (!$u["verified"]) {
      if (!checkForceJoin($from_id)) {
        sendForceJoinMessage($chat_id);
        exit;
      }
      sendVerificationMessage($chat_id);
      exit;
    }
    // Also require joined now
    if (!checkForceJoin($from_id)) {
      sendForceJoinMessage($chat_id);
      exit;
    }
    // keep referral and deduction accurate
    awardReferralIfEligible($from_id);
    deductIfLeftOnce($from_id);
  }

  if ($text === "📊 Stats") {
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"👥 Referrals: {$u["referrals"]}\n⭐ Points: {$u["points"]}"
    ]);
    exit;
  }

  if ($text === "🔗 Referral Link") {
    $link = "https://t.me/$GLOBALS[BOT_USERNAME]?start=".$from_id;
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"🔗 Your referral link:\n$link"
    ]);
    exit;
  }

  if ($text === "💰 Withdraw") {
    $types = ["500","1000","2000","4000"];
    $btn = [];
    foreach ($types as $t) {
      $stock = $pdo->query("SELECT COUNT(*) FROM coupons WHERE type='$t' AND used=FALSE")->fetchColumn();
      $need = (int)getSetting("points_$t", "0");
      $btn[][] = ["text"=>"$t off ($stock) • $need pts", "callback_data"=>"wd_$t"];
    }
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"Select withdrawal option:",
      "reply_markup"=>json_encode(["inline_keyboard"=>$btn])
    ]);
    exit;
  }

  // Default fallback
  if (!$u["verified"]) {
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"Type /start to begin."
    ]);
  } else {
    bot("sendMessage", [
      "chat_id"=>$chat_id,
      "text"=>"Use menu buttons 👇",
      "reply_markup"=>json_encode(nowKeyboardUser())
    ]);
  }
  exit;
}

echo "OK";

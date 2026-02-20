import hashlib
import re
from typing import Optional, Dict, Any

import asyncpg
import httpx
from fastapi import FastAPI, Request
from fastapi.responses import HTMLResponse, RedirectResponse

# ================= CONFIG (CHANGE THESE) =================
BOT_TOKEN = "8566776302:AAFzmjD96qirFe5P0OTI7orA29H8lbfEaGU"
DATABASE_URL = "postgresql://postgres.rhswwhjuaxkbjrsquevl:RadheyRadhe@aws-1-ap-south-1.pooler.supabase.com:5432/postgres"
BOT_USERNAME = "Sheinn_Refer_Bot"  # without @
BASE_URL = "https://zenithwave-refer-bot.onrender.com"  # your Render URL

BOT_DISPLAY_NAME = "ZenithWave Refer Bot"

ADMIN_IDS = [8537079657, 6246358508]  # your admin user IDs

CHANNELS = [
    "@ZenithWave_Shein",
    "@lootxupdates",
    "@dragoncodexshop"
]

COUPON_TYPES = {
    "500": "500 OFF ON 500",
    "1000": "1000 OFF ON 1000",
    "2000": "2000 OFF ON 2000",
    "4000": "4000 OFF ON 4000"
}

# ================= APP / GLOBALS =================
app = FastAPI()
pool: Optional[asyncpg.Pool] = None
http_client: Optional[httpx.AsyncClient] = None


# ================= DB SCHEMA (AUTO SETUP) =================
SCHEMA_SQL = """
CREATE TABLE IF NOT EXISTS public.bot_users (
  user_id BIGINT PRIMARY KEY,
  username TEXT,
  points INTEGER DEFAULT 0,
  total_referrals INTEGER DEFAULT 0,
  referred_by BIGINT,
  verified BOOLEAN DEFAULT FALSE,
  left_penalized BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMP DEFAULT NOW(),
  updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.coupons (
  id SERIAL PRIMARY KEY,
  type TEXT NOT NULL,
  code TEXT NOT NULL UNIQUE,
  is_used BOOLEAN DEFAULT FALSE,
  used_by BIGINT,
  used_at TIMESTAMP
);

CREATE TABLE IF NOT EXISTS public.redeems (
  id SERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL,
  coupon_type TEXT NOT NULL,
  code TEXT NOT NULL,
  points_used INTEGER NOT NULL,
  created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.settings (
  coupon_type TEXT PRIMARY KEY,
  required_points INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS public.bot_states (
  user_id BIGINT PRIMARY KEY,
  mode TEXT,
  coupon_type TEXT,
  updated_at TIMESTAMP DEFAULT NOW()
);

-- Defaults (edit if you want)
INSERT INTO public.settings (coupon_type, required_points) VALUES
('500', 1),
('1000', 2),
('2000', 3),
('4000', 4)
ON CONFLICT (coupon_type)
DO UPDATE SET required_points = EXCLUDED.required_points;
"""


# ================= STARTUP / SHUTDOWN =================
@app.on_event("startup")
async def startup():
    global pool, http_client
    pool = await asyncpg.create_pool(DATABASE_URL, min_size=1, max_size=10)
    http_client = httpx.AsyncClient(timeout=httpx.Timeout(8.0))

    # create schema
    async with pool.acquire() as conn:
        await conn.execute(SCHEMA_SQL)


@app.on_event("shutdown")
async def shutdown():
    global pool, http_client
    if http_client:
        await http_client.aclose()
    if pool:
        await pool.close()


# ================= SMALL HELPERS =================
def make_token(uid: int) -> str:
    return hashlib.sha256(str(uid).encode()).hexdigest()


def now_ts_sql() -> str:
    return "NOW()"


def is_admin(user_id: int) -> bool:
    return user_id in ADMIN_IDS


async def tg(method: str, data: Dict[str, Any]) -> None:
    assert http_client is not None
    url = f"https://api.telegram.org/bot{BOT_TOKEN}/{method}"
    await http_client.post(url, json=data)


async def tg_send(chat_id: int, text: str, reply_markup: Optional[dict] = None, disable_web_preview: bool = True):
    payload = {
        "chat_id": chat_id,
        "text": text,
        "disable_web_page_preview": disable_web_preview
    }
    if reply_markup:
        payload["reply_markup"] = reply_markup
    await tg("sendMessage", payload)


async def tg_answer_cb(cb_id: str):
    await tg("answerCallbackQuery", {"callback_query_id": cb_id})


async def check_member(user_id: int, channel: str) -> bool:
    """Checks if user is member/admin/creator of a channel."""
    assert http_client is not None
    url = f"https://api.telegram.org/bot{BOT_TOKEN}/getChatMember"
    r = await http_client.post(url, json={"chat_id": channel, "user_id": user_id})
    js = r.json()
    if js.get("ok"):
        status = js["result"]["status"]
        return status in ("member", "administrator", "creator")
    return False


async def force_join_ok(user_id: int) -> bool:
    for ch in CHANNELS:
        if not await check_member(user_id, ch):
            return False
    return True


def force_join_keyboard() -> dict:
    rows = []
    for ch in CHANNELS:
        rows.append([{"text": f"Join {ch}", "url": f"https://t.me/{ch[1:]}"}])
    rows.append([{"text": "✅ Joined All Channels", "callback_data": "recheck_join"}])
    return {"inline_keyboard": rows}


def user_menu_keyboard() -> dict:
    return {
        "keyboard": [
            ["📊 Stats"],
            ["🔗 Referral Link"],
            ["💰 Withdraw"]
        ],
        "resize_keyboard": True
    }


def admin_menu_keyboard() -> dict:
    return {
        "keyboard": [
            ["➕ Add Coupon"],
            ["📦 Stock"],
            ["📜 Redeems Log"],
            ["⚙ Change Withdraw Points"]
        ],
        "resize_keyboard": True
    }


async def set_state(user_id: int, mode: Optional[str], coupon_type: Optional[str] = None):
    """Stores admin/user flow state in DB (survives restarts)."""
    assert pool is not None
    async with pool.acquire() as conn:
        if mode is None:
            await conn.execute("DELETE FROM public.bot_states WHERE user_id=$1", user_id)
        else:
            await conn.execute("""
                INSERT INTO public.bot_states(user_id, mode, coupon_type, updated_at)
                VALUES($1,$2,$3,NOW())
                ON CONFLICT (user_id) DO UPDATE
                SET mode=EXCLUDED.mode, coupon_type=EXCLUDED.coupon_type, updated_at=NOW()
            """, user_id, mode, coupon_type)


async def get_state(user_id: int) -> Optional[asyncpg.Record]:
    assert pool is not None
    async with pool.acquire() as conn:
        return await conn.fetchrow("SELECT mode, coupon_type FROM public.bot_states WHERE user_id=$1", user_id)


async def ensure_bot_user(user_id: int, username: str):
    assert pool is not None
    async with pool.acquire() as conn:
        await conn.execute("""
            INSERT INTO public.bot_users(user_id, username)
            VALUES($1,$2)
            ON CONFLICT (user_id) DO UPDATE
            SET username=EXCLUDED.username, updated_at=NOW()
        """, user_id, username)


async def enforce_membership_and_penalty(user_id: int):
    """
    Clean policy:
    - If user is verified but leaves any channel -> set verified=false.
    - If they had a referrer and penalty not applied yet -> deduct 1 point from referrer once.
    """
    assert pool is not None

    # If user not verified, no need to penalize
    async with pool.acquire() as conn:
        row = await conn.fetchrow("""
            SELECT verified, referred_by, left_penalized
            FROM public.bot_users
            WHERE user_id=$1
        """, user_id)

    if not row:
        return

    if not row["verified"]:
        return

    joined = await force_join_ok(user_id)
    if joined:
        return

    # User left: revoke verification + penalize referrer once
    async with pool.acquire() as conn:
        async with conn.transaction():
            # re-fetch with lock
            locked = await conn.fetchrow("""
                SELECT verified, referred_by, left_penalized
                FROM public.bot_users
                WHERE user_id=$1
                FOR UPDATE
            """, user_id)

            if not locked or not locked["verified"]:
                return

            await conn.execute("""
                UPDATE public.bot_users
                SET verified=FALSE, updated_at=NOW()
                WHERE user_id=$1
            """, user_id)

            ref = locked["referred_by"]
            penalized = locked["left_penalized"]

            if ref and not penalized:
                # deduct 1 point, do not go negative
                await conn.execute("""
                    UPDATE public.bot_users
                    SET points = GREATEST(points - 1, 0),
                        updated_at=NOW()
                    WHERE user_id=$1
                """, ref)

                await conn.execute("""
                    UPDATE public.bot_users
                    SET left_penalized=TRUE, updated_at=NOW()
                    WHERE user_id=$1
                """, user_id)


# ================= UI ROUTES =================
@app.get("/")
async def home():
    return {"ok": True, "message": "Bot is running"}


VERIFY_PAGE_HTML = r"""
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>{name} • Verification</title>
  <style>
    :root{
      --bg1:#070a12; --bg2:#0b1224;
      --stroke: rgba(255,255,255,.12);
      --text:#eaf0ff; --muted: rgba(234,240,255,.72);
      --btn1:#5b8cff; --btn2:#2b58ff;
      --ok:#2ee59d;
    }
    *{box-sizing:border-box;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif}
    body{
      margin:0; min-height:100vh; display:grid; place-items:center;
      padding:24px; color:var(--text);
      background:
        radial-gradient(1200px 600px at 15% 10%, rgba(91,140,255,.35), transparent 55%),
        radial-gradient(900px 500px at 92% 35%, rgba(155,91,255,.26), transparent 60%),
        linear-gradient(160deg,var(--bg1),var(--bg2));
    }
    .card{
      width:min(560px,100%);
      border:1px solid var(--stroke);
      background: linear-gradient(180deg, rgba(255,255,255,.10), rgba(255,255,255,.04));
      backdrop-filter: blur(10px);
      border-radius:20px;
      padding:22px;
      box-shadow: 0 20px 70px rgba(0,0,0,.55);
      position:relative;
      overflow:hidden;
    }
    .header{display:flex;gap:14px;align-items:center}
    .logo{
      width:52px;height:52px;border-radius:16px;
      background: linear-gradient(135deg,#5b8cff,#9b5bff);
      display:grid;place-items:center;
      font-weight:900;font-size:18px;
      box-shadow: 0 12px 30px rgba(91,140,255,.24);
      flex: 0 0 auto;
    }
    .title{margin:0;font-size:18px;line-height:1.25}
    .subtitle{margin:6px 0 0;color:var(--muted);line-height:1.5}
    .divider{height:1px;background:rgba(255,255,255,.10);margin:16px 0}
    .btn{
      display:inline-flex;align-items:center;justify-content:center;
      width:100%;padding:14px 16px;border:none;border-radius:14px;
      background: linear-gradient(135deg,var(--btn1),var(--btn2));
      color:white;font-weight:800;font-size:16px;cursor:pointer;
      box-shadow: 0 16px 34px rgba(43,88,255,.28);
      transition: transform .08s ease, filter .2s ease;
    }
    .btn:active{transform:scale(.99)}
    .btn[disabled]{opacity:.7;cursor:not-allowed;filter:saturate(.6)}
    .small{margin-top:12px;color:rgba(234,240,255,.68);font-size:13px;text-align:center}
    .chip{
      display:inline-flex;align-items:center;gap:8px;
      margin-top:12px;padding:8px 10px;border-radius:999px;
      border:1px solid rgba(46,229,157,.35);
      background: rgba(46,229,157,.10);
      color: var(--ok); font-size:13px;
    }
    .overlay{
      position:absolute; inset:0;
      display:none; place-items:center;
      background: rgba(7,10,18,.72);
      backdrop-filter: blur(8px);
    }
    .overlay.on{display:grid;}
    .panel{
      width:min(420px, 92%);
      border:1px solid rgba(255,255,255,.16);
      border-radius:18px;
      padding:18px;
      background: rgba(255,255,255,.08);
      box-shadow: 0 20px 70px rgba(0,0,0,.55);
      text-align:center;
    }
    .spinner{
      width:56px;height:56px;border-radius:50%;
      border:4px solid rgba(255,255,255,.18);
      border-top-color: rgba(91,140,255,.95);
      animation: spin 1s linear infinite;
      margin: 2px auto 14px;
    }
    @keyframes spin{to{transform:rotate(360deg)}}
  </style>
</head>
<body>
  <div class="card">
    <div class="header">
      <div class="logo">Z</div>
      <div>
        <h1 class="title">{name}</h1>
        <p class="subtitle">Tap below to verify and unlock the bot menu.</p>
      </div>
    </div>

    <div class="divider"></div>

    <form id="verifyForm" method="POST" action="/verify">
      <input type="hidden" name="uid" value="{uid}">
      <input type="hidden" name="token" value="{token}">
      <button id="verifyBtn" class="btn" type="submit">✅ Verify Now</button>
    </form>

    <div class="small">
      After verification, you will be redirected to Telegram automatically.
      <div class="chip">🔒 Secure verification link</div>
    </div>

    <div id="overlay" class="overlay">
      <div class="panel">
        <div class="spinner"></div>
        <h2 style="margin:0 0 6px;font-size:18px">Verifying…</h2>
        <p style="margin:0;color:rgba(234,240,255,.72);line-height:1.5">Please wait a moment.</p>
      </div>
    </div>
  </div>

  <script>
    const form = document.getElementById("verifyForm");
    const overlay = document.getElementById("overlay");
    const btn = document.getElementById("verifyBtn");
    form.addEventListener("submit", () => {
      overlay.classList.add("on");
      btn.setAttribute("disabled", "disabled");
    });
  </script>
</body>
</html>
"""

SUCCESS_PAGE_HTML = r"""
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>{name} • Verified</title>
  <style>
    :root{--bg1:#070a12;--bg2:#0b1224;--text:#eaf0ff;--muted:rgba(234,240,255,.72);--ok:#2ee59d}
    *{box-sizing:border-box;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif}
    body{
      margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;color:var(--text);
      background:
        radial-gradient(1200px 600px at 15% 10%, rgba(91,140,255,.35), transparent 55%),
        radial-gradient(900px 500px at 92% 35%, rgba(155,91,255,.26), transparent 60%),
        linear-gradient(160deg,var(--bg1),var(--bg2));
    }
    .box{
      width:min(520px,100%);
      border-radius:20px;
      padding:22px;
      background: rgba(255,255,255,.07);
      border:1px solid rgba(255,255,255,.14);
      box-shadow: 0 20px 70px rgba(0,0,0,.55);
      text-align:center;
    }
    .ok{
      width:70px;height:70px;border-radius:22px;
      background: rgba(46,229,157,.12);
      border:1px solid rgba(46,229,157,.35);
      display:grid;place-items:center;
      margin: 0 auto 12px;
      font-size:34px;color:var(--ok);
    }
    h1{margin:0 0 6px;font-size:20px}
    p{margin:0;color:var(--muted);line-height:1.5}
    .small{margin-top:10px;font-size:13px;color:rgba(234,240,255,.62)}
  </style>
</head>
<body>
  <div class="box">
    <div class="ok">✅</div>
    <h1>Verified ✅</h1>
    <p>Redirecting you back to Telegram…</p>
    <div class="small">{name}</div>
  </div>

  <script>
    setTimeout(() => {
      window.location.href = "https://t.me/{bot}";
    }, 1200);
  </script>
</body>
</html>
"""


@app.get("/verify", response_class=HTMLResponse)
async def verify_page(uid: int, token: str):
    expected = make_token(uid)
    if token != expected:
        return HTMLResponse("<h3>Invalid verification link</h3>", status_code=403)

    return HTMLResponse(VERIFY_PAGE_HTML.format(uid=uid, token=token, name=BOT_DISPLAY_NAME))


@app.post("/verify", response_class=HTMLResponse)
async def verify_submit(request: Request):
    form = await request.form()
    uid = int(form.get("uid", "0"))
    token = str(form.get("token", ""))

    expected = make_token(uid)
    if token != expected:
        return HTMLResponse("<h3>Invalid verification link</h3>", status_code=403)

    # Must still be joined (clean policy)
    if not await force_join_ok(uid):
        return HTMLResponse("<h3>Please join all channels first, then verify again.</h3>", status_code=403)

    assert pool is not None
    async with pool.acquire() as conn:
        async with conn.transaction():
            row = await conn.fetchrow("""
                SELECT verified, referred_by
                FROM public.bot_users
                WHERE user_id=$1
                FOR UPDATE
            """, uid)

            if not row:
                # If user not created yet, create minimal record
                await conn.execute("""
                    INSERT INTO public.bot_users(user_id, verified, created_at, updated_at)
                    VALUES($1, TRUE, NOW(), NOW())
                    ON CONFLICT (user_id) DO UPDATE SET verified=TRUE, updated_at=NOW()
                """, uid)
                row = {"verified": False, "referred_by": None}

            if not row["verified"]:
                await conn.execute("""
                    UPDATE public.bot_users
                    SET verified=TRUE, updated_at=NOW()
                    WHERE user_id=$1
                """, uid)

                ref = row["referred_by"]
                if ref:
                    await conn.execute("""
                        UPDATE public.bot_users
                        SET points = points + 1,
                            total_referrals = total_referrals + 1,
                            updated_at=NOW()
                        WHERE user_id = $1
                    """, ref)

    return HTMLResponse(SUCCESS_PAGE_HTML.format(name=BOT_DISPLAY_NAME, bot=BOT_USERNAME))


# ================= MAIN BOT LOGIC =================
@app.post("/webhook")
async def webhook(req: Request):
    data = await req.json()

    # Callback queries
    if "callback_query" in data:
        return await handle_callback(data["callback_query"])

    if "message" not in data:
        return {"ok": True}

    msg = data["message"]
    user = msg.get("from", {})
    user_id = int(user.get("id"))
    username = user.get("username", "") or ""
    text = msg.get("text", "") or ""

    await ensure_bot_user(user_id, username)

    # If verified user left channels -> revoke + penalize once
    await enforce_membership_and_penalty(user_id)

    # Handle admin state inputs
    state = await get_state(user_id)
    if state and state["mode"]:
        handled = await handle_state_message(user_id, text, state)
        if handled:
            return {"ok": True}

    # START
    if text.startswith("/start"):
        # referral?
        ref_id = None
        m = re.search(r"ref_(\d+)", text)
        if m:
            try:
                ref_id = int(m.group(1))
            except:
                ref_id = None

        if not await force_join_ok(user_id):
            await tg_send(
                user_id,
                "⚠️ Please join all 3 channels first.",
                reply_markup=force_join_keyboard()
            )
            return {"ok": True}

        # Save ref if valid
        if ref_id and ref_id != user_id:
            assert pool is not None
            async with pool.acquire() as conn:
                await conn.execute("""
                    UPDATE public.bot_users
                    SET referred_by=$1, updated_at=NOW()
                    WHERE user_id=$2 AND (referred_by IS NULL OR referred_by=0)
                """, ref_id, user_id)

        token = make_token(user_id)
        verify_link = f"{BASE_URL}/verify?uid={user_id}&token={token}"

        await tg_send(
            user_id,
            "🌐 Web verification required.\n\n1) Click Verify Now\n2) After verified, come back and press “Check Verification”.",
            reply_markup={
                "inline_keyboard": [
                    [{"text": "Verify Now", "url": verify_link}],
                    [{"text": "Check Verification", "callback_data": "check_verify"}]
                ]
            }
        )
        return {"ok": True}

    # USER MENU
    if text == "📊 Stats":
        assert pool is not None
        async with pool.acquire() as conn:
            row = await conn.fetchrow("""
                SELECT points, total_referrals, verified
                FROM public.bot_users WHERE user_id=$1
            """, user_id)

        if not row or not row["verified"]:
            await tg_send(user_id, "❌ Please complete verification first. Send /start")
            return {"ok": True}

        await tg_send(
            user_id,
            f"👥 Referrals: {row['total_referrals']}\n⭐ Points: {row['points']}"
        )
        return {"ok": True}

    if text == "🔗 Referral Link":
        assert pool is not None
        async with pool.acquire() as conn:
            verified = await conn.fetchval("SELECT verified FROM public.bot_users WHERE user_id=$1", user_id)

        if not verified:
            await tg_send(user_id, "❌ Please complete verification first. Send /start")
            return {"ok": True}

        link = f"https://t.me/{BOT_USERNAME}?start=ref_{user_id}"
        await tg_send(user_id, f"🔗 Your referral link:\n{link}")
        return {"ok": True}

    if text == "💰 Withdraw":
        assert pool is not None
        async with pool.acquire() as conn:
            verified = await conn.fetchval("SELECT verified FROM public.bot_users WHERE user_id=$1", user_id)

        if not verified:
            await tg_send(user_id, "❌ Please complete verification first. Send /start")
            return {"ok": True}

        if not await force_join_ok(user_id):
            await tg_send(user_id, "⚠️ Join all channels first.", reply_markup=force_join_keyboard())
            return {"ok": True}

        keyboard = []
        assert pool is not None
        async with pool.acquire() as conn:
            for key, label in COUPON_TYPES.items():
                stock = await conn.fetchval("""
                    SELECT COUNT(*) FROM public.coupons
                    WHERE type=$1 AND is_used=FALSE
                """, key)
                keyboard.append([{
                    "text": f"{label} (Stock: {stock})",
                    "callback_data": f"withdraw_{key}"
                }])

        await tg_send(
            user_id,
            "Select withdraw option:",
            reply_markup={"inline_keyboard": keyboard}
        )
        return {"ok": True}

    # ADMIN COMMAND
    if text == "/admin":
        if not is_admin(user_id):
            await tg_send(user_id, "❌ You are not admin.")
            return {"ok": True}

        await tg_send(user_id, "✅ Admin Panel", reply_markup=admin_menu_keyboard())
        return {"ok": True}

    # ADMIN MENU OPTIONS
    if is_admin(user_id) and text == "➕ Add Coupon":
        keyboard = []
        for key, label in COUPON_TYPES.items():
            keyboard.append([{"text": label, "callback_data": f"admin_add_{key}"}])
        await tg_send(user_id, "Select coupon type to add:", reply_markup={"inline_keyboard": keyboard})
        return {"ok": True}

    if is_admin(user_id) and text == "📦 Stock":
        msg_lines = ["📦 Coupon Stock:\n"]
        assert pool is not None
        async with pool.acquire() as conn:
            for key, label in COUPON_TYPES.items():
                stock = await conn.fetchval("""
                    SELECT COUNT(*) FROM public.coupons
                    WHERE type=$1 AND is_used=FALSE
                """, key)
                msg_lines.append(f"{label}: {stock}")
        await tg_send(user_id, "\n".join(msg_lines))
        return {"ok": True}

    if is_admin(user_id) and text == "📜 Redeems Log":
        assert pool is not None
        async with pool.acquire() as conn:
            rows = await conn.fetch("""
                SELECT user_id, coupon_type, code, points_used, created_at
                FROM public.redeems
                ORDER BY id DESC
                LIMIT 10
            """)
        if not rows:
            await tg_send(user_id, "No redeems yet.")
            return {"ok": True}

        out = ["📜 Last 10 Redeems:\n"]
        for r in rows:
            out.append(f"User: {r['user_id']} | {COUPON_TYPES.get(r['coupon_type'], r['coupon_type'])} | {r['code']} | -{r['points_used']} pts")
        await tg_send(user_id, "\n".join(out))
        return {"ok": True

        }

    if is_admin(user_id) and text == "⚙ Change Withdraw Points":
        keyboard = []
        for key, label in COUPON_TYPES.items():
            keyboard.append([{"text": label, "callback_data": f"admin_points_{key}"}])
        await tg_send(user_id, "Select coupon type to change points:", reply_markup={"inline_keyboard": keyboard})
        return {"ok": True}

    return {"ok": True}


# ================= CALLBACK HANDLER =================
async def handle_callback(cb: dict):
    cb_id = cb["id"]
    user_id = int(cb["from"]["id"])
    data = cb.get("data", "")

    await tg_answer_cb(cb_id)

    await ensure_bot_user(user_id, cb["from"].get("username", "") or "")
    await enforce_membership_and_penalty(user_id)

    # Force join recheck
    if data == "recheck_join":
        if await force_join_ok(user_id):
            await tg_send(user_id, "✅ Channels verified. Now click /start again to verify.")
        else:
            await tg_send(user_id, "❌ You still haven't joined all channels.", reply_markup=force_join_keyboard())
        return {"ok": True}

    # Check verification
    if data == "check_verify":
        assert pool is not None
        async with pool.acquire() as conn:
            verified = await conn.fetchval("SELECT verified FROM public.bot_users WHERE user_id=$1", user_id)

        if verified:
            await tg_send(user_id, "✅ Verified successfully!", reply_markup=user_menu_keyboard())
        else:
            await tg_send(user_id, "❌ Verification not completed. Send /start and verify.")
        return {"ok": True}

    # Withdraw callback
    if data.startswith("withdraw_"):
        coupon_type = data.split("_", 1)[1]

        if coupon_type not in COUPON_TYPES:
            await tg_send(user_id, "Invalid option.")
            return {"ok": True}

        # must be joined
        if not await force_join_ok(user_id):
            await tg_send(user_id, "⚠️ Join all channels first.", reply_markup=force_join_keyboard())
            return {"ok": True}

        assert pool is not None
        async with pool.acquire() as conn:
            verified = await conn.fetchval("SELECT verified FROM public.bot_users WHERE user_id=$1", user_id)
        if not verified:
            await tg_send(user_id, "❌ Please verify first. Send /start")
            return {"ok": True}

        # Transaction: lock coupon + ensure points
        assert pool is not None
        async with pool.acquire() as conn:
            async with conn.transaction():
                required = await conn.fetchval("""
                    SELECT required_points FROM public.settings WHERE coupon_type=$1
                """, coupon_type)

                if required is None:
                    await tg_send(user_id, "Settings missing for this coupon type. Admin must set points.")
                    return {"ok": True}

                # lock user row
                u = await conn.fetchrow("""
                    SELECT points FROM public.bot_users WHERE user_id=$1 FOR UPDATE
                """, user_id)
                if not u:
                    await tg_send(user_id, "User not found. Send /start")
                    return {"ok": True}

                if u["points"] < required:
                    await tg_send(user_id, "❌ You don't have enough referral points.")
                    return {"ok": True}

                coupon = await conn.fetchrow("""
                    SELECT id, code
                    FROM public.coupons
                    WHERE type=$1 AND is_used=FALSE
                    ORDER BY id ASC
                    FOR UPDATE SKIP LOCKED
                    LIMIT 1
                """, coupon_type)

                if not coupon:
                    await tg_send(user_id, "❌ Out of stock.")
                    return {"ok": True}

                # mark coupon used
                await conn.execute("""
                    UPDATE public.coupons
                    SET is_used=TRUE, used_by=$1, used_at=NOW()
                    WHERE id=$2
                """, user_id, coupon["id"])

                # deduct points
                await conn.execute("""
                    UPDATE public.bot_users
                    SET points = points - $1, updated_at=NOW()
                    WHERE user_id=$2
                """, required, user_id)

                # log redeem
                await conn.execute("""
                    INSERT INTO public.redeems(user_id, coupon_type, code, points_used)
                    VALUES($1,$2,$3,$4)
                """, user_id, coupon_type, coupon["code"], required)

        # send coupon
        await tg_send(user_id, f"🎉 Redeemed!\n\n{COUPON_TYPES[coupon_type]}\n\n✅ Code:\n{coupon['code']}")

        # notify admins
        for admin_id in ADMIN_IDS:
            await tg_send(
                admin_id,
                f"🧾 Redeem Alert\nUser: {user_id}\nType: {COUPON_TYPES[coupon_type]}\nPoints Deducted: {required}\nCode: {coupon['code']}"
            )

        return {"ok": True}

    # Admin add coupons
    if data.startswith("admin_add_"):
        if not is_admin(user_id):
            await tg_send(user_id, "❌ Not admin.")
            return {"ok": True}

        coupon_type = data.split("_", 2)[2]
        if coupon_type not in COUPON_TYPES:
            await tg_send(user_id, "Invalid coupon type.")
            return {"ok": True}

        await set_state(user_id, mode="await_codes", coupon_type=coupon_type)
        await tg_send(
            user_id,
            f"Send codes line-by-line for:\n{COUPON_TYPES[coupon_type]}\n\nExample:\nCODE1\nCODE2\nCODE3"
        )
        return {"ok": True}

    # Admin change points
    if data.startswith("admin_points_"):
        if not is_admin(user_id):
            await tg_send(user_id, "❌ Not admin.")
            return {"ok": True}

        coupon_type = data.split("_", 2)[2]
        if coupon_type not in COUPON_TYPES:
            await tg_send(user_id, "Invalid coupon type.")
            return {"ok": True}

        await set_state(user_id, mode="await_points", coupon_type=coupon_type)
        await tg_send(
            user_id,
            f"Enter required points for:\n{COUPON_TYPES[coupon_type]}\n\nSend a number (example: 5)"
        )
        return {"ok": True}

    return {"ok": True}


# ================= STATE MESSAGE HANDLER =================
async def handle_state_message(user_id: int, text: str, state: asyncpg.Record) -> bool:
    """
    Handles admin flows:
    - await_codes: admin sends bulk coupon codes
    - await_points: admin sends required points number
    """
    mode = state["mode"]
    coupon_type = state["coupon_type"]

    if mode == "await_codes":
        if not is_admin(user_id):
            await set_state(user_id, None)
            return True

        # Parse codes line-by-line, remove empty lines
        codes = [c.strip() for c in text.splitlines() if c.strip()]
        if not codes:
            await tg_send(user_id, "❌ Please send at least 1 code (line by line).")
            return True

        # Insert codes, ignore duplicates via UNIQUE(code)
        assert pool is not None
        inserted = 0
        async with pool.acquire() as conn:
            async with conn.transaction():
                for code in codes:
                    try:
                        await conn.execute("""
                            INSERT INTO public.coupons(type, code, is_used)
                            VALUES($1,$2,FALSE)
                            ON CONFLICT (code) DO NOTHING
                        """, coupon_type, code)
                        inserted += 1
                    except:
                        # ignore bad code lines
                        pass

        await set_state(user_id, None)

        # Show stock
        assert pool is not None
        async with pool.acquire() as conn:
            stock = await conn.fetchval("""
                SELECT COUNT(*) FROM public.coupons
                WHERE type=$1 AND is_used=FALSE
            """, coupon_type)

        await tg_send(
            user_id,
            f"✅ Added codes for {COUPON_TYPES.get(coupon_type, coupon_type)}\n"
            f"Inserted: {inserted}\n"
            f"Current Stock: {stock}",
            reply_markup=admin_menu_keyboard()
        )
        return True

    if mode == "await_points":
        if not is_admin(user_id):
            await set_state(user_id, None)
            return True

        # must be integer
        try:
            points = int(text.strip())
        except:
            await tg_send(user_id, "❌ Send only a number (example: 5).")
            return True

        if points < 0 or points > 10_000:
            await tg_send(user_id, "❌ Please send a valid points number.")
            return True

        assert pool is not None
        async with pool.acquire() as conn:
            await conn.execute("""
                INSERT INTO public.settings(coupon_type, required_points)
                VALUES($1,$2)
                ON CONFLICT (coupon_type) DO UPDATE
                SET required_points=EXCLUDED.required_points
            """, coupon_type, points)

        await set_state(user_id, None)
        await tg_send(
            user_id,
            f"✅ Updated required points:\n{COUPON_TYPES.get(coupon_type, coupon_type)} → {points} points",
            reply_markup=admin_menu_keyboard()
        )
        return True

    return False

import ssl
import re
import hashlib
import traceback
from typing import Optional, Dict, Any

import asyncpg
import httpx
from fastapi import FastAPI, Request
from fastapi.responses import HTMLResponse, JSONResponse

# ================= CONFIG =================
BOT_TOKEN = "8566776302:AAFzmjD96qirFe5P0OTI7orA29H8lbfEaGU"
DATABASE_URL = "postgresql://postgres.rhswwhjuaxkbjrsquevl:RadheyRadhe@aws-1-ap-south-1.pooler.supabase.com:5432/postgres"
BOT_USERNAME = "Sheinn_Refer_Bot"  # without @
BASE_URL = "https://zenithwave-refer-bot.onrender.com"

BOT_DISPLAY_NAME = "ZenithWave Refer Bot"

ADMIN_IDS = [8537079657, 8222581668]

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

# ================= APP =================
app = FastAPI()
pool: Optional[asyncpg.Pool] = None
http_client: Optional[httpx.AsyncClient] = None

# ================= GLOBAL ERROR HANDLER =================
@app.exception_handler(Exception)
async def all_exception_handler(request: Request, exc: Exception):
    print("🔥 ERROR:", repr(exc))
    traceback.print_exc()
    return JSONResponse(status_code=500, content={"ok": False, "error": str(exc)})

# ================= DB SCHEMA (AUTO CREATE) =================
SCHEMA_SQL = """
CREATE TABLE IF NOT EXISTS public.bot_users (
  user_id BIGINT PRIMARY KEY,
  username TEXT,
  points INTEGER NOT NULL DEFAULT 0,
  total_referrals INTEGER NOT NULL DEFAULT 0,
  referred_by BIGINT NULL,
  verified BOOLEAN NOT NULL DEFAULT FALSE,
  left_penalized BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.coupons (
  id SERIAL PRIMARY KEY,
  type TEXT NOT NULL,
  code TEXT NOT NULL UNIQUE,
  is_used BOOLEAN NOT NULL DEFAULT FALSE,
  used_by BIGINT NULL,
  used_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.redeems (
  id SERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL,
  coupon_type TEXT NOT NULL,
  code TEXT NOT NULL,
  points_used INTEGER NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.settings (
  coupon_type TEXT PRIMARY KEY,
  required_points INTEGER NOT NULL
);

INSERT INTO public.settings (coupon_type, required_points) VALUES
('500', 1),
('1000', 2),
('2000', 3),
('4000', 4)
ON CONFLICT (coupon_type) DO UPDATE
SET required_points = EXCLUDED.required_points;

CREATE TABLE IF NOT EXISTS public.bot_states (
  user_id BIGINT PRIMARY KEY,
  mode TEXT NULL,
  coupon_type TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
"""

# ================= STARTUP / SHUTDOWN =================
@app.on_event("startup")
async def startup():
    global pool, http_client

    # Supabase pooler SSL cert chain often fails verification on Render.
    # This SSL context avoids the SSLCertVerificationError you saw.
    ssl_ctx = ssl.create_default_context()
    ssl_ctx.check_hostname = False
    ssl_ctx.verify_mode = ssl.CERT_NONE

    pool = await asyncpg.create_pool(
        DATABASE_URL,
        min_size=1,
        max_size=10,
        ssl=ssl_ctx
    )

    http_client = httpx.AsyncClient(timeout=httpx.Timeout(10.0))

    async with pool.acquire() as conn:
        await conn.execute(SCHEMA_SQL)

@app.on_event("shutdown")
async def shutdown():
    global pool, http_client
    if http_client:
        await http_client.aclose()
    if pool:
        await pool.close()

# ================= HELPERS =================
def is_admin(uid: int) -> bool:
    return uid in ADMIN_IDS

def make_token(uid: int) -> str:
    return hashlib.sha256(str(uid).encode()).hexdigest()

async def tg(method: str, data: Dict[str, Any]) -> Dict[str, Any]:
    assert http_client is not None
    url = f"https://api.telegram.org/bot{BOT_TOKEN}/{method}"
    r = await http_client.post(url, json=data)
    return r.json()

async def tg_send(chat_id: int, text: str, reply_markup: Optional[dict] = None):
    payload = {"chat_id": chat_id, "text": text, "disable_web_page_preview": True}
    if reply_markup:
        payload["reply_markup"] = reply_markup
    await tg("sendMessage", payload)

def force_join_keyboard():
    rows = []
    for ch in CHANNELS:
        rows.append([{"text": f"Join {ch}", "url": f"https://t.me/{ch[1:]}"}])
    rows.append([{"text": "✅ Joined All Channels", "callback_data": "recheck_join"}])
    return {"inline_keyboard": rows}

def user_menu_keyboard():
    return {"keyboard": [["📊 Stats"], ["🔗 Referral Link"], ["💰 Withdraw"]], "resize_keyboard": True}

def admin_menu_keyboard():
    return {"keyboard": [["➕ Add Coupon"], ["📦 Stock"], ["📜 Redeems Log"], ["⚙ Change Withdraw Points"]], "resize_keyboard": True}

async def check_member(user_id: int, channel: str) -> bool:
    js = await tg("getChatMember", {"chat_id": channel, "user_id": user_id})
    if js.get("ok"):
        return js["result"]["status"] in ("member", "administrator", "creator")
    return False

async def force_join_ok(user_id: int) -> bool:
    for ch in CHANNELS:
        if not await check_member(user_id, ch):
            return False
    return True

async def ensure_user(user_id: int, username: str):
    assert pool is not None
    async with pool.acquire() as conn:
        await conn.execute("""
            INSERT INTO public.bot_users(user_id, username)
            VALUES($1,$2)
            ON CONFLICT (user_id) DO UPDATE
            SET username=EXCLUDED.username, updated_at=NOW()
        """, user_id, username)

async def set_state(user_id: int, mode: Optional[str], coupon_type: Optional[str] = None):
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

async def get_state(user_id: int):
    assert pool is not None
    async with pool.acquire() as conn:
        return await conn.fetchrow("SELECT mode, coupon_type FROM public.bot_states WHERE user_id=$1", user_id)

# ================= TEST ROUTES =================
@app.get("/")
async def home():
    return {"ok": True, "message": "Bot running"}

@app.get("/health")
async def health():
    return {"ok": True}

@app.get("/dbtest")
async def dbtest():
    assert pool is not None
    async with pool.acquire() as conn:
        x = await conn.fetchval("SELECT 1")
    return {"db": x}

# ================= VERIFY UI =================
VERIFY_PAGE_HTML = r"""
<!doctype html><html><head>
<meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>__NAME__ • Verification</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;color:#eaf0ff;
background:radial-gradient(1200px 600px at 15% 10%, rgba(91,140,255,.35), transparent 55%),
radial-gradient(900px 500px at 92% 35%, rgba(155,91,255,.26), transparent 60%),
linear-gradient(160deg,#070a12,#0b1224);font-family:system-ui}
.card{width:min(560px,100%);border-radius:20px;padding:22px;
background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14);
box-shadow:0 20px 70px rgba(0,0,0,.55);position:relative;overflow:hidden}
.btn{width:100%;padding:14px 16px;border:none;border-radius:14px;
background:linear-gradient(135deg,#5b8cff,#2b58ff);color:white;font-weight:800;font-size:16px;cursor:pointer}
.overlay{position:absolute;inset:0;display:none;place-items:center;background:rgba(7,10,18,.72);backdrop-filter:blur(8px)}
.overlay.on{display:grid}
.spinner{width:56px;height:56px;border-radius:50%;border:4px solid rgba(255,255,255,.18);
border-top-color:rgba(91,140,255,.95);animation:spin 1s linear infinite;margin:2px auto 14px}
@keyframes spin{to{transform:rotate(360deg)}}
</style></head><body>
<div class="card">
  <h2 style="margin:0 0 6px">__NAME__</h2>
  <p style="margin:0 0 16px;color:rgba(234,240,255,.72)">Tap verify to unlock the bot menu.</p>
  <form id="vf" method="POST" action="/verify">
    <input type="hidden" name="uid" value="__UID__"/>
    <input type="hidden" name="token" value="__TOKEN__"/>
    <button class="btn" id="vb" type="submit">✅ Verify Now</button>
  </form>
  <div id="ov" class="overlay">
    <div style="text-align:center">
      <div class="spinner"></div>
      <div style="font-weight:700">Verifying…</div>
      <div style="color:rgba(234,240,255,.72)">Please wait</div>
    </div>
  </div>
</div>
<script>
document.getElementById("vf").addEventListener("submit", ()=>{
  document.getElementById("ov").classList.add("on");
  document.getElementById("vb").disabled = true;
});
</script>
</body></html>
"""

SUCCESS_PAGE_HTML = r"""
<!doctype html><html><head>
<meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>__NAME__ • Verified</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;color:#eaf0ff;
background:radial-gradient(1200px 600px at 15% 10%, rgba(91,140,255,.35), transparent 55%),
radial-gradient(900px 500px at 92% 35%, rgba(155,91,255,.26), transparent 60%),
linear-gradient(160deg,#070a12,#0b1224);font-family:system-ui}
.box{width:min(520px,100%);border-radius:20px;padding:22px;background:rgba(255,255,255,.07);
border:1px solid rgba(255,255,255,.14);box-shadow:0 20px 70px rgba(0,0,0,.55);text-align:center}
</style></head><body>
<div class="box">
  <div style="font-size:44px">✅</div>
  <h2 style="margin:6px 0">Verified ✅</h2>
  <p style="margin:0;color:rgba(234,240,255,.72)">Redirecting to Telegram…</p>
  <p style="margin:10px 0 0;color:rgba(234,240,255,.62);font-size:13px">__NAME__</p>
</div>
<script>
setTimeout(()=>{ window.location.href="https://t.me/__BOT__"; }, 1200);
</script>
</body></html>
"""

@app.get("/verify", response_class=HTMLResponse)
async def verify_page(uid: int, token: str):
    if token != make_token(uid):
        return HTMLResponse("<h3>Invalid verification link</h3>", status_code=403)

    html = (VERIFY_PAGE_HTML
            .replace("__NAME__", BOT_DISPLAY_NAME)
            .replace("__UID__", str(uid))
            .replace("__TOKEN__", token))
    return HTMLResponse(html)


@app.post("/verify", response_class=HTMLResponse)
async def verify_submit(request: Request):
    form = await request.form()
    uid = int(form.get("uid", "0"))
    token = str(form.get("token", ""))

    if token != make_token(uid):
        return HTMLResponse("<h3>Invalid verification link</h3>", status_code=403)

    # ... your DB verify logic ...

    html = (SUCCESS_PAGE_HTML
            .replace("__NAME__", BOT_DISPLAY_NAME)
            .replace("__BOT__", BOT_USERNAME))
    return HTMLResponse(html)

    # Must still be in all channels
    if not await force_join_ok(uid):
        return HTMLResponse("<h3>Please join all channels first, then verify again.</h3>", status_code=403)

    assert pool is not None
    async with pool.acquire() as conn:
        async with conn.transaction():
            row = await conn.fetchrow("SELECT verified, referred_by FROM public.bot_users WHERE user_id=$1 FOR UPDATE", uid)
            if not row:
                await conn.execute("INSERT INTO public.bot_users(user_id, verified) VALUES($1, TRUE)", uid)
                row = {"verified": False, "referred_by": None}

            if not row["verified"]:
                await conn.execute("UPDATE public.bot_users SET verified=TRUE, updated_at=NOW() WHERE user_id=$1", uid)
                ref = row["referred_by"]
                if ref:
                    await conn.execute("""
                        UPDATE public.bot_users
                        SET points=points+1, total_referrals=total_referrals+1, updated_at=NOW()
                        WHERE user_id=$1
                    """, ref)

    return HTMLResponse(SUCCESS_PAGE_HTML.format(name=BOT_DISPLAY_NAME, bot=BOT_USERNAME))

# ================= WEBHOOK =================
@app.post("/webhook")
async def webhook(req: Request):
    data = await req.json()

    # callbacks
    if "callback_query" in data:
        return await handle_callback(data["callback_query"])

    if "message" not in data:
        return {"ok": True}

    msg = data["message"]
    u = msg.get("from", {})
    user_id = int(u.get("id"))
    username = u.get("username", "") or ""
    text = msg.get("text", "") or ""

    await ensure_user(user_id, username)

    # state flows for admin
    st = await get_state(user_id)
    if st and st["mode"]:
        if await handle_state_message(user_id, text, st):
            return {"ok": True}

    # start
    if text.startswith("/start"):
        # referral param
        ref_id = None
        m = re.search(r"ref_(\d+)", text)
        if m:
            try:
                ref_id = int(m.group(1))
            except:
                ref_id = None

        if not await force_join_ok(user_id):
            await tg_send(user_id, "⚠️ Join all channels first.", reply_markup=force_join_keyboard())
            return {"ok": True}

        # save ref once
        if ref_id and ref_id != user_id:
            assert pool is not None
            async with pool.acquire() as conn:
                await conn.execute("""
                    UPDATE public.bot_users
                    SET referred_by=$1, updated_at=NOW()
                    WHERE user_id=$2 AND referred_by IS NULL
                """, ref_id, user_id)

        token = make_token(user_id)
        verify_link = f"{BASE_URL}/verify?uid={user_id}&token={token}"

        await tg_send(user_id, "🌐 Complete web verification:",
            reply_markup={"inline_keyboard":[
                [{"text":"Verify Now","url":verify_link}],
                [{"text":"Check Verification","callback_data":"check_verify"}]
            ]}
        )
        return {"ok": True}

    # user menu
    if text == "📊 Stats":
        assert pool is not None
        async with pool.acquire() as conn:
            row = await conn.fetchrow("SELECT points,total_referrals,verified FROM public.bot_users WHERE user_id=$1", user_id)
        if not row or not row["verified"]:
            await tg_send(user_id, "❌ Please verify first. Send /start")
            return {"ok": True}
        await tg_send(user_id, f"👥 Referrals: {row['total_referrals']}\n⭐ Points: {row['points']}")
        return {"ok": True}

    if text == "🔗 Referral Link":
        assert pool is not None
        async with pool.acquire() as conn:
            verified = await conn.fetchval("SELECT verified FROM public.bot_users WHERE user_id=$1", user_id)
        if not verified:
            await tg_send(user_id, "❌ Please verify first. Send /start")
            return {"ok": True}
        link = f"https://t.me/{BOT_USERNAME}?start=ref_{user_id}"
        await tg_send(user_id, f"🔗 Your referral link:\n{link}")
        return {"ok": True}

    if text == "💰 Withdraw":
        assert pool is not None
        async with pool.acquire() as conn:
            verified = await conn.fetchval("SELECT verified FROM public.bot_users WHERE user_id=$1", user_id)
        if not verified:
            await tg_send(user_id, "❌ Please verify first. Send /start")
            return {"ok": True}

        kb = []
        async with pool.acquire() as conn:
            for k, label in COUPON_TYPES.items():
                stock = await conn.fetchval("SELECT COUNT(*) FROM public.coupons WHERE type=$1 AND is_used=FALSE", k)
                kb.append([{"text": f"{label} (Stock: {stock})", "callback_data": f"withdraw_{k}"}])

        await tg_send(user_id, "Select withdraw option:", reply_markup={"inline_keyboard": kb})
        return {"ok": True}

    # admin
    if text == "/admin":
        if not is_admin(user_id):
            await tg_send(user_id, "❌ Not admin.")
            return {"ok": True}
        await tg_send(user_id, "✅ Admin Panel", reply_markup=admin_menu_keyboard())
        return {"ok": True}

    if is_admin(user_id) and text == "➕ Add Coupon":
        kb = [[{"text": COUPON_TYPES[k], "callback_data": f"admin_add_{k}"}] for k in COUPON_TYPES]
        await tg_send(user_id, "Select type to add:", reply_markup={"inline_keyboard": kb})
        return {"ok": True}

    if is_admin(user_id) and text == "📦 Stock":
        lines = ["📦 Stock:\n"]
        async with pool.acquire() as conn:
            for k, label in COUPON_TYPES.items():
                stock = await conn.fetchval("SELECT COUNT(*) FROM public.coupons WHERE type=$1 AND is_used=FALSE", k)
                lines.append(f"{label}: {stock}")
        await tg_send(user_id, "\n".join(lines))
        return {"ok": True}

    if is_admin(user_id) and text == "📜 Redeems Log":
        async with pool.acquire() as conn:
            rows = await conn.fetch("SELECT user_id,coupon_type,code,points_used FROM public.redeems ORDER BY id DESC LIMIT 10")
        if not rows:
            await tg_send(user_id, "No redeems yet.")
            return {"ok": True}
        out = ["📜 Last 10 Redeems:\n"]
        for r in rows:
            out.append(f"User: {r['user_id']} | {COUPON_TYPES.get(r['coupon_type'], r['coupon_type'])} | {r['code']} | -{r['points_used']} pts")
        await tg_send(user_id, "\n".join(out))
        return {"ok": True}

    if is_admin(user_id) and text == "⚙ Change Withdraw Points":
        kb = [[{"text": COUPON_TYPES[k], "callback_data": f"admin_points_{k}"}] for k in COUPON_TYPES]
        await tg_send(user_id, "Select type to change points:", reply_markup={"inline_keyboard": kb})
        return {"ok": True}

    return {"ok": True}

# ================= CALLBACKS =================
async def handle_callback(cb: dict):
    cb_id = cb["id"]
    user_id = int(cb["from"]["id"])
    data = cb.get("data", "")

    await tg("answerCallbackQuery", {"callback_query_id": cb_id})
    await ensure_user(user_id, cb["from"].get("username", "") or "")

    if data == "recheck_join":
        if await force_join_ok(user_id):
            await tg_send(user_id, "✅ Channels verified. Send /start now.")
        else:
            await tg_send(user_id, "❌ You still haven't joined all channels.", reply_markup=force_join_keyboard())
        return {"ok": True}

    if data == "check_verify":
        assert pool is not None
        async with pool.acquire() as conn:
            verified = await conn.fetchval("SELECT verified FROM public.bot_users WHERE user_id=$1", user_id)
        if verified:
            await tg_send(user_id, "✅ Verified!", reply_markup=user_menu_keyboard())
        else:
            await tg_send(user_id, "❌ Not verified yet. Send /start and verify.")
        return {"ok": True}

    if data.startswith("withdraw_"):
        ctype = data.split("_", 1)[1]
        if ctype not in COUPON_TYPES:
            await tg_send(user_id, "Invalid option.")
            return {"ok": True}

        if not await force_join_ok(user_id):
            await tg_send(user_id, "⚠️ Join all channels first.", reply_markup=force_join_keyboard())
            return {"ok": True}

        assert pool is not None
        async with pool.acquire() as conn:
            async with conn.transaction():
                verified = await conn.fetchval("SELECT verified FROM public.bot_users WHERE user_id=$1", user_id)
                if not verified:
                    await tg_send(user_id, "❌ Verify first. Send /start")
                    return {"ok": True}

                required = await conn.fetchval("SELECT required_points FROM public.settings WHERE coupon_type=$1", ctype)
                points = await conn.fetchval("SELECT points FROM public.bot_users WHERE user_id=$1 FOR UPDATE", user_id)

                if points is None:
                    await tg_send(user_id, "Send /start first.")
                    return {"ok": True}

                if points < required:
                    await tg_send(user_id, "❌ Not enough points.")
                    return {"ok": True}

                coupon = await conn.fetchrow("""
                    SELECT id, code FROM public.coupons
                    WHERE type=$1 AND is_used=FALSE
                    ORDER BY id ASC
                    FOR UPDATE SKIP LOCKED
                    LIMIT 1
                """, ctype)

                if not coupon:
                    await tg_send(user_id, "❌ Out of stock.")
                    return {"ok": True}

                await conn.execute("UPDATE public.coupons SET is_used=TRUE, used_by=$1, used_at=NOW() WHERE id=$2", user_id, coupon["id"])
                await conn.execute("UPDATE public.bot_users SET points=points-$1, updated_at=NOW() WHERE user_id=$2", required, user_id)
                await conn.execute("INSERT INTO public.redeems(user_id,coupon_type,code,points_used) VALUES($1,$2,$3,$4)", user_id, ctype, coupon["code"], required)

        await tg_send(user_id, f"🎉 Redeemed!\n{COUPON_TYPES[ctype]}\n\n✅ Code:\n{coupon['code']}")

        for a in ADMIN_IDS:
            await tg_send(a, f"🧾 Redeem\nUser: {user_id}\nType: {COUPON_TYPES[ctype]}\nPoints: -{required}\nCode: {coupon['code']}")
        return {"ok": True}

    if data.startswith("admin_add_"):
        if not is_admin(user_id):
            return {"ok": True}
        ctype = data.split("_", 2)[2]
        await set_state(user_id, "await_codes", ctype)
        await tg_send(user_id, f"Send codes line-by-line for {COUPON_TYPES.get(ctype, ctype)}")
        return {"ok": True}

    if data.startswith("admin_points_"):
        if not is_admin(user_id):
            return {"ok": True}
        ctype = data.split("_", 2)[2]
        await set_state(user_id, "await_points", ctype)
        await tg_send(user_id, f"Send required points number for {COUPON_TYPES.get(ctype, ctype)}")
        return {"ok": True}

    return {"ok": True}

# ================= STATE INPUT =================
async def handle_state_message(user_id: int, text: str, st) -> bool:
    mode = st["mode"]
    ctype = st["coupon_type"]

    if mode == "await_codes":
        if not is_admin(user_id):
            await set_state(user_id, None)
            return True

        codes = [c.strip() for c in text.splitlines() if c.strip()]
        if not codes:
            await tg_send(user_id, "❌ Send codes line-by-line.")
            return True

        assert pool is not None
        inserted = 0
        async with pool.acquire() as conn:
            async with conn.transaction():
                for code in codes:
                    await conn.execute("""
                        INSERT INTO public.coupons(type, code) VALUES($1,$2)
                        ON CONFLICT (code) DO NOTHING
                    """, ctype, code)
                    inserted += 1

        await set_state(user_id, None)
        await tg_send(user_id, f"✅ Added {inserted} codes.", reply_markup=admin_menu_keyboard())
        return True

    if mode == "await_points":
        if not is_admin(user_id):
            await set_state(user_id, None)
            return True

        try:
            pts = int(text.strip())
        except:
            await tg_send(user_id, "❌ Send a number only.")
            return True

        assert pool is not None
        async with pool.acquire() as conn:
            await conn.execute("""
                INSERT INTO public.settings(coupon_type, required_points)
                VALUES($1,$2)
                ON CONFLICT (coupon_type) DO UPDATE
                SET required_points=EXCLUDED.required_points
            """, ctype, pts)

        await set_state(user_id, None)
        await tg_send(user_id, f"✅ Updated points: {COUPON_TYPES.get(ctype, ctype)} → {pts}", reply_markup=admin_menu_keyboard())
        return True

    return False

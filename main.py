import os
import hashlib
import asyncpg
import httpx
from fastapi import FastAPI, Request
from fastapi.responses import HTMLResponse, RedirectResponse
from datetime import datetime

# ================= CONFIG =================
BOT_TOKEN = "8566776302:AAFzmjD96qirFe5P0OTI7orA29H8lbfEaGU"
DATABASE_URL = "postgresql://postgres.rhswwhjuaxkbjrsquevl:RadheyRadhe@aws-1-ap-south-1.pooler.supabase.com:5432/postgres"
BOT_USERNAME = "Sheinn_Refer_Bot"
BASE_URL = "https://zenithwave-refer-bot.onrender.com"

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

app = FastAPI()
pool = None

# ================= DATABASE =================
@app.on_event("startup")
async def startup():
    global pool
    pool = await asyncpg.create_pool(DATABASE_URL)

# ================= TELEGRAM API =================
async def tg(method, data):
    async with httpx.AsyncClient() as client:
        await client.post(
            f"https://api.telegram.org/bot{BOT_TOKEN}/{method}",
            json=data
        )

async def check_member(user_id, channel):
    async with httpx.AsyncClient() as client:
        r = await client.post(
            f"https://api.telegram.org/bot{BOT_TOKEN}/getChatMember",
            json={"chat_id": channel, "user_id": user_id}
        )
        js = r.json()
        if js.get("ok"):
            return js["result"]["status"] in ["member", "administrator", "creator"]
    return False

async def force_join(user_id):
    for ch in CHANNELS:
        if not await check_member(user_id, ch):
            return False
    return True

# ================= WEBHOOK =================
@app.post("/webhook")
async def webhook(req: Request):
    data = await req.json()

    if "callback_query" in data:
        return await handle_callback(data["callback_query"])

    if "message" not in data:
        return {"ok": True}

    msg = data["message"]
    user = msg["from"]
    user_id = user["id"]
    username = user.get("username", "")
    text = msg.get("text", "")

    async with pool.acquire() as conn:
        await conn.execute("""
            INSERT INTO users(user_id, username)
            VALUES($1,$2)
            ON CONFLICT (user_id) DO NOTHING
        """, user_id, username)

    # ================= START =================
    if text.startswith("/start"):

        ref_id = None
        if "ref_" in text:
            ref_id = int(text.split("ref_")[1])

        if not await force_join(user_id):
            keyboard = []
            for ch in CHANNELS:
                keyboard.append([{
                    "text": f"Join {ch}",
                    "url": f"https://t.me/{ch[1:]}"
                }])
            keyboard.append([{
                "text": "✅ Joined All Channels",
                "callback_data": "recheck_join"
            }])

            await tg("sendMessage", {
                "chat_id": user_id,
                "text": "⚠️ Please join all channels first.",
                "reply_markup": {"inline_keyboard": keyboard}
            })
            return {"ok": True}

        token = hashlib.sha256(str(user_id).encode()).hexdigest()
        verify_link = f"{BASE_URL}/verify?uid={user_id}&token={token}"

        await tg("sendMessage", {
            "chat_id": user_id,
            "text": "🌐 Please complete web verification.",
            "reply_markup": {
                "inline_keyboard": [
                    [{"text": "Verify Now", "url": verify_link}],
                    [{"text": "Check Verification", "callback_data": "check_verify"}]
                ]
            }
        })

        if ref_id and ref_id != user_id:
            async with pool.acquire() as conn:
                await conn.execute("""
                    UPDATE users SET referred_by=$1 WHERE user_id=$2
                """, ref_id, user_id)

    # ================= USER MENU =================
    if text == "📊 Stats":
        async with pool.acquire() as conn:
            row = await conn.fetchrow("""
                SELECT points,total_referrals FROM users WHERE user_id=$1
            """, user_id)

        await tg("sendMessage", {
            "chat_id": user_id,
            "text": f"👥 Referrals: {row['total_referrals']}\n⭐ Points: {row['points']}"
        })

    if text == "🔗 Referral Link":
        link = f"https://t.me/{BOT_USERNAME}?start=ref_{user_id}"
        await tg("sendMessage", {
            "chat_id": user_id,
            "text": f"Share this link:\n{link}"
        })

    if text == "💰 Withdraw":
        keyboard = []
        async with pool.acquire() as conn:
            for key, label in COUPON_TYPES.items():
                stock = await conn.fetchval("""
                    SELECT COUNT(*) FROM coupons
                    WHERE type=$1 AND is_used=FALSE
                """, key)

                keyboard.append([{
                    "text": f"{label} (Stock: {stock})",
                    "callback_data": f"withdraw_{key}"
                }])

        await tg("sendMessage", {
            "chat_id": user_id,
            "text": "Select Withdraw Option:",
            "reply_markup": {"inline_keyboard": keyboard}
        })

    # ================= ADMIN PANEL =================
    if user_id in ADMIN_IDS:

        if text == "/admin":
            await tg("sendMessage", {
                "chat_id": user_id,
                "text": "Admin Panel",
                "reply_markup": {
                    "keyboard": [
                        ["➕ Add Coupon"],
                        ["📦 Stock"],
                        ["📜 Redeems Log"],
                        ["⚙ Change Withdraw Points"]
                    ],
                    "resize_keyboard": True
                }
            })

        if text == "📦 Stock":
            message = "📦 Coupon Stock:\n\n"
            async with pool.acquire() as conn:
                for key, label in COUPON_TYPES.items():
                    stock = await conn.fetchval("""
                        SELECT COUNT(*) FROM coupons
                        WHERE type=$1 AND is_used=FALSE
                    """, key)
                    message += f"{label}: {stock}\n"

            await tg("sendMessage", {
                "chat_id": user_id,
                "text": message
            })

        if text == "📜 Redeems Log":
            async with pool.acquire() as conn:
                rows = await conn.fetch("""
                    SELECT * FROM redeems
                    ORDER BY id DESC
                    LIMIT 10
                """)

            message = "📜 Last 10 Redeems:\n\n"
            for r in rows:
                message += f"User: {r['user_id']} | {r['coupon_type']} | {r['code']}\n"

            await tg("sendMessage", {
                "chat_id": user_id,
                "text": message
            })

    return {"ok": True}

# ================= CALLBACK HANDLER =================
async def handle_callback(cb):
    user_id = cb["from"]["id"]
    data = cb["data"]

    await tg("answerCallbackQuery", {"callback_query_id": cb["id"]})

    if data == "recheck_join":
        if await force_join(user_id):
            await tg("sendMessage", {
                "chat_id": user_id,
                "text": "✅ Channels verified."
            })
        else:
            await tg("sendMessage", {
                "chat_id": user_id,
                "text": "❌ You still haven't joined all channels."
            })

    if data == "check_verify":
        async with pool.acquire() as conn:
            verified = await conn.fetchval("""
                SELECT verified FROM users WHERE user_id=$1
            """, user_id)

        if verified:
            await tg("sendMessage", {
                "chat_id": user_id,
                "text": "✅ Verification successful!",
                "reply_markup": {
                    "keyboard": [
                        ["📊 Stats"],
                        ["🔗 Referral Link"],
                        ["💰 Withdraw"]
                    ],
                    "resize_keyboard": True
                }
            })
        else:
            await tg("sendMessage", {
                "chat_id": user_id,
                "text": "❌ Verification not completed."
            })

    if data.startswith("withdraw_"):
        coupon_type = data.split("_")[1]

        async with pool.acquire() as conn:
            points = await conn.fetchval("""
                SELECT points FROM users WHERE user_id=$1
            """, user_id)

            required = await conn.fetchval("""
                SELECT required_points FROM settings WHERE coupon_type=$1
            """, coupon_type)

            stock = await conn.fetchval("""
                SELECT COUNT(*) FROM coupons
                WHERE type=$1 AND is_used=FALSE
            """, coupon_type)

            if stock == 0:
                await tg("sendMessage", {
                    "chat_id": user_id,
                    "text": "❌ Out of stock."
                })
                return {"ok": True}

            if points < required:
                await tg("sendMessage", {
                    "chat_id": user_id,
                    "text": "❌ Not enough referral points."
                })
                return {"ok": True}

            coupon = await conn.fetchrow("""
                SELECT id, code FROM coupons
                WHERE type=$1 AND is_used=FALSE
                LIMIT 1
            """, coupon_type)

            await conn.execute("""
                UPDATE coupons SET is_used=TRUE WHERE id=$1
            """, coupon["id"])

            await conn.execute("""
                UPDATE users SET points=points-$1 WHERE user_id=$2
            """, required, user_id)

            await conn.execute("""
                INSERT INTO redeems(user_id,coupon_type,code,points_used)
                VALUES($1,$2,$3,$4)
            """, user_id, coupon_type, coupon["code"], required)

        await tg("sendMessage", {
            "chat_id": user_id,
            "text": f"🎉 Coupon Redeemed:\n{coupon['code']}"
        })

        for admin in ADMIN_IDS:
            await tg("sendMessage", {
                "chat_id": admin,
                "text": f"User {user_id} redeemed {coupon_type}"
            })

    return {"ok": True}

# ================= WEB VERIFICATION =================
BOT_DISPLAY_NAME = "ZenithWave Refer Bot"  # Change name shown on web page

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
    expected = hashlib.sha256(str(uid).encode()).hexdigest()
    if token != expected:
        return HTMLResponse("<h3>Invalid verification link</h3>", status_code=403)

    return HTMLResponse(VERIFY_PAGE_HTML.format(uid=uid, token=token, name=BOT_DISPLAY_NAME))

@app.post("/verify", response_class=HTMLResponse)
async def verify_submit(request: Request):
    form = await request.form()
    uid = int(form.get("uid", "0"))
    token = str(form.get("token", ""))

    expected = hashlib.sha256(str(uid).encode()).hexdigest()
    if token != expected:
        return HTMLResponse("<h3>Invalid verification link</h3>", status_code=403)

    async with pool.acquire() as conn:
        already = await conn.fetchval("SELECT verified FROM users WHERE user_id=$1", uid)
        if not already:
            await conn.execute("UPDATE users SET verified=TRUE WHERE user_id=$1", uid)

            ref = await conn.fetchval("SELECT referred_by FROM users WHERE user_id=$1", uid)
            if ref:
                await conn.execute("""
                    UPDATE users
                    SET points = points + 1,
                        total_referrals = total_referrals + 1
                    WHERE user_id = $1
                """, ref)

    return HTMLResponse(SUCCESS_PAGE_HTML.format(name=BOT_DISPLAY_NAME, bot=BOT_USERNAME))

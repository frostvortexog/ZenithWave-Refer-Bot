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
BASE_URL = "https://your-render-url.onrender.com"

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
@app.get("/verify", response_class=HTMLResponse)
async def verify(uid: int, token: str):
    async with pool.acquire() as conn:
        await conn.execute("""
            UPDATE users SET verified=TRUE WHERE user_id=$1
        """, uid)

        ref = await conn.fetchval("""
            SELECT referred_by FROM users WHERE user_id=$1
        """, uid)

        if ref:
            await conn.execute("""
                UPDATE users SET
                points=points+1,
                total_referrals=total_referrals+1
                WHERE user_id=$1
            """, ref)

    return RedirectResponse(f"https://t.me/{BOT_USERNAME}")

import re
import hmac
import asyncio
import hashlib
from typing import Optional, List

import asyncpg
from fastapi import FastAPI, Request
from fastapi.responses import HTMLResponse, JSONResponse

from aiogram import Bot, Dispatcher, F, types
from aiogram.client.default import DefaultBotProperties
from aiogram.types import (
    ReplyKeyboardMarkup, KeyboardButton,
    InlineKeyboardMarkup, InlineKeyboardButton
)
from aiogram.fsm.storage.memory import MemoryStorage
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import State, StatesGroup


# =========================
# CONFIG (PUT YOUR VALUES)
# =========================
BOT_TOKEN = "8485339280:AAF_gJl7Wnz0dBHAEQTVFIHzf6KsWL_bAYU"
DATABASE_URL = "postgresql://postgres.rhswwhjuaxkbjrsquevl:RadheyRadhe@aws-1-ap-south-1.pooler.supabase.com:5432/postgres"
ADMIN_IDS = [6313009653, 8222581668]  # 2 admins
BOT_USERNAME = "sheinvoucherlootbot"  # ex: RedeemCodeRefer_bot
PUBLIC_BASE_URL = "https://zenithwave-refer-bot.onrender.com"  # Render service URL

WEBHOOK_PATH = "/webhook"
WEBHOOK_URL = f"{PUBLIC_BASE_URL}{WEBHOOK_PATH}"

CHANNEL_AUDIT_SECONDS = 300  # 5 min


# =========================
# INIT
# =========================
app = FastAPI()
storage = MemoryStorage()
dp = Dispatcher(storage=storage)
bot = Bot(BOT_TOKEN, default=DefaultBotProperties(parse_mode="HTML"))
db: Optional[asyncpg.Pool] = None


# =========================
# FSM STATES
# =========================
class AdminAddCoupon(StatesGroup):
    choosing_type = State()
    waiting_codes = State()

class AdminChangePoints(StatesGroup):
    choosing_type = State()
    waiting_points = State()

class AdminAddChannels(StatesGroup):
    waiting_channels = State()


# =========================
# HELPERS
# =========================
def is_admin(user_id: int) -> bool:
    return user_id in ADMIN_IDS

def sign_user(user_id: int) -> str:
    msg = str(user_id).encode()
    return hmac.new(BOT_TOKEN.encode(), msg, hashlib.sha256).hexdigest()[:24]

def norm_channel_username(s: str) -> Optional[str]:
    s = s.strip()
    s = s.replace("https://t.me/", "").replace("t.me/", "")
    if s.startswith("@"):
        s = s[1:]
    s = s.strip()
    if not s:
        return None
    if not re.fullmatch(r"[A-Za-z0-9_]{5,32}", s):
        return None
    return s

async def fetch_channels() -> List[str]:
    rows = await db.fetch("SELECT channel_username FROM channels ORDER BY id ASC")
    return [r["channel_username"] for r in rows]

async def user_is_joined_all(user_id: int) -> bool:
    channels = await fetch_channels()
    if not channels:
        return True
    for ch in channels:
        try:
            member = await bot.get_chat_member(f"@{ch}", user_id)
            if member.status in ("left", "kicked"):
                return False
        except:
            return False
    return True

async def notify_admins(text: str):
    for aid in ADMIN_IDS:
        try:
            await bot.send_message(aid, text)
        except:
            pass

def main_user_keyboard() -> ReplyKeyboardMarkup:
    return ReplyKeyboardMarkup(
        keyboard=[
            [KeyboardButton(text="📊 Stats")],
            [KeyboardButton(text="🔗 Referral Link")],
            [KeyboardButton(text="💰 Withdraw")]
        ],
        resize_keyboard=True
    )

def admin_keyboard() -> ReplyKeyboardMarkup:
    return ReplyKeyboardMarkup(
        keyboard=[
            [KeyboardButton(text="➕ Add Coupon")],
            [KeyboardButton(text="📦 Stock")],
            [KeyboardButton(text="📜 Redeem Logs")],
            [KeyboardButton(text="⚙ Change Withdraw Points")],
            [KeyboardButton(text="➕ Add Channels")],
            [KeyboardButton(text="➖ Remove Channel")],
        ],
        resize_keyboard=True
    )

def coupon_type_buttons() -> ReplyKeyboardMarkup:
    return ReplyKeyboardMarkup(
        keyboard=[
            [KeyboardButton(text="500 OFF on 500")],
            [KeyboardButton(text="1000 OFF on 1000")],
            [KeyboardButton(text="2000 OFF on 2000")],
            [KeyboardButton(text="4000 OFF on 4000")],
        ],
        resize_keyboard=True
    )

def parse_coupon_type_from_text(text: str) -> Optional[str]:
    t = (text or "").strip()
    m = re.match(r"^(\d{3,4})\s+OFF", t, re.IGNORECASE)
    if m and m.group(1) in ("500", "1000", "2000", "4000"):
        return m.group(1)
    m = re.match(r"^(\d{3,4})\s+OFF\s*\(\d+\)", t, re.IGNORECASE)
    if m and m.group(1) in ("500", "1000", "2000", "4000"):
        return m.group(1)
    return None

def verify_keyboard() -> ReplyKeyboardMarkup:
    return ReplyKeyboardMarkup(
        keyboard=[
            [KeyboardButton(text="✅ Verify Now")],
            [KeyboardButton(text="🔎 Check Verification")]
        ],
        resize_keyboard=True
    )

def join_inline_keyboard(channels: List[str]) -> InlineKeyboardMarkup:
    # Better Join UI: inline join links + "Joined, Check" button
    rows = []
    for i, ch in enumerate(channels, start=1):
        rows.append([InlineKeyboardButton(text=f"➡️ Join Channel {i}", url=f"https://t.me/{ch}")])
    rows.append([InlineKeyboardButton(text="✅ Joined, Check", callback_data="join_check")])
    return InlineKeyboardMarkup(inline_keyboard=rows)


# =========================
# WEB PAGES (VERIFY)
# =========================
@app.get("/v/{user_id}", response_class=HTMLResponse)
async def verify_page(user_id: int, token: str):
    if token != sign_user(user_id):
        return HTMLResponse("<h3>Invalid verification link</h3>", status_code=400)

    html = f"""
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Verification</title>
  <style>
    body {{ font-family: Arial, sans-serif; padding: 20px; }}
    .box {{ max-width: 520px; margin: 0 auto; border: 1px solid #ddd; border-radius: 12px; padding: 18px; }}
    button {{ width: 100%; padding: 14px; border: 0; border-radius: 10px; font-size: 16px; cursor: pointer; }}
  </style>
</head>
<body>
  <div class="box">
    <h2>✅ Web Verification</h2>
    <p>Click Verify Now to complete verification, then return to Telegram and press <b>Check Verification</b>.</p>
    <button id="btn">Verify Now</button>
    <p id="msg" style="margin-top:12px;"></p>
  </div>

<script>
(function() {{
  function getDeviceId() {{
    let d = localStorage.getItem("device_id");
    if (!d) {{
      d = (crypto.randomUUID ? crypto.randomUUID() : String(Math.random()).slice(2) + Date.now());
      localStorage.setItem("device_id", d);
    }}
    return d;
  }}

  const btn = document.getElementById("btn");
  const msg = document.getElementById("msg");
  btn.addEventListener("click", async () => {{
    btn.disabled = true;
    msg.innerText = "Verifying...";
    const device_id = getDeviceId();
    const res = await fetch("/api/verify", {{
      method: "POST",
      headers: {{ "Content-Type": "application/json" }},
      body: JSON.stringify({{ user_id: {user_id}, token: "{token}", device_id }})
    }});
    const data = await res.json();
    if (data.ok) {{
      msg.innerHTML = "✅ Verified! <br><br>Redirecting to Telegram...";
      window.location.href = "https://t.me/{BOT_USERNAME}";
    }} else {{
      msg.innerText = "❌ " + (data.error || "Verification failed");
      btn.disabled = false;
    }}
  }});
}})();
</script>
</body>
</html>
"""
    return HTMLResponse(html)

@app.post("/api/verify")
async def api_verify(request: Request):
    body = await request.json()
    user_id = int(body.get("user_id", 0))
    token = body.get("token", "")
    device_id = str(body.get("device_id", "")).strip()

    if not user_id or not token or not device_id:
        return JSONResponse({"ok": False, "error": "Missing fields"}, status_code=400)
    if token != sign_user(user_id):
        return JSONResponse({"ok": False, "error": "Invalid token"}, status_code=400)

    device_hash = hashlib.sha256(device_id.encode()).hexdigest()

    async with db.acquire() as con:
        other = await con.fetchrow(
            "SELECT id FROM users WHERE device_id=$1 AND id <> $2 AND verified=TRUE",
            device_hash, user_id
        )
        if other:
            return JSONResponse({"ok": False, "error": "This device is already verified with another Telegram account."}, status_code=403)

        user = await con.fetchrow("SELECT id FROM users WHERE id=$1", user_id)
        if not user:
            return JSONResponse({"ok": False, "error": "User not found. Start the bot first."}, status_code=404)

        await con.execute(
            "UPDATE users SET verified=TRUE, device_id=$1 WHERE id=$2",
            device_hash, user_id
        )

    return JSONResponse({"ok": True})


# =========================
# TELEGRAM WEBHOOK
# =========================
@app.post(WEBHOOK_PATH)
async def webhook(request: Request):
    data = await request.json()
    update = types.Update(**data)
    await dp.feed_update(bot, update)
    return {"ok": True}


# =========================
# JOIN FLOW (BETTER UI)
# =========================
async def prompt_force_join(chat_id: int):
    channels = await fetch_channels()
    if not channels:
        await bot.send_message(chat_id, "✅ No force-join channels set. Continue.", reply_markup=verify_keyboard())
        return

    text = "🚨 <b>Join all channels first</b>\n\n"
    for i, ch in enumerate(channels, start=1):
        text += f"{i}) @<b>{ch}</b>\n"
    text += "\nAfter joining, tap <b>✅ Joined, Check</b>."

    await bot.send_message(chat_id, text, reply_markup=join_inline_keyboard(channels))

@dp.callback_query(F.data == "join_check")
async def cb_join_check(call: types.CallbackQuery):
    user_id = call.from_user.id
    if await user_is_joined_all(user_id):
        await call.message.edit_text("✅ Joined confirmed.\n\n🔐 Web Verification Required.")
        await bot.send_message(user_id, "Choose:", reply_markup=verify_keyboard())
    else:
        await call.answer("❌ You still haven’t joined all channels.", show_alert=True)


# =========================
# USER FLOW
# =========================
@dp.message(F.text.startswith("/start"))
async def cmd_start(message: types.Message):
    user_id = message.from_user.id
    username = message.from_user.username or ""

    args = message.text.split()
    referred_by = None
    if len(args) > 1:
        try:
            referred_by = int(args[1])
            if referred_by == user_id:
                referred_by = None
        except:
            referred_by = None

    async with db.acquire() as con:
        user = await con.fetchrow("SELECT id FROM users WHERE id=$1", user_id)
        if not user:
            await con.execute(
                "INSERT INTO users (id, username, referred_by) VALUES ($1,$2,$3)",
                user_id, username, referred_by
            )
        else:
            await con.execute("UPDATE users SET username=$1 WHERE id=$2", username, user_id)

    if not await user_is_joined_all(user_id):
        await prompt_force_join(user_id)
        return

    await message.answer("🔐 <b>Web Verification Required</b>", reply_markup=verify_keyboard())


@dp.message(F.text == "✅ Verify Now")
async def verify_now(message: types.Message):
    user_id = message.from_user.id
    if not await user_is_joined_all(user_id):
        await prompt_force_join(user_id)
        return

    token = sign_user(user_id)
    url = f"{PUBLIC_BASE_URL}/v/{user_id}?token={token}"
    ikb = InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="🔐 Open Verification Page", url=url)]
    ])
    await message.answer("Tap the button to verify on web:", reply_markup=ikb)


@dp.message(F.text == "🔎 Check Verification")
async def check_verification(message: types.Message):
    user_id = message.from_user.id

    if not await user_is_joined_all(user_id):
        await message.answer("🚨 You must stay joined to all required channels.")
        return

    async with db.acquire() as con:
        user = await con.fetchrow("SELECT * FROM users WHERE id=$1", user_id)
        if not user:
            await message.answer("Send /start first.")
            return
        if not user["verified"]:
            await message.answer("❌ Not verified yet. Click ✅ Verify Now and complete web verification.")
            return

        # Award referral once using redeem_logs marker
        awarded = await con.fetchrow(
            "SELECT 1 FROM redeem_logs WHERE user_id=$1 AND coupon_type='REF_AWARD' LIMIT 1",
            user_id
        )
        if not awarded and user["referred_by"]:
            await con.execute("UPDATE users SET points=points+1 WHERE id=$1", user["referred_by"])
            await con.execute(
                "INSERT INTO redeem_logs (user_id, coupon_type, coupon_code) VALUES ($1,'REF_AWARD',$2)",
                user_id, str(user["referred_by"])
            )

    await message.answer("✅ Verification complete! Use the menu:", reply_markup=main_user_keyboard())


@dp.message(F.text == "📊 Stats")
async def stats(message: types.Message):
    user_id = message.from_user.id
    async with db.acquire() as con:
        user = await con.fetchrow("SELECT points FROM users WHERE id=$1", user_id)
        if not user:
            await message.answer("Send /start first.")
            return
        refs = await con.fetchval("SELECT COUNT(*) FROM users WHERE referred_by=$1", user_id)

    await message.answer(f"👥 Referrals: <b>{refs}</b>\n⭐ Points: <b>{user['points']}</b>")


@dp.message(F.text == "🔗 Referral Link")
async def referral_link(message: types.Message):
    link = f"https://t.me/{BOT_USERNAME}?start={message.from_user.id}"
    await message.answer(f"🔗 Your referral link:\n{link}")


@dp.message(F.text == "💰 Withdraw")
async def withdraw_menu(message: types.Message):
    user_id = message.from_user.id
    if not await user_is_joined_all(user_id):
        await message.answer("🚨 You must stay joined to all required channels to withdraw.")
        return

    async with db.acquire() as con:
        user = await con.fetchrow("SELECT verified, points FROM users WHERE id=$1", user_id)
        if not user or not user["verified"]:
            await message.answer("🔐 Please complete verification first.")
            return

        settings = await con.fetch("SELECT type, points_required FROM withdraw_settings ORDER BY type::int ASC")

        kb_rows = []
        for s in settings:
            stock = await con.fetchval(
                "SELECT COUNT(*) FROM coupons WHERE type=$1 AND redeemed=FALSE",
                s["type"]
            )
            kb_rows.append([KeyboardButton(text=f"{s['type']} OFF ({stock})")])

    await message.answer("Select a coupon to withdraw:", reply_markup=ReplyKeyboardMarkup(keyboard=kb_rows, resize_keyboard=True))


@dp.message(lambda m: bool(re.match(r"^\d{3,4}\s+OFF\s*\(\d+\)$", (m.text or "").strip(), re.I)))
async def withdraw_selected(message: types.Message):
    user_id = message.from_user.id
    if not await user_is_joined_all(user_id):
        await message.answer("🚨 You must stay joined to all required channels.")
        return

    ctype = parse_coupon_type_from_text(message.text)
    if not ctype:
        return

    async with db.acquire() as con:
        user = await con.fetchrow("SELECT verified, points, username FROM users WHERE id=$1", user_id)
        if not user or not user["verified"]:
            await message.answer("🔐 Please verify first.")
            return

        required = await con.fetchval("SELECT points_required FROM withdraw_settings WHERE type=$1", ctype)
        if required is None:
            await message.answer("❌ Withdraw settings missing for this type.")
            return

        if user["points"] < required:
            await message.answer("❌ You don’t have enough referrals/points for this withdrawal.")
            return

        async with con.transaction():
            coupon = await con.fetchrow(
                """
                SELECT id, code FROM coupons
                WHERE type=$1 AND redeemed=FALSE
                ORDER BY id ASC
                FOR UPDATE SKIP LOCKED
                LIMIT 1
                """,
                ctype
            )
            if not coupon:
                await message.answer("❌ Out of stock.")
                return

            await con.execute(
                "UPDATE coupons SET redeemed=TRUE, redeemed_by=$1 WHERE id=$2",
                user_id, coupon["id"]
            )
            await con.execute(
                "UPDATE users SET points=points-$1 WHERE id=$2",
                required, user_id
            )
            await con.execute(
                "INSERT INTO redeem_logs (user_id, coupon_type, coupon_code) VALUES ($1,$2,$3)",
                user_id, ctype, coupon["code"]
            )

        uname = f"@{user['username']}" if user["username"] else str(user_id)
        await notify_admins(
            f"✅ <b>Withdraw Redeemed</b>\n"
            f"User: {uname}\n"
            f"Coupon: <b>{ctype} OFF</b>\n"
            f"Points Deducted: <b>{required}</b>\n"
            f"Code: <code>{coupon['code']}</code>"
        )

    await message.answer(
        f"✅ Redeemed Successfully!\n\n"
        f"🎟 Coupon: <b>{ctype} OFF</b>\n"
        f"🔑 Code: <code>{coupon['code']}</code>",
        reply_markup=main_user_keyboard()
    )


# =========================
# ADMIN FLOW
# =========================
@dp.message(F.text == "/admin")
async def admin_panel(message: types.Message):
    if not is_admin(message.from_user.id):
        return
    await message.answer("🛠 <b>Admin Panel</b>", reply_markup=admin_keyboard())


@dp.message(F.text == "➕ Add Coupon")
async def admin_add_coupon(message: types.Message, state: FSMContext):
    if not is_admin(message.from_user.id):
        return
    await state.set_state(AdminAddCoupon.choosing_type)
    await message.answer("Select coupon type to add:", reply_markup=coupon_type_buttons())


@dp.message(AdminAddCoupon.choosing_type)
async def admin_add_coupon_choose_type(message: types.Message, state: FSMContext):
    if not is_admin(message.from_user.id):
        return
    ctype = parse_coupon_type_from_text(message.text or "")
    if not ctype:
        await message.answer("❌ Choose a valid type using the buttons.")
        return
    await state.update_data(ctype=ctype)
    await state.set_state(AdminAddCoupon.waiting_codes)
    await message.answer(
        f"Send codes for <b>{ctype} OFF</b> (one per line).",
        reply_markup=ReplyKeyboardMarkup(keyboard=[[KeyboardButton(text="❌ Cancel")]], resize_keyboard=True)
    )


@dp.message(AdminAddCoupon.waiting_codes)
async def admin_add_coupon_codes(message: types.Message, state: FSMContext):
    if not is_admin(message.from_user.id):
        return
    if (message.text or "").strip() == "❌ Cancel":
        await state.clear()
        await message.answer("Cancelled.", reply_markup=admin_keyboard())
        return

    data = await state.get_data()
    ctype = data["ctype"]

    lines = [l.strip() for l in (message.text or "").splitlines()]
    codes = []
    for l in lines:
        if not l:
            continue
        l = re.sub(r"\s+", "", l)
        if 4 <= len(l) <= 64:
            codes.append(l)

    if not codes:
        await message.answer("❌ No valid codes found. Send again, one per line.")
        return

    inserted = 0
    skipped = 0
    async with db.acquire() as con:
        for code in codes:
            try:
                await con.execute("INSERT INTO coupons (type, code) VALUES ($1,$2)", ctype, code)
                inserted += 1
            except asyncpg.UniqueViolationError:
                skipped += 1

        stock = await con.fetchval("SELECT COUNT(*) FROM coupons WHERE type=$1 AND redeemed=FALSE", ctype)

    await state.clear()
    await message.answer(
        f"✅ Added <b>{inserted}</b> codes to <b>{ctype} OFF</b>.\n"
        f"Skipped duplicates: <b>{skipped}</b>\n"
        f"Current stock: <b>{stock}</b>",
        reply_markup=admin_keyboard()
    )


@dp.message(F.text == "📦 Stock")
async def admin_stock(message: types.Message):
    if not is_admin(message.from_user.id):
        return

    async with db.acquire() as con:
        rows = await con.fetch(
            """
            SELECT type,
                   SUM(CASE WHEN redeemed=FALSE THEN 1 ELSE 0 END) AS available,
                   SUM(CASE WHEN redeemed=TRUE THEN 1 ELSE 0 END) AS redeemed
            FROM coupons
            GROUP BY type
            ORDER BY type::int ASC
            """
        )

    if not rows:
        await message.answer("No coupons in database yet.")
        return

    text = "<b>📦 Stock</b>\n\n"
    for r in rows:
        text += f"• <b>{r['type']} OFF</b> → Available: <b>{r['available']}</b> | Redeemed: <b>{r['redeemed']}</b>\n"
    await message.answer(text)


@dp.message(F.text == "📜 Redeem Logs")
async def admin_redeem_logs(message: types.Message):
    if not is_admin(message.from_user.id):
        return

    async with db.acquire() as con:
        logs = await con.fetch(
            """
            SELECT user_id, coupon_type, coupon_code, created_at
            FROM redeem_logs
            WHERE coupon_type NOT IN ('REF_AWARD','REF_DEDUCT')
            ORDER BY created_at DESC
            LIMIT 10
            """
        )

    if not logs:
        await message.answer("No redeem logs yet.")
        return

    text = "<b>📜 Last 10 Redeems</b>\n\n"
    for l in logs:
        text += (
            f"• User: <code>{l['user_id']}</code> | "
            f"Type: <b>{l['coupon_type']} OFF</b> | "
            f"Code: <code>{l['coupon_code']}</code>\n"
        )
    await message.answer(text)


@dp.message(F.text == "⚙ Change Withdraw Points")
async def admin_change_points(message: types.Message, state: FSMContext):
    if not is_admin(message.from_user.id):
        return
    await state.set_state(AdminChangePoints.choosing_type)
    await message.answer("Select coupon type to change required points:", reply_markup=coupon_type_buttons())


@dp.message(AdminChangePoints.choosing_type)
async def admin_change_points_choose_type(message: types.Message, state: FSMContext):
    if not is_admin(message.from_user.id):
        return
    ctype = parse_coupon_type_from_text(message.text or "")
    if not ctype:
        await message.answer("❌ Choose a valid type using the buttons.")
        return
    await state.update_data(ctype=ctype)
    await state.set_state(AdminChangePoints.waiting_points)
    await message.answer(
        f"Send required points for <b>{ctype} OFF</b> (number only).",
        reply_markup=ReplyKeyboardMarkup(keyboard=[[KeyboardButton(text="❌ Cancel")]], resize_keyboard=True)
    )


@dp.message(AdminChangePoints.waiting_points)
async def admin_change_points_value(message: types.Message, state: FSMContext):
    if not is_admin(message.from_user.id):
        return
    if (message.text or "").strip() == "❌ Cancel":
        await state.clear()
        await message.answer("Cancelled.", reply_markup=admin_keyboard())
        return

    raw = (message.text or "").strip()
    if not raw.isdigit():
        await message.answer("❌ Send number only (example: 10).")
        return
    val = int(raw)
    if val < 0 or val > 10_000_000:
        await message.answer("❌ Invalid points.")
        return

    data = await state.get_data()
    ctype = data["ctype"]

    async with db.acquire() as con:
        await con.execute(
            """
            INSERT INTO withdraw_settings (type, points_required)
            VALUES ($1,$2)
            ON CONFLICT (type) DO UPDATE SET points_required=EXCLUDED.points_required
            """,
            ctype, val
        )

    await state.clear()
    await message.answer(f"✅ Updated <b>{ctype} OFF</b> required points to <b>{val}</b>.", reply_markup=admin_keyboard())


@dp.message(F.text == "➕ Add Channels")
async def admin_add_channels(message: types.Message, state: FSMContext):
    if not is_admin(message.from_user.id):
        return
    await state.set_state(AdminAddChannels.waiting_channels)
    await message.answer(
        "Send channel usernames one per line.\n"
        "Example:\n"
        "@ChannelOne\n"
        "ChannelTwo\n\n"
        "Cancel: ❌ Cancel",
        reply_markup=ReplyKeyboardMarkup(keyboard=[[KeyboardButton(text="❌ Cancel")]], resize_keyboard=True)
    )


@dp.message(AdminAddChannels.waiting_channels)
async def admin_add_channels_save(message: types.Message, state: FSMContext):
    if not is_admin(message.from_user.id):
        return
    if (message.text or "").strip() == "❌ Cancel":
        await state.clear()
        await message.answer("Cancelled.", reply_markup=admin_keyboard())
        return

    lines = [l.strip() for l in (message.text or "").splitlines()]
    chans = []
    for l in lines:
        u = norm_channel_username(l)
        if u:
            chans.append(u)

    if not chans:
        await message.answer("❌ No valid channel usernames found. Send again.")
        return

    added, skipped = 0, 0
    async with db.acquire() as con:
        for ch in chans:
            try:
                await con.execute("INSERT INTO channels (channel_username) VALUES ($1)", ch)
                added += 1
            except asyncpg.UniqueViolationError:
                skipped += 1

        total = await con.fetchval("SELECT COUNT(*) FROM channels")

    await state.clear()
    await message.answer(
        f"✅ Channels updated.\nAdded: <b>{added}</b> | Skipped: <b>{skipped}</b>\nTotal required: <b>{total}</b>",
        reply_markup=admin_keyboard()
    )


# =========================
# REMOVE CHANNEL (NEW)
# =========================
@dp.message(F.text == "➖ Remove Channel")
async def admin_remove_channel(message: types.Message):
    if not is_admin(message.from_user.id):
        return

    channels = await fetch_channels()
    if not channels:
        await message.answer("No channels added yet.")
        return

    rows = []
    for ch in channels[:80]:  # safety cap
        rows.append([InlineKeyboardButton(text=f"❌ Remove @{ch}", callback_data=f"rmch:{ch}")])

    await message.answer("Select a channel to remove:", reply_markup=InlineKeyboardMarkup(inline_keyboard=rows))


@dp.callback_query(F.data.startswith("rmch:"))
async def cb_remove_channel(call: types.CallbackQuery):
    if not is_admin(call.from_user.id):
        await call.answer("Not allowed", show_alert=True)
        return

    ch = call.data.split(":", 1)[1]
    async with db.acquire() as con:
        await con.execute("DELETE FROM channels WHERE channel_username=$1", ch)
        left = await con.fetchval("SELECT COUNT(*) FROM channels")

    await call.answer("Removed ✅", show_alert=False)
    try:
        await call.message.edit_text(f"✅ Removed @{ch}\nRemaining channels: <b>{left}</b>")
    except:
        pass


# =========================
# BACKGROUND: AUTO DEDUCT IF REFEREE LEAVES
# =========================
async def channel_audit_loop():
    while True:
        try:
            channels = await fetch_channels()
            if channels:
                async with db.acquire() as con:
                    users = await con.fetch(
                        "SELECT id, referred_by, active_in_channels FROM users WHERE verified=TRUE AND referred_by IS NOT NULL"
                    )

                for u in users:
                    uid = u["id"]
                    ref = u["referred_by"]
                    was_active = u["active_in_channels"]

                    joined = await user_is_joined_all(uid)

                    if was_active and not joined:
                        async with db.acquire() as con:
                            async with con.transaction():
                                await con.execute("UPDATE users SET active_in_channels=FALSE WHERE id=$1", uid)
                                await con.execute("UPDATE users SET points=GREATEST(points-1,0) WHERE id=$1", ref)
                                await con.execute(
                                    "INSERT INTO redeem_logs (user_id, coupon_type, coupon_code) VALUES ($1,'REF_DEDUCT',$2)",
                                    uid, str(ref)
                                )
                        await notify_admins(
                            f"⚠️ Referee left required channels.\n"
                            f"Referee: <code>{uid}</code>\nReferrer: <code>{ref}</code>\n"
                            f"Action: <b>1 point deducted</b>"
                        )

                    if (not was_active) and joined:
                        async with db.acquire() as con:
                            await con.execute("UPDATE users SET active_in_channels=TRUE WHERE id=$1", uid)

        except Exception:
            pass

        await asyncio.sleep(CHANNEL_AUDIT_SECONDS)


# =========================
# STARTUP
# =========================
@app.on_event("startup")
async def on_startup():
    global db
    db = await asyncpg.create_pool(DATABASE_URL, min_size=1, max_size=10)
    await bot.set_webhook(WEBHOOK_URL)
    asyncio.create_task(channel_audit_loop())

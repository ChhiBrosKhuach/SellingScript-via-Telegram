# 📦 Script Marketplace Telegram Bot (PHP)

A powerful **Telegram marketplace bot** built with PHP, supporting **digital product selling, Bakong payment verification, referrals, and admin controls**.

---

## 🚀 Features

- 💰 **Digital Product Marketplace**
  - Sell scripts, tools, or services directly via Telegram

- 🔐 **Secure Configuration**
  - Uses `.env` for sensitive data (tokens, API keys)

- 🏦 **Bakong Payment Integration**
  - Verify transactions using NBC Bakong API

- 🪙 **Crypto Support**
  - Accept TRX deposits

- 👥 **Referral System**
  - Users can invite others and earn rewards

- 🧠 **User State Management**
  - Tracks user actions and prevents abuse

- 🛠️ **Admin Commands**
  - Manage users, reset data, control bot behavior

- 📂 **JSON Database**
  - Lightweight storage using:
    - `users.json`
    - `Sell.json`
    - `Names.json`

- 🔒 **Security Enhancements**
  - Protected data directory (`.htaccess`)
  - Environment-based secrets

---

## 📁 Project Structure

```
📦 project/
 ┣ 📂 data/
 ┃ ┣ users.json
 ┃ ┣ Sell.json
 ┃ ┣ Names.json
 ┃ ┗ debug.log
 ┣ 📜 sell_fixed.php
 ┣ 📜 config.php
 ┣ 📜 .env
 ┗ 📜 README.md
```

---

## ⚙️ Installation

### 1. Clone the repository

```bash
git clone https://github.com/your-username/your-repo.git
cd your-repo
```

---

### 2. Setup `.env`

Create a `.env` file:

```env
TELEGRAM_BOT_TOKEN=your_bot_token
ADMIN_ID=your_telegram_id
CHANNEL=@your_channel
OTP_CHANNEL=@your_channel
WEBHOOK_URL=https://yourdomain.com/sell_fixed.php

BAKONG_API_TOKEN=your_bakong_token
BAKONG_ACCOUNT=your_account

TRX_ADDRESS=your_trx_wallet
```

⚠️ **Never commit `.env` to GitHub**

---

### 3. Configure Webhook

```
https://api.telegram.org/bot<YOUR_TOKEN>/setWebhook?url=https://yourdomain.com/sell_fixed.php
```

---

### 4. Permissions

Make sure your server can write to:

```
/data/
```

---

## 🧩 How It Works

### 👤 User Flow

1. User starts bot  
2. Data is initialized  
3. User selects product  
4. Payment is made (Bakong / TRX)  
5. Transaction is verified  
6. Product is delivered  

---

### 💾 Data Handling

- Users stored in `users.json`
- Products stored in `Sell.json`
- Names mapping in `Names.json`

---

### 🔄 State System

Each user has a `state`:

```json
{
  "state": "none"
}
```

---

## 🛡️ Security

- Uses `.env` for secrets  
- Blocks public access to `/data/`  
- Logs all requests (`debug.log`)  

---

## 🧪 Debugging

Logs are saved in:

```
/data/debug.log
```

---

## 🛠️ Admin Commands

- `/reset` → wipe all data  

---

## ⚠️ Notes

- Requires **PHP ≥ 7.2.5**
- Enable cURL
- Use HTTPS

---

## 📌 Future Improvements

- MySQL support  
- Admin dashboard  
- More payment methods  

---

## 👨‍💻 Author

Developed by you 😎

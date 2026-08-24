# AboutYou 👶📸

**AboutYou** 是一個專為紀錄與保存寶寶成長點滴而設計的個人相簿網站系統。
本專案採用 **PHP** 後端架構，並深度整合 **PWA (Progressive Web App)** 技術，讓你可以像管理一般網頁一樣靈活自訂，同時又能讓家人在手機上獲得如同原生 App 的流暢體驗。

---

## 🌟 核心特色 (Features)

* **專屬相簿**：輕鬆打造個人網站，安全儲存並展示寶寶的珍貴照片。
* **輕鬆管理**：操作介面直觀，用最簡單的方式進行檔案與資料管理。
* **靈活擴充**：架構設計靈含，基於 PHP 輕鬆進行二次開發。
* **📱 支援 PWA**：
  * 可直接將網頁**安裝至手機桌面**，生成獨立 App 圖示。
  * 支援全螢幕啟動，隱藏瀏覽器網址列，體驗更純粹。
  * 具備快取機制，大幅提升手機端載入速度。

---

## 📂 專案架構 (Project Structure)

```text
AboutYou/
├── Database/
│   └── mysql/          # 數據庫初始化與 SQL 腳本
├── aboutyou/           # 網頁應用程式核心原始碼 (PHP、HTML、CSS、JS、Manifest 等)
├── LICENSE             # Apache-2.0 開源授權協議
└── README.md           # 專案說明文件
```

---

## 🛠️ 技術棧 (Tech Stack)

* **Frontend**: HTML5, CSS3, JavaScript (整合 Service Worker & Manifest)
* **Backend**: PHP
* **Database**: MySQL

---

## 🚀 快速開始 (Getting Started)

### 1. 複製專案 (Clone Repository)
```bash
git clone https://github.com
cd AboutYou
```

### 2. 資料庫設定 (Database Setup)
* 進入 `Database/mysql/` 資料夾。
* 將內部的 SQL 腳本匯入至你的 MySQL 資料庫中。
* SQL創建user:
```text
SET NAMES utf8mb4;

INSERT INTO `tbl_user` (`id`, `username`, `nickname`, `icon_url`, `relationship`, `password`, `created_at`, `approval`, `aboutyou_default_capsule`) VALUES
(1,	'root',	'Nick.Name',	'generate after upload avatar image',	NULL,	'123456',	'2026-08-24 00:00:00',	'Y',	1);
```

### 3. 環境配置與啟動 (Deployment)
* 將 `aboutyou/` 資料夾內的完整原始碼部署至你的 PHP 伺服器環境（如 Apache、Nginx，或使用 XAMPP / Laragon 進行本地測試）。
* */apache2/php.ini 的部份配置, 有需要自行修改:
  * max_execution_time = 150
  * max_input_time = 150
  * memory_limit = 512M
  * post_max_size = 300M
  * upload_max_filesize = 300M
  * max_file_uploads = 50
* 確保伺服器已開啟 MySQL 擴充功能（`pdo_mysql` 或 `mysqli`）。
* 設定資料庫連接設定檔:   Config.php
* 網站架構:
```text
  AboutYou
   ├── *.php
   ├── manifest.json
   ├── images
         ├── aboutyou.png
         └── default_avatar.png
   └── uploads
         ├── avatars
         ├── capsule_profile
         └── memories                <<上傳的圖片所在, 請做備份.
```
* 預設用戶頭像: default_avatar.png
* PWA 頭像: **需要自行叉圖,命名, 添加** (android 的圖像按需要參改 manifest.json)
  - Iphone: apple-touch-icon.png 
  - Android: android_144x144.png; android_180x180.png; android_192x192.png

---

### 4. 📲 PWA 行動端安裝教學 (How to Install App)

由於本網站支援 PWA 技術，你與家人無需透過 App Store 或 Google Play，即可一鍵安裝至手機：

#### iOS (iPhone / iPad)
1. 使用 **Safari 瀏覽器** 開啟本網站。
2. 點擊瀏覽器底部的 **「分享」** 按鈕（向上箭頭圖示）。
3. 往下滑動並選擇 **「加入主畫面」**。

#### Android (安卓手機)
1. 使用 **Chrome 瀏覽器** 開啟本網站。
2. 系統會自動彈出「將 AboutYou 新增至主畫面」提示；若無彈出，請點擊右上角 **「┇」選單**。
3. 點擊 **「安裝應用程式」** 或 **「加到主畫面」**。

---

## 使用建議
1. SQL創建user
2. 使用/ay_login.pgp登錄
3. 點擊[📱 裝置] 新增移動設備. 減少頻繁的使用登錄信息(account+password)登錄
4. PWA 安裝
5. 開始使用
  * 點擊[✏️ 個人資料] 編輯: Nickname + 頭像上傳, 保存後返回
  * 點擊[＋ 新增] 創建Capsule, 輸入寶寶相關資料, 以及頭像, 創建
  * (optional) 點擊[設為預設]讓寶寶作為常駐, 進入即看
6. 第一次
  * 在"寫下這段回憶..."填寫回憶
  * 點擊"點這裡選擇照片或影片" 上傳框選擇需要的行為, 照片或影片
  * 查看/勾選"可觀看的使用者", 為部份寶寶的私密照片不被四大長老看到
  * 點擊"📤 發佈", 記錄寶寶的第一次

## 📄 開源協議 (License)

本專案採用 **[Apache-2.0 License](LICENSE)** 開源協議。你可以自由地使用、修改和分發本專案。

// sw.js - 最簡 Service Worker
const CACHE_NAME = 'aboutyou-v1';

// 安裝時跳過等待
self.addEventListener('install', event => {
    self.skipWaiting();
});

// 啟用時立刻接管網頁
self.addEventListener('activate', event => {
    event.waitUntil(self.clients.claim());
});

// 必須監聽 fetch 事件，PWA 安裝提示才能觸發
self.addEventListener('fetch', event => {
    // 這裡可以維持空白，或做最簡單的網絡請求通過
    event.respondWith(fetch(event.request));
});


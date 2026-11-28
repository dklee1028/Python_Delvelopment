/* MRS Service Worker — 화면 파일만 캐시(오프라인에서도 앱 실행), api 는 항상 네트워크 */
const CACHE = "mrs-shell-v14";
const SHELL = ["./", "assets/app.css", "assets/app.js", "assets/icons/mrs-192.png", "assets/icons/mrs-512.png",
  "assets/img/logo_color.png", "manifest.webmanifest"];

self.addEventListener("install", (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).catch(() => {}).then(() => self.skipWaiting()));
});
self.addEventListener("activate", (e) => {
  e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});
self.addEventListener("fetch", (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== "GET" || url.origin !== location.origin || url.pathname.includes("/api/") || url.pathname.endsWith("install.php") || url.pathname.includes("/vendor/")) return; // 7-Zip(WASM) 등 큰 파일은 캐시하지 않음
  e.respondWith(
    fetch(e.request).then((res) => {
      if (res.ok && res.type === "basic") { const copy = res.clone(); caches.open(CACHE).then((c) => c.put(e.request, copy)); }
      return res;
    }).catch(() => caches.match(e.request, { ignoreSearch: true }))
  );
});

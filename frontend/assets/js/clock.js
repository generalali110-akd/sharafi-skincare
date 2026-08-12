// ===== ساعت و تاریخ زنده به وقت تهران + شمارش‌گر تخفیف لحظه‌ای روزانه =====
// ایده: علاوه بر نمایش ساعت/تاریخ، یک شمارش‌گر معکوس «پایان جشن تخفیف امروز»
// کنار آن نشان داده می‌شود تا حس فوریت خرید را برای مشتری ایجاد کند.

function tehranNow() {
  // بازنمایی زمان فعلی بر اساس منطقه زمانی تهران
  return new Date(new Date().toLocaleString("en-US", { timeZone: "Asia/Tehran" }));
}

function formatJalaliDate(date) {
  return new Intl.DateTimeFormat("fa-IR", {
    timeZone: "Asia/Tehran",
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
  }).format(date);
}

function formatTehranTime(date) {
  return new Intl.DateTimeFormat("fa-IR", {
    timeZone: "Asia/Tehran",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
  }).format(date);
}

function tickClock() {
  const now = new Date();
  const dateStr = formatJalaliDate(now);
  const timeStr = formatTehranTime(now);

  document.querySelectorAll(".js-live-date").forEach((el) => (el.textContent = dateStr));
  document.querySelectorAll(".js-live-time").forEach((el) => (el.textContent = timeStr));

  // ---- شمارش‌گر پایان جشن تخفیف امروز (تا ساعت ۲۳:۵۹:۵۹ به وقت تهران) ----
  const tNow = tehranNow();
  const endOfDay = new Date(tNow);
  endOfDay.setHours(23, 59, 59, 999);
  let diff = endOfDay - tNow;
  if (diff < 0) diff = 0;

  const h = String(Math.floor(diff / 3600000)).padStart(2, "0");
  const m = String(Math.floor((diff % 3600000) / 60000)).padStart(2, "0");
  const s = String(Math.floor((diff % 60000) / 1000)).padStart(2, "0");
  document.querySelectorAll(".js-flash-countdown").forEach((el) => {
    el.textContent = `${h}:${m}:${s}`;
  });
}

document.addEventListener("DOMContentLoaded", () => {
  tickClock();
  setInterval(tickClock, 1000);
});

// ===== ساعت و تاریخ زنده به وقت تهران =====

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

  document.querySelectorAll(".js-live-date").forEach((el) => {
    el.textContent = dateStr;
  });

  document.querySelectorAll(".js-live-time").forEach((el) => {
    el.textContent = timeStr;
  });
}

document.addEventListener("DOMContentLoaded", () => {
  tickClock();
  setInterval(tickClock, 1000);
});

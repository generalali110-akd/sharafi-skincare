// ===== پنل مدیریت فروشگاه شرفی — منطق نمونه (دموی UI/UX) =====
// در نسخه نهایی، داده‌ها از دیتابیس ووکامرس/وردپرس از طریق REST API خوانده می‌شوند.

function fmtT(n) { return n.toLocaleString("fa-IR") + " تومان"; }

/* ---------- داده نمونه فروش برای نمودار ---------- */
const SALES_DATA = {
  daily: {
    labels: ["شنبه", "یک‌شنبه", "دوشنبه", "سه‌شنبه", "چهارشنبه", "پنج‌شنبه", "جمعه"],
    values: [2100000, 2850000, 1900000, 3200000, 2700000, 4100000, 3650000],
  },
  weekly: {
    labels: ["هفته ۱", "هفته ۲", "هفته ۳", "هفته ۴", "هفته ۵", "هفته ۶", "هفته ۷", "هفته ۸"],
    values: [14500000, 16200000, 13800000, 19100000, 17600000, 21300000, 18900000, 22700000],
  },
  monthly: {
    labels: ["فروردین","اردیبهشت","خرداد","تیر","مرداد","شهریور","مهر","آبان","آذر","دی","بهمن","اسفند"],
    values: [58000000,62500000,71000000,69500000,75200000,81000000,77400000,84600000,90100000,88300000,95200000,102400000],
  },
};

let salesChart;
function renderSalesChart(period) {
  const ctx = document.getElementById("salesChart");
  if (!ctx) return;
  const data = SALES_DATA[period] || SALES_DATA.daily;
  if (salesChart) salesChart.destroy();
  salesChart = new Chart(ctx, {
    type: "bar",
    data: {
      labels: data.labels,
      datasets: [{
        label: "فروش (تومان)",
        data: data.values,
        backgroundColor: "#e98aa3",
        borderRadius: 8,
        maxBarThickness: 40,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        y: { ticks: { callback: (v) => (v / 1000000).toFixed(0) + "M" }, grid: { color: "#f7eaee" } },
        x: { grid: { display: false } },
      },
    },
  });
}

function initPeriodTabs() {
  const tabs = document.querySelectorAll(".period-tabs button");
  const customRange = document.querySelector(".custom-range");
  if (!tabs.length) return;
  tabs.forEach((btn) => {
    btn.addEventListener("click", () => {
      tabs.forEach((b) => b.classList.remove("active"));
      btn.classList.add("active");
      const period = btn.dataset.period;
      if (period === "custom") {
        customRange.classList.add("show");
      } else {
        customRange.classList.remove("show");
        renderSalesChart(period);
      }
    });
  });
  const applyBtn = document.querySelector(".js-apply-range");
  if (applyBtn) {
    applyBtn.addEventListener("click", () => {
      // دموی بازه دلخواه: همان داده ماهانه را به‌عنوان نمونه نمایش می‌دهد
      renderSalesChart("monthly");
    });
  }
}

/* ---------- مودال‌های عمومی ---------- */
function openModal(id) { document.getElementById(id).classList.add("show"); }
function closeModal(id) { document.getElementById(id).classList.remove("show"); }

/* ---------- مدیریت محصولات ---------- */
function initProductModal() {
  const addBtn = document.querySelector(".js-add-product");
  const form = document.querySelector(".js-product-form");
  if (!addBtn) return;

  addBtn.addEventListener("click", () => {
    form.reset();
    document.querySelector(".js-modal-title").textContent = "افزودن محصول جدید";
    openModal("productModal");
  });

  document.querySelectorAll(".js-edit-product").forEach((btn) => {
    btn.addEventListener("click", () => {
      const row = btn.closest("tr");
      document.querySelector(".js-modal-title").textContent = "ویرایش محصول";
      form.querySelector('[name="name"]').value = row.dataset.name;
      form.querySelector('[name="category"]').value = row.dataset.category;
      form.querySelector('[name="price"]').value = row.dataset.price;
      form.querySelector('[name="stock"]').value = row.dataset.stock;
      openModal("productModal");
    });
  });

  document.querySelectorAll(".js-delete-product").forEach((btn) => {
    btn.addEventListener("click", () => {
      if (confirm("از حذف این محصول مطمئن هستید؟")) {
        btn.closest("tr").remove();
      }
    });
  });

  form.addEventListener("submit", (e) => {
    e.preventDefault();
    closeModal("productModal");
    toastAdmin("محصول با موفقیت ذخیره شد (نمونه — بدون اتصال به دیتابیس واقعی)");
  });
}

/* ---------- جزئیات مشتری ---------- */
const USER_HISTORY = {
  u1: { orders: [
    { id: "SH-1042", date: "۱۴۰۵/۰۳/۱۲", amount: 685000, status: "تکمیل‌شده" },
    { id: "SH-1029", date: "۱۴۰۵/۰۲/۲۰", amount: 420000, status: "تکمیل‌شده" },
    { id: "SH-0991", date: "۱۴۰۵/۰۱/۰۵", amount: 310000, status: "مرجوع‌شده" },
  ]},
  u2: { orders: [
    { id: "SH-1055", date: "۱۴۰۵/۰۳/۱۸", amount: 1250000, status: "در حال ارسال" },
    { id: "SH-1003", date: "۱۴۰۵/۰۱/۲۲", amount: 540000, status: "تکمیل‌شده" },
  ]},
};

function initUserDrawer() {
  document.querySelectorAll(".js-view-user").forEach((btn) => {
    btn.addEventListener("click", () => {
      const row = btn.closest("tr");
      const uid = row.dataset.uid;
      document.querySelector(".js-drawer-name").textContent = row.dataset.name;
      document.querySelector(".js-drawer-email").textContent = row.dataset.email;
      document.querySelector(".js-drawer-orders").textContent = row.dataset.orders;
      document.querySelector(".js-drawer-total").textContent = fmtT(Number(row.dataset.total));
      document.querySelector(".js-drawer-joined").textContent = row.dataset.joined;

      const list = USER_HISTORY[uid]?.orders || [];
      document.querySelector(".js-drawer-history").innerHTML = list.map(o => `
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid #f7eaee;font-size:13px;">
          <div><b>${o.id}</b><br><span style="color:var(--text-light);font-size:11.5px;">${o.date}</span></div>
          <div style="text-align:left;"><b>${fmtT(o.amount)}</b><br><span style="font-size:11.5px;color:var(--text-light);">${o.status}</span></div>
        </div>
      `).join("") || `<p style="color:var(--text-light);font-size:13px;">سفارشی ثبت نشده</p>`;

      document.querySelector(".js-user-drawer-overlay").classList.add("show");
    });
  });
  document.querySelectorAll(".js-close-drawer").forEach(el =>
    el.addEventListener("click", () => document.querySelector(".js-user-drawer-overlay").classList.remove("show"))
  );
}

/* ---------- جستجو/فیلتر جدول ساده ---------- */
function initTableSearch() {
  document.querySelectorAll(".js-table-search").forEach((input) => {
    input.addEventListener("input", () => {
      const table = document.querySelector(input.dataset.target);
      const q = input.value.trim().toLowerCase();
      table.querySelectorAll("tbody tr").forEach((row) => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? "" : "none";
      });
    });
  });
}

/* ---------- toast ادمین ---------- */
function toastAdmin(msg) {
  let box = document.querySelector(".js-admin-toast");
  if (!box) {
    box = document.createElement("div");
    box.className = "js-admin-toast";
    box.style.cssText = "position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#4a3a3c;color:#fff;padding:12px 24px;border-radius:999px;font-size:14px;z-index:999;box-shadow:0 8px 20px rgba(0,0,0,.2);transition:opacity .3s;";
    document.body.appendChild(box);
  }
  box.textContent = msg;
  box.style.opacity = "1";
  clearTimeout(box._t);
  box._t = setTimeout(() => (box.style.opacity = "0"), 2200);
}

/* ---------- زنگ اعلان داخل پنل (جایگزین پیامک هشدار) ---------- */
const LOW_STOCK_ALERTS = [
  { icon: "⚠️", title: "ماسک شب ترمیم‌کننده", desc: "فقط ۳ عدد در انبار باقی مانده" },
  { icon: "⚠️", title: "ژل شوینده ملایم صورت", desc: "فقط ۲ عدد در انبار باقی مانده" },
  { icon: "📦", title: "سفارش جدید GB-1058", desc: "۲ دقیقه پیش ثبت شد" },
];

function initNotifBell() {
  const bell = document.querySelector(".js-notif-bell");
  const dropdown = document.querySelector(".js-notif-dropdown");
  if (!bell || !dropdown) return;

  dropdown.innerHTML =
    `<div class="head">اعلان‌های پنل</div>` +
    LOW_STOCK_ALERTS.map(a => `
      <div class="notif-item">
        <span class="ic">${a.icon}</span>
        <div><div class="t">${a.title}</div><div class="d">${a.desc}</div></div>
      </div>`).join("");

  bell.addEventListener("click", (e) => {
    e.stopPropagation();
    dropdown.classList.toggle("show");
  });
  document.addEventListener("click", () => dropdown.classList.remove("show"));
}

function initSidebarToggle() {
  const toggle = document.querySelector(".js-sidebar-toggle");
  const sidebar = document.querySelector(".admin-sidebar");
  if (toggle && sidebar) toggle.addEventListener("click", () => sidebar.classList.toggle("show"));
}

document.addEventListener("DOMContentLoaded", () => {
  renderSalesChart("daily");
  initPeriodTabs();
  initProductModal();
  initUserDrawer();
  initTableSearch();
  initSidebarToggle();
  initNotifBell();

  document.querySelectorAll(".js-modal-close").forEach(el => {
    el.addEventListener("click", () => el.closest(".modal-overlay").classList.remove("show"));
  });
});

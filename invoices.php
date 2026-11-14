<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

// ---- Filters ----
$status_filter = $_GET['status'] ?? '';
if (!in_array($status_filter, ['Paid','Partial','Unpaid',''])) $status_filter = '';
$q           = trim($_GET['q'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 15;
$offset      = ($page - 1) * $per_page;

// ---- Build WHERE safely ----
$conds = [];
$params = [];
if ($status_filter) { $conds[] = 'i.status = ?';        $params[] = $status_filter; }
if ($q !== '')      { $conds[] = '(i.invoice_no LIKE ? OR c.name LIKE ? OR v.reg_no LIKE ?)';
                      $like = "%$q%";                  $params = array_merge($params, [$like, $like, $like]); }
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

// ---- Rows (paginated) ----
$stmt = $pdo->prepare("SELECT i.*, v.reg_no, c.name AS customer,
  (i.grand_total - i.paid_amount) AS balance
  FROM invoices i JOIN vehicles v ON v.id=i.vehicle_id JOIN customers c ON c.id=v.customer_id
  $where ORDER BY i.id DESC LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ---- Grand totals (respect filter/search) ----
$sum = $pdo->prepare("SELECT COALESCE(SUM(i.grand_total),0) AS billed, COALESCE(SUM(i.paid_amount),0) AS paid
  FROM invoices i JOIN vehicles v ON v.id=i.vehicle_id JOIN customers c ON c.id=v.customer_id $where");
$sum->execute($params);
$sum = $sum->fetch();

// ---- Balance due by status (always global) ----
$due = $pdo->query("SELECT
  COALESCE(SUM(CASE WHEN status='Partial' THEN grand_total - paid_amount END),0) AS partial,
  COALESCE(SUM(CASE WHEN status='Unpaid'  THEN grand_total - paid_amount END),0) AS unpaid
  FROM invoices")->fetch();

// ---- Status counts for the filter tabs (global, ignoring status filter but respecting search) ----
$cntConds = [];
$cntParams = [];
if ($q !== '') { $cntConds[] = '(i.invoice_no LIKE ? OR c.name LIKE ? OR v.reg_no LIKE ?)'; $cntParams = [$like, $like, $like]; }
$cntWhere = $cntConds ? 'WHERE ' . implode(' AND ', $cntConds) : '';
$cnts = $pdo->prepare("SELECT status, COUNT(*) c FROM invoices i JOIN vehicles v ON v.id=i.vehicle_id JOIN customers c ON c.id=v.customer_id $cntWhere GROUP BY status");
$cnts->execute($cntParams);
$status_counts = ['Paid'=>0, 'Partial'=>0, 'Unpaid'=>0];
foreach ($cnts as $sc) $status_counts[$sc['status']] = (int)$sc['c'];

// ---- Pagination count ----
$cnt = $pdo->prepare("SELECT COUNT(*) FROM invoices i JOIN vehicles v ON v.id=i.vehicle_id JOIN customers c ON c.id=v.customer_id $where");
$cnt->execute($params);
$total_rows  = (int)$cnt->fetchColumn();
$total_pages = max(1, ceil($total_rows / $per_page));

// ---- CSV export ----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $all = $pdo->prepare("SELECT i.invoice_no, i.invoice_date, c.name AS customer, v.reg_no,
      i.grand_total, i.paid_amount, (i.grand_total - i.paid_amount) AS balance, i.status
      FROM invoices i JOIN vehicles v ON v.id=i.vehicle_id JOIN customers c ON c.id=v.customer_id
      $where ORDER BY i.id DESC");
    $all->execute($params);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="invoices.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Invoice #','Date','Customer','Vehicle','Total','Paid','Balance','Status']);
    foreach ($all as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

$collect_pct = $sum['billed'] > 0 ? round($sum['paid'] / $sum['billed'] * 100) : 0;
page_header('Invoice Management');
?>
<style>
  .stat-card { transition: transform .15s, box-shadow .15s; }
  .stat-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,.08); }
  .status-tab { transition: background .15s, color .15s; }
  .status-tab.active-paid    { background: #059669; color: #fff; }
  .status-tab.active-partial { background: #d97706; color: #fff; }
  .status-tab.active-unpaid  { background: #dc2626; color: #fff; }
  .status-tab:not(.active-paid):not(.active-partial):not(.active-unpaid) { background: #fff; color: #374151; }
  .status-tab:not(.active-paid):not(.active-partial):not(.active-unpaid):hover { background: #f3f4f6; }
  .inv-row { cursor: pointer; transition: background .12s; }
  .row-actions a { opacity: .35; transition: opacity .12s, transform .12s; }
  tr:hover .row-actions a { opacity: 1; }
  .row-actions a:hover { transform: scale(1.2); }
  .mini-bar { height: 4px; border-radius: 9999px; background: #e5e7eb; overflow: hidden; width: 90px; margin-left: auto; }
  .mini-bar > div { height: 100%; border-radius: 9999px; }
  kbd.k { border: 1px solid #d1d5db; border-bottom-width: 2px; border-radius: 4px; padding: 0 4px; background: #f9fafb; font-size: 10px; }
  #searchInput:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.15); outline: none; }
</style>

<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
  <div>
    <h1 class="text-lg font-bold text-gray-800">Invoices</h1>
    <p class="text-xs text-gray-400"><?= $total_rows ?> invoice<?= $total_rows === 1 ? '' : 's' ?><?= $status_filter ? " · $status_filter" : '' ?><?= $q !== '' ? " · matching “" . e($q) . "”" : '' ?></p>
  </div>
  <div class="flex gap-2">
    <a href="invoice_form.php" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition" title="New invoice (N)"><i class="fa-solid fa-plus mr-1"></i> New Invoice</a>
    <a href="invoices.php?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>" class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition" title="Download CSV"><i class="fa-solid fa-download mr-1"></i> Export CSV</a>
  </div>
</div>

<!-- Search bar -->
<form method="get" class="mb-4" id="searchForm">
  <?php if ($status_filter): ?><input type="hidden" name="status" value="<?= e($status_filter) ?>"><?php endif; ?>
  <div class="relative max-w-xl">
    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
    <input id="searchInput" type="text" name="q" value="<?= e($q) ?>" placeholder="Search by invoice #, customer name or vehicle reg no…  ( / to focus )"
           class="w-full border rounded-lg pl-9 pr-16 py-2.5 text-sm bg-white transition" autocomplete="off">
    <?php if ($q !== ''): ?>
    <a href="invoices.php<?= $status_filter ? '?status=' . urlencode($status_filter) : '' ?>" class="absolute right-9 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600" title="Clear search"><i class="fa-solid fa-xmark"></i></a>
    <?php endif; ?>
    <button class="absolute right-1.5 top-1/2 -translate-y-1/2 bg-gray-700 hover:bg-gray-800 text-white text-xs font-medium px-3 py-1.5 rounded-md transition">Search</button>
  </div>
</form>

<!-- Status filter tabs with live counts -->
<div class="flex flex-wrap gap-2 mb-4">
  <a href="invoices.php<?= $q ? '?q=' . urlencode($q) : '' ?>"
     class="status-tab text-sm font-medium px-4 py-2 rounded-lg border border-gray-200 flex items-center gap-2 <?= $status_filter === '' ? 'active-all !bg-gray-800 !text-white !border-gray-800' : '' ?>">
    All <span class="text-xs bg-gray-100 text-gray-600 rounded-full px-2 py-0.5"><?= array_sum($status_counts) ?></span>
  </a>
  <?php
    $tabColors = ['Paid'=>'active-paid', 'Partial'=>'active-partial', 'Unpaid'=>'active-unpaid'];
    $tabBgs    = ['Paid'=>'#ecfdf5', 'Partial'=>'#fffbeb', 'Unpaid'=>'#fef2f2'];
    foreach (['Paid','Partial','Unpaid'] as $s):
      $active = $status_filter === $s;
      $qs = '?' . http_build_query(array_merge($q !== '' ? ['q'=>$q] : [], ['status'=>$s]));
  ?>
  <a href="invoices.php<?= $qs ?>"
     class="status-tab text-sm font-medium px-4 py-2 rounded-lg border border-gray-200 flex items-center gap-2 <?= $active ? $tabColors[$s] : '' ?>"
     <?= $active ? '' : 'style="background:' . $tabBgs[$s] . '"' ?>>
    <?= $s ?> <span class="text-xs rounded-full px-2 py-0.5 <?= $active ? 'bg-white/25' : 'bg-white/70 text-gray-600' ?>"><?= $status_counts[$s] ?></span>
  </a>
  <?php endforeach; ?>
</div>

<!-- Summary cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
  <div class="stat-card bg-white rounded-2xl shadow p-4">
    <div class="flex items-center justify-between">
      <div class="text-gray-400 text-xs">Total Billed<?= $status_filter || $q ? '*' : '' ?></div>
      <i class="fa-solid fa-file-invoice text-blue-100 text-lg"></i>
    </div>
    <div class="text-xl font-bold text-gray-800"><?= money($sum['billed']) ?></div>
  </div>
  <div class="stat-card bg-white rounded-2xl shadow p-4">
    <div class="flex items-center justify-between">
      <div class="text-gray-400 text-xs">Collected<?= $status_filter || $q ? '*' : '' ?></div>
      <i class="fa-solid fa-sack-dollar text-emerald-100 text-lg"></i>
    </div>
    <div class="text-xl font-bold text-green-600"><?= money($sum['paid']) ?></div>
    <div class="mt-1.5 w-full bg-gray-100 rounded-full h-1.5"><div class="bg-emerald-500 h-1.5 rounded-full transition-all duration-500" style="width: <?= $collect_pct ?>%"></div></div>
    <div class="text-[11px] text-gray-400 mt-1"><?= $collect_pct ?>% collection rate</div>
  </div>
  <div class="stat-card bg-white rounded-2xl shadow p-4">
    <div class="flex items-center justify-between">
      <div class="text-gray-400 text-xs">Balance Due<?= $status_filter || $q ? '*' : '' ?></div>
      <i class="fa-solid fa-hourglass-half text-red-100 text-lg"></i>
    </div>
    <div class="text-xl font-bold text-red-600"><?= money($sum['billed'] - $sum['paid']) ?></div>
    <?php if ($status_filter || $q): ?><div class="text-[11px] text-gray-400 mt-1">* reflects current filter</div><?php endif; ?>
  </div>
  <div class="stat-card bg-white rounded-2xl shadow p-4 flex flex-col justify-center gap-1.5">
    <div class="flex items-center gap-2 text-sm">
      <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
      <span class="text-gray-600 flex-1">Partial</span>
      <strong class="text-amber-700"><?= money($due['partial']) ?></strong>
    </div>
    <div class="flex items-center gap-2 text-sm">
      <span class="w-2.5 h-2.5 rounded-full bg-red-500"></span>
      <span class="text-gray-600 flex-1">Unpaid</span>
      <strong class="text-red-700"><?= money($due['unpaid']) ?></strong>
    </div>
  </div>
</div>

<div class="bg-white rounded-2xl shadow overflow-x-auto">
  <table class="w-full text-sm">
    <thead><tr class="text-left text-gray-500 border-b bg-gray-50">
      <th class="py-2.5 pl-4">Invoice #</th><th>Date</th><th>Customer</th><th>Vehicle</th><th class="text-right">Total</th><th class="text-right">Paid</th><th class="text-right pr-4">Balance</th><th>Status</th><th class="text-right pr-4">Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $pct = $r['grand_total'] > 0 ? min(100, round($r['paid_amount'] / $r['grand_total'] * 100)) : 0;
      $barColor = $pct >= 100 ? '#10b981' : ($pct > 0 ? '#f59e0b' : '#e5e7eb');
    ?>
      <tr class="border-b inv-row hover:bg-blue-50/50" onclick="window.location='invoice_print.php?id=<?= (int)$r['id'] ?>'" title="Open invoice">
        <td class="py-2.5 pl-4 font-semibold text-blue-700"><?= e($r['invoice_no']) ?></td>
        <td class="text-gray-600"><?= e(date('d M Y', strtotime($r['invoice_date']))) ?></td>
        <td><?= e($r['customer']) ?></td>
        <td><span class="bg-gray-100 text-gray-600 rounded px-1.5 py-0.5 text-xs font-mono"><?= e($r['reg_no']) ?></span></td>
        <td class="text-right font-medium"><?= money($r['grand_total']) ?></td>
        <td class="text-right text-green-600"><?= money($r['paid_amount']) ?></td>
        <td class="text-right pr-4">
          <div class="flex flex-col items-end gap-1">
            <span class="<?= $r['balance'] > 0 ? 'text-red-600 font-medium' : 'text-gray-400' ?>"><?= money($r['balance']) ?></span>
            <span class="mini-bar"><div style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div></span>
          </div>
        </td>
        <td><?= invoice_status_badge($r['status']) ?></td>
        <td class="text-right pr-4 row-actions space-x-3 whitespace-nowrap" onclick="event.stopPropagation()">
          <a href="invoice_print.php?id=<?= (int)$r['id'] ?>" class="text-gray-500 hover:text-gray-800" title="View / Print"><i class="fa-solid fa-print"></i></a>
          <?php if ($r['status'] !== 'Paid'): ?>
          <a href="invoice_pay.php?id=<?= (int)$r['id'] ?>" class="text-emerald-600 hover:text-emerald-800" title="Record payment"><i class="fa-solid fa-money-bill"></i></a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (!$rows): ?>
  <div class="py-14 text-center">
    <i class="fa-solid fa-folder-open text-4xl text-gray-200 mb-3"></i>
    <p class="text-gray-400 text-sm mb-4">No invoices found<?= $q ? ' for “' . e($q) . '”' : '' ?>.</p>
    <a href="invoice_form.php" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg"><i class="fa-solid fa-plus mr-1"></i> Create your first invoice</a>
  </div>
  <?php endif; ?>

  <?php if ($total_pages > 1):
    $qs = fn($p) => '?' . http_build_query(array_merge($_GET, ['page' => $p])); ?>
  <div class="flex items-center justify-between mt-4 px-4 pb-4 text-sm text-gray-500">
    <span>Page <?= $page ?> of <?= $total_pages ?></span>
    <div class="flex gap-1 items-center">
      <?php if ($page > 1): ?><a href="<?= $qs($page - 1) ?>" class="px-2.5 py-1.5 rounded-lg bg-gray-100 hover:bg-gray-200" title="Previous"><i class="fa-solid fa-chevron-left"></i></a><?php endif; ?>
      <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
      <a href="<?= $qs($p) ?>" class="px-3 py-1.5 rounded-lg <?= $p === $page ? 'bg-blue-600 text-white font-medium' : 'bg-gray-100 hover:bg-gray-200' ?>"><?= $p ?></a>
      <?php endfor; ?>
      <?php if ($page < $total_pages): ?><a href="<?= $qs($page + 1) ?>" class="px-2.5 py-1.5 rounded-lg bg-gray-100 hover:bg-gray-200" title="Next"><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
/* "/" focuses search, "n" opens new invoice — unless typing in a field */
document.addEventListener('keydown', function (e) {
  if (e.target.matches('input, select, textarea')) return;
  if (e.key === '/') { e.preventDefault(); document.getElementById('searchInput').focus(); }
  if (e.key === 'n') { e.preventDefault(); window.location = 'invoice_form.php'; }
});

/* Debounced auto-search while typing */
let t;
const input = document.getElementById('searchInput');
input.addEventListener('input', function () {
  clearTimeout(t);
  t = setTimeout(() => document.getElementById('searchForm').submit(), 600);
});
</script>
<?php page_footer(); ?>
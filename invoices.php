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

// ---- Pagination count ----
$cnt = $pdo->prepare("SELECT COUNT(*) FROM invoices i JOIN vehicles v ON v.id=i.vehicle_id JOIN customers c ON c.id=v.customer_id $where");
$cnt->execute($params);
$total_pages = max(1, ceil($cnt->fetchColumn() / $per_page));

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

page_header('Invoice Management');
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
  <div class="flex flex-wrap gap-2 items-center">
    <a href="invoice_form.php" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg"><i class="fa-solid fa-plus mr-1"></i> New Invoice</a>
    <form method="get" class="flex gap-2 items-center">
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search invoice, customer, reg no..." class="border rounded-lg px-3 py-2 text-sm bg-white w-64">
      <select name="status" class="border rounded-lg px-3 py-2 text-sm bg-white" onchange="this.form.submit()">
        <option value="">All statuses</option>
        <?php foreach (['Paid','Partial','Unpaid'] as $s): ?><option <?= $status_filter === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
      </select>
      <button class="bg-gray-700 hover:bg-gray-800 text-white text-sm font-medium px-4 py-2 rounded-lg"><i class="fa-solid fa-magnifying-glass mr-1"></i>Search</button>
      <?php if ($status_filter || $q !== ''): ?><a href="invoices.php" class="text-sm text-gray-500 hover:underline">Reset</a><?php endif; ?>
    </form>
  </div>
  <a href="invoices.php?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>" class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium px-4 py-2 rounded-lg"><i class="fa-solid fa-download mr-1"></i>Export CSV</a>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
  <div class="bg-white rounded-2xl shadow p-4"><div class="text-gray-400 text-xs">Total Billed<?= $status_filter || $q ? ' (filtered)' : '' ?></div><div class="text-xl font-bold"><?= money($sum['billed']) ?></div></div>
  <div class="bg-white rounded-2xl shadow p-4"><div class="text-gray-400 text-xs">Total Collected<?= $status_filter || $q ? ' (filtered)' : '' ?></div><div class="text-xl font-bold text-green-600"><?= money($sum['paid']) ?></div></div>
  <div class="bg-white rounded-2xl shadow p-4"><div class="text-gray-400 text-xs">Balance Due</div><div class="text-xl font-bold text-red-600"><?= money($sum['billed'] - $sum['paid']) ?></div></div>
  <div class="bg-white rounded-2xl shadow p-4 flex items-center gap-3">
    <span class="bg-yellow-50 text-yellow-700 px-3 py-1.5 rounded-lg text-sm">Partial: <strong><?= money($due['partial']) ?></strong></span>
    <span class="bg-red-50 text-red-700 px-3 py-1.5 rounded-lg text-sm">Unpaid: <strong><?= money($due['unpaid']) ?></strong></span>
  </div>
</div>

<div class="bg-white rounded-2xl shadow p-5 overflow-x-auto">
  <table class="w-full text-sm">
    <thead><tr class="text-left text-gray-500 border-b">
      <th class="py-2">Invoice #</th><th>Date</th><th>Customer</th><th>Vehicle</th><th class="text-right">Total</th><th class="text-right">Paid</th><th class="text-right">Balance</th><th>Status</th><th class="text-right">Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="border-b hover:bg-gray-50">
        <td class="py-2 font-semibold text-blue-700"><?= e($r['invoice_no']) ?></td>
        <td><?= e($r['invoice_date']) ?></td>
        <td><?= e($r['customer']) ?></td>
        <td><?= e($r['reg_no']) ?></td>
        <td class="text-right"><?= money($r['grand_total']) ?></td>
        <td class="text-right text-green-600"><?= money($r['paid_amount']) ?></td>
        <td class="text-right <?= $r['balance'] > 0 ? 'text-red-600 font-medium' : 'text-gray-400' ?>"><?= money($r['balance']) ?></td>
        <td><?= invoice_status_badge($r['status']) ?></td>
        <td class="text-right space-x-2 whitespace-nowrap">
          <a href="invoice_print.php?id=<?= (int)$r['id'] ?>" class="text-gray-600" title="View / Print"><i class="fa-solid fa-print"></i></a>
          <?php if ($r['status'] !== 'Paid'): ?>
          <a href="invoice_pay.php?id=<?= (int)$r['id'] ?>" class="text-emerald-600" title="Record payment"><i class="fa-solid fa-money-bill"></i></a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="py-6 text-center text-gray-400">No invoices found.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <?php if ($total_pages > 1):
    $qs = fn($p) => '?' . http_build_query(array_merge($_GET, ['page' => $p])); ?>
  <div class="flex items-center justify-between mt-4 text-sm text-gray-500">
    <span>Page <?= $page ?> of <?= $total_pages ?></span>
    <div class="flex gap-1">
      <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
      <a href="<?= $qs($p) ?>" class="px-3 py-1.5 rounded-lg <?= $p === $page ? 'bg-blue-600 text-white' : 'bg-gray-100 hover:bg-gray-200' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php page_footer(); ?>
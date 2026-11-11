<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

$period = $_GET['period'] ?? 'month';
if (!in_array($period, ['day','month','year'])) $period = 'month';
$fmt = ['day' => '%Y-%m-%d', 'month' => '%Y-%m', 'year' => '%Y'][$period];

// ---- Income (payments) ----
$income = $pdo->prepare("SELECT DATE_FORMAT(payment_date,'$fmt') AS per, SUM(amount) AS total FROM payments GROUP BY per ORDER BY per DESC LIMIT 24");
$income->execute();
$income = $income->fetchAll();

// ---- Invoice stats (lifetime + this period) ----
$inv_stats = $pdo->query('SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total),0) AS billed, COALESCE(SUM(paid_amount),0) AS collected FROM invoices')->fetch();
$outstanding = $pdo->query("SELECT COALESCE(SUM(grand_total - paid_amount),0) AS s FROM invoices WHERE status != 'Paid'")->fetch()['s'];

$period_billed = $pdo->prepare("SELECT COALESCE(SUM(grand_total),0) FROM invoices WHERE DATE_FORMAT(invoice_date,'$fmt') = DATE_FORMAT(CURDATE(),'$fmt')");
$period_billed->execute();
$period_billed = $period_billed->fetchColumn();

// ---- Expenses ----
$expenses = $pdo->prepare("SELECT DATE_FORMAT(expense_date,'$fmt') AS per, SUM(amount) AS total FROM expenses GROUP BY per ORDER BY per DESC LIMIT 24");
$expenses->execute();
$expenses = $expenses->fetchAll();

// ---- Service count per period ----
$svc = $pdo->prepare("SELECT DATE_FORMAT(service_date,'$fmt') AS per, COUNT(*) AS cnt FROM services GROUP BY per ORDER BY per DESC LIMIT 24");
$svc->execute();
$svc = $svc->fetchAll();

// ---- Stock valuation ----
$stock = $pdo->query('SELECT COALESCE(SUM(stock_qty * unit_price),0) AS v, COALESCE(SUM(stock_qty),0) AS q FROM parts')->fetch();
$low_count = $pdo->query('SELECT COUNT(*) c FROM parts WHERE stock_qty <= min_stock')->fetch()['c'];

// ---- Profit per period: merge income & expense rows ----
$periods = [];
foreach ($income  as $r) $periods[$r['per']]['income']  = (float)$r['total'];
foreach ($expenses as $r) $periods[$r['per']]['expense'] = (float)$r['total'];
foreach ($periods as $p => &$v) {
    $v['income']  = $v['income']  ?? 0;
    $v['expense'] = $v['expense'] ?? 0;
    $v['profit']  = $v['income'] - $v['expense'];
}
unset($v);
$total_income  = array_sum(array_column($periods, 'income'));
$total_expense = array_sum(array_column($periods, 'expense'));
$total_profit  = $total_income - $total_expense;

// ---- CSV export ----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="report_' . $period . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Period', 'Income', 'Expenses', 'Profit']);
    foreach ($periods as $p => $v) fputcsv($out, [$p, $v['income'], $v['expense'], $v['profit']]);
    fclose($out);
    exit;
}

page_header('Reports & Financial Summary');
?>
<div class="flex flex-wrap items-center justify-between gap-2 mb-5">
  <div class="flex gap-2">
    <?php foreach (['day'=>'Daily','month'=>'Monthly','year'=>'Yearly'] as $k=>$l): ?>
    <a href="reports.php?period=<?= $k ?>" class="px-4 py-2 rounded-lg text-sm font-medium <?= $period===$k ? 'bg-blue-600 text-white' : 'bg-white shadow text-gray-600' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
  <a href="reports.php?period=<?= $period ?>&export=csv" class="px-4 py-2 rounded-lg text-sm font-medium bg-emerald-600 text-white shadow"><i class="fa-solid fa-download mr-1"></i>Export CSV</a>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <div class="bg-white rounded-2xl shadow p-4"><div class="text-gray-400 text-xs">Total Billed</div><div class="text-xl font-bold"><?= money($inv_stats['billed']) ?></div></div>
  <div class="bg-white rounded-2xl shadow p-4"><div class="text-gray-400 text-xs">Collected</div><div class="text-xl font-bold text-green-600"><?= money($inv_stats['collected']) ?></div></div>
  <div class="bg-white rounded-2xl shadow p-4"><div class="text-gray-400 text-xs">Outstanding</div><div class="text-xl font-bold text-red-600"><?= money($outstanding) ?></div></div>
  <div class="bg-white rounded-2xl shadow p-4"><div class="text-gray-400 text-xs">Stock Value</div><div class="text-xl font-bold text-indigo-600"><?= money($stock['v']) ?></div><div class="text-xs text-gray-400"><?= (int)$stock['q'] ?> items<?= $low_count ? " | $low_count low-stock" : '' ?></div></div>
</div>

<div class="grid grid-cols-3 gap-4 mb-6">
  <div class="bg-white rounded-2xl shadow p-4"><div class="text-gray-400 text-xs">Income (last 24 <?= $period ?>s)</div><div class="text-xl font-bold text-green-600"><?= money($total_income) ?></div></div>
  <div class="bg-white rounded-2xl shadow p-4"><div class="text-gray-400 text-xs">Expenses (last 24 <?= $period ?>s)</div><div class="text-xl font-bold text-rose-600"><?= money($total_expense) ?></div></div>
  <div class="bg-white rounded-2xl shadow p-4"><div class="text-gray-400 text-xs">Net Profit (last 24 <?= $period ?>s)</div><div class="text-xl font-bold <?= $total_profit >= 0 ? 'text-emerald-600' : 'text-red-600' ?>"><?= money($total_profit) ?></div></div>
</div>

<div class="bg-white rounded-2xl shadow p-5 mb-6">
  <h2 class="font-bold text-gray-700 mb-3"><i class="fa-solid fa-chart-line text-blue-500 mr-2"></i>Income vs Expenses & Profit (<?= $period ?>ly)</h2>
  <canvas id="finChart" height="110"></canvas>
</div>

<div class="grid lg:grid-cols-3 gap-6">
  <div class="bg-white rounded-2xl shadow p-5">
    <h2 class="font-bold text-gray-700 mb-3"><i class="fa-solid fa-sack-dollar text-green-500 mr-2"></i>Income (<?= $period ?>ly)</h2>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-400 border-b"><th class="py-1">Period</th><th class="text-right">Income</th></tr></thead>
      <tbody><?php foreach ($income as $r): ?><tr class="border-b"><td class="py-2"><?= e($r['per']) ?></td><td class="text-right font-medium text-green-600"><?= money($r['total']) ?></td></tr><?php endforeach; ?>
      <?php if (!$income): ?><tr><td colspan="2" class="py-4 text-center text-gray-400">No income recorded.</td></tr><?php endif; ?></tbody>
    </table>
  </div>
  <div class="bg-white rounded-2xl shadow p-5">
    <h2 class="font-bold text-gray-700 mb-3"><i class="fa-solid fa-money-bill-wave text-rose-500 mr-2"></i>Expenses (<?= $period ?>ly)</h2>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-400 border-b"><th class="py-1">Period</th><th class="text-right">Expense</th></tr></thead>
      <tbody><?php foreach ($expenses as $r): ?><tr class="border-b"><td class="py-2"><?= e($r['per']) ?></td><td class="text-right font-medium text-rose-600"><?= money($r['total']) ?></td></tr><?php endforeach; ?>
      <?php if (!$expenses): ?><tr><td colspan="2" class="py-4 text-center text-gray-400">No expenses recorded.</td></tr><?php endif; ?></tbody>
    </table>
  </div>
  <div class="bg-white rounded-2xl shadow p-5">
    <h2 class="font-bold text-gray-700 mb-3"><i class="fa-solid fa-scale-balanced text-emerald-500 mr-2"></i>Profit (<?= $period ?>ly)</h2>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-400 border-b"><th class="py-1">Period</th><th class="text-right">Profit</th></tr></thead>
      <tbody>
      <?php foreach ($periods as $p => $v): ?>
        <tr class="border-b"><td class="py-2"><?= e($p) ?></td><td class="text-right font-medium <?= $v['profit'] >= 0 ? 'text-emerald-600' : 'text-red-600' ?>"><?= money($v['profit']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$periods): ?><tr><td colspan="2" class="py-4 text-center text-gray-400">No data recorded.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="bg-white rounded-2xl shadow p-5 mt-6">
  <h2 class="font-bold text-gray-700 mb-3"><i class="fa-solid fa-file-invoice text-indigo-500 mr-2"></i>Outstanding Payments</h2>
  <?php
  $rows = $pdo->query("SELECT i.invoice_no, i.grand_total, i.paid_amount, (i.grand_total - i.paid_amount) AS balance, v.reg_no, c.name AS customer
    FROM invoices i JOIN vehicles v ON v.id=i.vehicle_id JOIN customers c ON c.id=v.customer_id
    WHERE i.status != 'Paid' ORDER BY balance DESC")->fetchAll();
  ?>
  <table class="w-full text-sm">
    <thead><tr class="text-left text-gray-500 border-b"><th class="py-2">Invoice</th><th>Customer</th><th>Vehicle</th><th class="text-right">Total</th><th class="text-right">Balance</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="border-b"><td class="py-2 font-medium text-blue-700"><?= e($r['invoice_no']) ?></td><td><?= e($r['customer']) ?></td><td><?= e($r['reg_no']) ?></td><td class="text-right"><?= money($r['grand_total']) ?></td><td class="text-right text-red-600 font-medium"><?= money($r['balance']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="py-4 text-center text-gray-400">All invoices are fully paid.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const labels  = <?= json_encode(array_keys($periods)) ?>;
const incData = <?= json_encode(array_column($periods, 'income')) ?>;
const expData = <?= json_encode(array_column($periods, 'expense')) ?>;
const prfData = <?= json_encode(array_column($periods, 'profit')) ?>;
new Chart(document.getElementById('finChart'), {
  type: 'bar',
  data: {
    labels,
    datasets: [
      { label: 'Income',  data: incData, backgroundColor: 'rgba(34,197,94,.75)' },
      { label: 'Expenses', data: expData, backgroundColor: 'rgba(244,63,94,.75)' },
      { label: 'Profit',  data: prfData, type: 'line', borderColor: 'rgba(99,102,241,1)', backgroundColor: 'rgba(99,102,241,1)', tension: .3 }
    ]
  },
  options: { responsive: true, scales: { y: { beginAtZero: true } } }
});
</script>
<?php page_footer(); ?>
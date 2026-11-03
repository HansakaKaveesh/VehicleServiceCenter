<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

$period = $_GET['period'] ?? 'month';
if (!in_array($period, ['day','month','year'])) $period = 'month';
$fmt = ['day' => '%Y-%m-%d', 'month' => '%Y-%m', 'year' => '%Y'][$period];

// Income (payments)
$income = $pdo->prepare("SELECT DATE_FORMAT(payment_date,'$fmt') AS per, SUM(amount) AS total FROM payments GROUP BY per ORDER BY per DESC LIMIT 24");
$income->execute();
$income = $income->fetchAll();

// Invoice stats
$inv_stats = $pdo->query('SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total),0) AS billed, COALESCE(SUM(paid_amount),0) AS collected FROM invoices')->fetch();
$outstanding = $pdo->query("SELECT COALESCE(SUM(grand_total - paid_amount),0) AS s FROM invoices WHERE status != 'Paid'")->fetch()['s'];

// Expenses
$expenses = $pdo->prepare("SELECT DATE_FORMAT(expense_date,'$fmt') AS per, SUM(amount) AS total FROM expenses GROUP BY per ORDER BY per DESC LIMIT 24");
$expenses->execute();
$expenses = $expenses->fetchAll();

// Service count per period
$svc = $pdo->prepare("SELECT DATE_FORMAT(service_date,'$fmt') AS per, COUNT(*) AS cnt FROM services GROUP BY per ORDER BY per DESC LIMIT 24");
$svc->execute();
$svc = $svc->fetchAll();

// Stock valuation
$stock = $pdo->query('SELECT COALESCE(SUM(stock_qty * unit_price),0) AS v, COALESCE(SUM(stock_qty),0) AS q FROM parts')->fetch();
$low_count = $pdo->query('SELECT COUNT(*) c FROM parts WHERE stock_qty <= min_stock')->fetch()['c'];

page_header('Reports & Financial Summary');
?>
<div class="flex gap-2 mb-5">
  <?php foreach (['day'=>'Daily','month'=>'Monthly','year'=>'Yearly'] as $k=>$l): ?>
  <a href="reports.php?period=<?= $k ?>" class="px-4 py-2 rounded-lg text-sm font-medium <?= $period===$k ? 'bg-blue-600 text-white' : 'bg-white shadow text-gray-600' ?>"><?= $l ?></a>
  <?php endforeach; ?>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <div class="bg-white rounded-2xl shadow p-4"><div class="text-gray-400 text-xs">Total Billed</div><div class="text-xl font-bold"><?= money($inv_stats['billed']) ?></div></div>
  <div class="bg-white rounded-2xl shadow p-4"><div class="text-gray-400 text-xs">Collected</div><div class="text-xl font-bold text-green-600"><?= money($inv_stats['collected']) ?></div></div>
  <div class="bg-white rounded-2xl shadow p-4"><div class="text-gray-400 text-xs">Outstanding</div><div class="text-xl font-bold text-red-600"><?= money($outstanding) ?></div></div>
  <div class="bg-white rounded-2xl shadow p-4"><div class="text-gray-400 text-xs">Stock Value</div><div class="text-xl font-bold text-indigo-600"><?= money($stock['v']) ?></div><div class="text-xs text-gray-400"><?= (int)$stock['q'] ?> items<?= $low_count ? " | $low_count low-stock" : '' ?></div></div>
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
    <h2 class="font-bold text-gray-700 mb-3"><i class="fa-solid fa-screwdriver-wrench text-blue-500 mr-2"></i>Service Jobs (<?= $period ?>ly)</h2>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-400 border-b"><th class="py-1">Period</th><th class="text-right">Jobs</th></tr></thead>
      <tbody><?php foreach ($svc as $r): ?><tr class="border-b"><td class="py-2"><?= e($r['per']) ?></td><td class="text-right font-medium"><?= (int)$r['cnt'] ?></td></tr><?php endforeach; ?>
      <?php if (!$svc): ?><tr><td colspan="2" class="py-4 text-center text-gray-400">No services recorded.</td></tr><?php endif; ?></tbody>
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
<?php page_footer(); ?>

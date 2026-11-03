<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

$status_filter = $_GET['status'] ?? '';
$where = $status_filter ? "WHERE i.status = " . $pdo->quote($status_filter) : '';
$rows = $pdo->query("SELECT i.*, v.reg_no, c.name AS customer,
  (i.grand_total - i.paid_amount) AS balance
  FROM invoices i JOIN vehicles v ON v.id=i.vehicle_id JOIN customers c ON c.id=v.customer_id $where ORDER BY i.id DESC")->fetchAll();
$totals = ['Paid'=>0,'Partial'=>0,'Unpaid'=>0];
foreach ($rows as $r) $totals[$r['status']] += $r['balance'];
page_header('Invoice Management');
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
  <div class="flex gap-2">
    <a href="invoice_form.php" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg"><i class="fa-solid fa-plus mr-1"></i> New Invoice</a>
    <form method="get" class="flex gap-2">
      <select name="status" class="border rounded-lg px-3 py-2 text-sm bg-white" onchange="this.form.submit()">
        <option value="">All statuses</option>
        <?php foreach (['Paid','Partial','Unpaid'] as $s): ?><option <?= $status_filter === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="flex gap-4 text-sm">
    <span class="bg-yellow-50 text-yellow-700 px-3 py-1.5 rounded-lg">Partial due: <strong><?= money($totals['Partial']) ?></strong></span>
    <span class="bg-red-50 text-red-700 px-3 py-1.5 rounded-lg">Unpaid: <strong><?= money($totals['Unpaid']) ?></strong></span>
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
          <a href="invoice_print.php?id=<?= $r['id'] ?>" class="text-gray-600" title="View / Print"><i class="fa-solid fa-print"></i></a>
          <?php if ($r['status'] !== 'Paid'): ?>
          <a href="invoice_pay.php?id=<?= $r['id'] ?>" class="text-emerald-600" title="Record payment"><i class="fa-solid fa-money-bill"></i></a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="py-6 text-center text-gray-400">No invoices yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php page_footer(); ?>

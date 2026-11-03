<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT i.*, v.reg_no, c.name AS customer FROM invoices i JOIN vehicles v ON v.id=i.vehicle_id JOIN customers c ON c.id=v.customer_id WHERE i.id=?');
$st->execute([$id]);
$inv = $st->fetch();
if (!$inv) { flash('Invoice not found.', 'error'); redirect('invoices.php'); }
$balance = $inv['grand_total'] - $inv['paid_amount'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amt = min((float)$_POST['amount'], $balance);
    if ($amt > 0) {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO payments (invoice_id, amount, payment_date, method, notes) VALUES (?,?,?,?,?)')
            ->execute([$id, $amt, $_POST['payment_date'], $_POST['method'], trim($_POST['notes'])]);
        $new_paid = $inv['paid_amount'] + $amt;
        $status = $new_paid >= $inv['grand_total'] ? 'Paid' : 'Partial';
        $pdo->prepare('UPDATE invoices SET paid_amount = ?, status = ? WHERE id = ?')->execute([$new_paid, $status, $id]);
        $pdo->commit();
        flash('Payment recorded. Invoice is now ' . $status . '.');
    }
    redirect('invoices.php');
}
page_header('Record Payment - ' . $inv['invoice_no']);
?>
<div class="max-w-md bg-white rounded-2xl shadow p-6">
  <div class="text-sm text-gray-600 space-y-1 mb-4">
    <p>Customer: <strong class="text-gray-800"><?= e($inv['customer']) ?></strong></p>
    <p>Vehicle: <strong class="text-gray-800"><?= e($inv['reg_no']) ?></strong></p>
    <p>Grand total: <strong class="text-gray-800"><?= money($inv['grand_total']) ?></strong></p>
    <p>Balance due: <strong class="text-red-600"><?= money($balance) ?></strong></p>
  </div>
  <form method="post" class="space-y-3">
    <input name="amount" type="number" step="0.01" min="0.01" max="<?= $balance ?>" value="<?= $balance ?>" required class="w-full border rounded-lg px-3 py-2 text-sm">
    <input name="payment_date" type="date" value="<?= date('Y-m-d') ?>" required class="w-full border rounded-lg px-3 py-2 text-sm">
    <select name="method" class="w-full border rounded-lg px-3 py-2 text-sm bg-white">
      <?php foreach (['Cash','Card','Bank Transfer','Other'] as $m): ?><option><?= $m ?></option><?php endforeach; ?>
    </select>
    <input name="notes" placeholder="Notes (optional)" class="w-full border rounded-lg px-3 py-2 text-sm">
    <button class="w-full bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg py-2 text-sm font-medium"><i class="fa-solid fa-money-bill mr-1"></i> Record Payment</button>
  </form>
</div>
<?php page_footer(); ?>

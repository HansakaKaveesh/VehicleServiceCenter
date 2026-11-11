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

// ---- Payment history ----
$hist = $pdo->prepare('SELECT * FROM payments WHERE invoice_id = ? ORDER BY payment_date DESC, id DESC');
$hist->execute([$id]);
$hist = $hist->fetchAll();

// ---- CSRF token ----
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        $amt    = (float)($_POST['amount'] ?? 0);
        $date   = $_POST['payment_date'] ?? '';
        $method = $_POST['method'] ?? '';
        $notes  = trim($_POST['notes'] ?? '');

        if ($amt <= 0)                              $errors[] = 'Amount must be greater than zero.';
        if ($amt > $balance + 0.001)                $errors[] = 'Amount exceeds the remaining balance of ' . money($balance) . '.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $errors[] = 'Please pick a valid payment date.';
        if (!in_array($method, ['Cash','Card','Bank Transfer','Other'], true)) $errors[] = 'Invalid payment method.';

        if (!$errors) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO payments (invoice_id, amount, payment_date, method, notes) VALUES (?,?,?,?,?)')
                    ->execute([$id, $amt, $date, $method, $notes]);
                $new_paid = $inv['paid_amount'] + $amt;
                $status = $new_paid >= $inv['grand_total'] - 0.001 ? 'Paid' : 'Partial';
                $pdo->prepare('UPDATE invoices SET paid_amount = ?, status = ? WHERE id = ?')->execute([$new_paid, $status, $id]);
                $pdo->commit();
                flash('Payment of ' . money($amt) . ' recorded. Invoice is now ' . $status . '.');
            } catch (Exception $ex) {
                $pdo->rollBack();
                flash('Payment failed: ' . $ex->getMessage(), 'error');
            }
            redirect('invoices.php');
        }
    }
}

$paid_pct = $inv['grand_total'] > 0 ? min(100, round($inv['paid_amount'] / $inv['grand_total'] * 100)) : 0;
page_header('Record Payment - ' . $inv['invoice_no']);
?>
<div class="max-w-md space-y-5">
  <div class="bg-white rounded-2xl shadow p-6">
    <div class="text-sm text-gray-600 space-y-1 mb-4">
      <p>Customer: <strong class="text-gray-800"><?= e($inv['customer']) ?></strong></p>
      <p>Vehicle: <strong class="text-gray-800"><?= e($inv['reg_no']) ?></strong></p>
      <p>Grand total: <strong class="text-gray-800"><?= money($inv['grand_total']) ?></strong></p>
      <p>Balance due: <strong class="text-red-600"><?= money($balance) ?></strong></p>
    </div>
    <div class="mb-4">
      <div class="flex justify-between text-xs text-gray-500 mb-1"><span>Paid <?= money($inv['paid_amount']) ?></span><span><?= $paid_pct ?>%</span></div>
      <div class="w-full bg-gray-200 rounded-full h-2"><div class="bg-emerald-500 h-2 rounded-full" style="width: <?= $paid_pct ?>%"></div></div>
    </div>

    <?php foreach ($errors as $err): ?><div class="bg-red-50 text-red-700 text-sm rounded-lg px-3 py-2 mb-3"><i class="fa-solid fa-triangle-exclamation mr-1"></i><?= e($err) ?></div><?php endforeach; ?>

    <form method="post" class="space-y-3" id="payForm">
      <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
      <div>
        <label class="text-xs text-gray-500">Amount</label>
        <input id="amount" name="amount" type="number" step="0.01" min="0.01" max="<?= $balance ?>" value="<?= $balance ?>" required class="w-full border rounded-lg px-3 py-2 text-sm">
        <div class="flex gap-2 mt-2">
          <button type="button" onclick="setAmt(<?= $balance ?>)" class="text-xs bg-emerald-50 text-emerald-700 px-2.5 py-1 rounded-lg hover:bg-emerald-100">Full balance</button>
          <button type="button" onclick="setAmt(<?= round($balance / 2, 2) ?>)" class="text-xs bg-gray-100 text-gray-600 px-2.5 py-1 rounded-lg hover:bg-gray-200">50%</button>
          <button type="button" onclick="setAmt(<?= round($balance / 4, 2) ?>)" class="text-xs bg-gray-100 text-gray-600 px-2.5 py-1 rounded-lg hover:bg-gray-200">25%</button>
        </div>
      </div>
      <div>
        <label class="text-xs text-gray-500">Payment date</label>
        <input name="payment_date" type="date" value="<?= date('Y-m-d') ?>" required class="w-full border rounded-lg px-3 py-2 text-sm">
      </div>
      <div>
        <label class="text-xs text-gray-500">Method</label>
        <select name="method" class="w-full border rounded-lg px-3 py-2 text-sm bg-white">
          <?php foreach (['Cash','Card','Bank Transfer','Other'] as $m): ?><option><?= $m ?></option><?php endforeach; ?>
        </select>
      </div>
      <input name="notes" placeholder="Notes (optional)" class="w-full border rounded-lg px-3 py-2 text-sm">
      <button class="w-full bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg py-2 text-sm font-medium"><i class="fa-solid fa-money-bill mr-1"></i> Record Payment</button>
    </form>
  </div>

  <?php if ($hist): ?>
  <div class="bg-white rounded-2xl shadow p-6">
    <h3 class="font-bold text-gray-700 mb-3"><i class="fa-solid fa-clock-rotate-left text-gray-400 mr-2"></i>Payment History</h3>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-400 border-b"><th class="py-1">Date</th><th>Method</th><th>Notes</th><th class="text-right">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($hist as $p): ?>
        <tr class="border-b"><td class="py-2"><?= e($p['payment_date']) ?></td><td><?= e($p['method']) ?></td><td class="text-gray-500"><?= e($p['notes']) ?></td><td class="text-right text-green-600 font-medium"><?= money($p['amount']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<script>
function setAmt(v) { document.getElementById('amount').value = v.toFixed(2); }
document.getElementById('payForm').addEventListener('submit', function () {
  this.querySelector('button[type=submit], button:not([type])').disabled = true;
});
</script>
<?php page_footer(); ?>
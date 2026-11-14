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
$hist_total = 0;
foreach ($hist as $p) $hist_total += (float)$p['amount'];

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
$will_settle = ($balance > 0 && $balance < 0.005) || $balance <= 0;
page_header('Record Payment - ' . $inv['invoice_no']);
?>
<style>
  .shake { animation: shake .3s; }
  @keyframes shake { 0%,100%{transform:none} 25%{transform:translateX(-4px)} 75%{transform:translateX(4px)} }
  .quick-btn.active { outline: 2px solid #059669; outline-offset: 1px; }
  .pay-card { transition: box-shadow .2s; }
  .pay-card:focus-within { box-shadow: 0 0 0 3px rgba(16,185,129,.15); }
  .currency-rs::before { content: "Rs. "; opacity: .45; font-weight: 500; }
  @keyframes pop { from { transform: scale(.97); } to { transform: scale(1); } }
  .live-badge { animation: pop .15s ease; }
</style>

<div class="max-w-md space-y-5">
  <div class="bg-white rounded-2xl shadow p-6 pay-card">
    <div class="flex items-center justify-between mb-4">
      <div>
        <p class="text-sm text-gray-500">Customer</p>
        <p class="font-semibold text-gray-800"><?= e($inv['customer']) ?></p>
        <p class="text-xs text-gray-400 mt-0.5"><i class="fa-solid fa-car mr-1"></i><?= e($inv['reg_no']) ?> · <?= e($inv['invoice_no']) ?></p>
      </div>
      <span class="rounded-full px-3 py-1 text-xs font-semibold
        <?= $paid_pct >= 100 ? 'bg-emerald-100 text-emerald-700' : ($paid_pct > 0 ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-600') ?>">
        <?= $paid_pct >= 100 ? 'Paid' : ($paid_pct > 0 ? 'Partial' : 'Unpaid') ?>
      </span>
    </div>

    <div class="mb-4">
      <div class="flex justify-between text-xs text-gray-500 mb-1">
        <span>Paid <span class="font-medium text-gray-700 currency-rs"><?= number_format($inv['paid_amount'], 2) ?></span> of <?= money($inv['grand_total']) ?></span>
        <span><?= $paid_pct ?>%</span>
      </div>
      <div class="w-full bg-gray-200 rounded-full h-2.5"><div class="bg-emerald-500 h-2.5 rounded-full transition-all duration-500" style="width: <?= $paid_pct ?>%"></div></div>
    </div>

    <?php foreach ($errors as $err): ?><div class="bg-red-50 text-red-700 text-sm rounded-lg px-3 py-2 mb-3"><i class="fa-solid fa-triangle-exclamation mr-1"></i><?= e($err) ?></div><?php endforeach; ?>

    <form method="post" class="space-y-4" id="payForm" novalidate>
      <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">

      <div>
        <label class="text-xs text-gray-500">Amount (Rs.) <span class="text-red-500">*</span></label>
        <div class="relative mt-1">
          <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm font-medium select-none">Rs.</span>
          <input id="amount" name="amount" type="number" step="0.01" min="0.01" max="<?= $balance ?>"
                 value="<?= number_format($balance, 2, '.', '') ?>" inputmode="decimal" required
                 class="w-full border rounded-lg pl-11 pr-3 py-2.5 text-lg font-semibold focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 outline-none">
        </div>

        <div class="flex flex-wrap gap-2 mt-2">
          <button type="button" class="quick-btn text-xs bg-emerald-50 text-emerald-700 px-2.5 py-1.5 rounded-lg hover:bg-emerald-100 transition active" onclick="setAmt(<?= $balance ?>, this)">Full balance</button>
          <button type="button" class="quick-btn text-xs bg-gray-100 text-gray-600 px-2.5 py-1.5 rounded-lg hover:bg-gray-200 transition" onclick="setAmt(<?= round($balance / 2, 2) ?>, this)">50%</button>
          <button type="button" class="quick-btn text-xs bg-gray-100 text-gray-600 px-2.5 py-1.5 rounded-lg hover:bg-gray-200 transition" onclick="setAmt(<?= round($balance / 4, 2) ?>, this)">25%</button>
          <button type="button" class="quick-btn text-xs bg-gray-100 text-gray-600 px-2.5 py-1.5 rounded-lg hover:bg-gray-200 transition" onclick="this.closest('div').querySelector('#amount').focus()">Custom</button>
        </div>

        <p id="amountHint" class="text-xs mt-2 text-emerald-600 font-medium">
          <i class="fa-solid fa-circle-check mr-1"></i>Full balance — invoice will be marked <strong>Paid</strong>.
        </p>
        <p id="amountWarn" class="hidden text-xs mt-2 text-red-600 font-medium">
          <i class="fa-solid fa-triangle-exclamation mr-1"></i>Amount exceeds the balance of Rs. <?= number_format($balance, 2) ?>.
        </p>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="text-xs text-gray-500">Payment date <span class="text-red-500">*</span></label>
          <input name="payment_date" type="date" value="<?= date('Y-m-d') ?>" required
                 class="mt-1 w-full border rounded-lg px-3 py-2 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 outline-none">
        </div>
        <div>
          <label class="text-xs text-gray-500">Method <span class="text-red-500">*</span></label>
          <select name="method" class="mt-1 w-full border rounded-lg px-3 py-2 text-sm bg-white focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 outline-none">
            <?php foreach (['Cash','Card','Bank Transfer','Other'] as $m): ?><option><?= $m ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>

      <input name="notes" placeholder="Notes (optional) — e.g. receipt no., bank ref…"
             class="w-full border rounded-lg px-3 py-2 text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 outline-none">

      <button id="payBtn" type="submit"
              class="w-full bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg py-2.5 text-sm font-medium transition disabled:opacity-50 disabled:cursor-not-allowed">
        <i class="fa-solid fa-money-bill mr-1"></i> Record Payment
      </button>
      <p class="text-[11px] text-gray-400 text-center"><kbd class="border rounded px-1 bg-gray-50">Ctrl</kbd> + <kbd class="border rounded px-1 bg-gray-50">Enter</kbd> to record</p>
    </form>
  </div>

  <?php if ($hist): ?>
  <div class="bg-white rounded-2xl shadow p-6">
    <div class="flex items-center justify-between mb-3">
      <h3 class="font-bold text-gray-700"><i class="fa-solid fa-clock-rotate-left text-gray-400 mr-2"></i>Payment History</h3>
      <span class="text-xs font-semibold text-emerald-600">Total: <?= money($hist_total) ?></span>
    </div>
    <div class="space-y-2">
      <?php foreach ($hist as $p): ?>
      <div class="flex items-center justify-between text-sm border-b pb-2">
        <div>
          <p class="font-medium text-gray-700"><?= money($p['amount']) ?></p>
          <p class="text-xs text-gray-400"><?= e($p['payment_date']) ?> · <?= e($p['method']) ?></p>
          <?php if (trim($p['notes'])): ?><p class="text-xs text-gray-500 italic"><?= e($p['notes']) ?></p><?php endif; ?>
        </div>
        <span class="text-emerald-600 text-lg"><i class="fa-solid fa-circle-check"></i></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
const BALANCE = <?= json_encode((float)$balance) ?>;
const fmt = n => (Math.round(n * 100) / 100).toFixed(2);
const amtInput = document.getElementById('amount');

/* Quick amount buttons — highlight the active one and show a live hint */
function setAmt(v, btn) {
  amtInput.value = v > BALANCE ? fmt(BALANCE) : fmt(v);
  amtInput.classList.remove('shake');
  if (btn) {
    document.querySelectorAll('.quick-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
  }
  updateHint();
  amtInput.focus();
}

/* Live feedback as the user types */
function updateHint() {
  const v = parseFloat(amtInput.value) || 0;
  const hint = document.getElementById('amountHint');
  const warn = document.getElementById('amountWarn');
  if (v > BALANCE + 0.001) {
    warn.classList.remove('hidden');
    hint.classList.add('hidden');
    amtInput.classList.add('border-red-400');
  } else {
    warn.classList.add('hidden');
    amtInput.classList.remove('border-red-400');
    if (v > 0) {
      const left = BALANCE - v;
      hint.innerHTML = left <= 0.001
        ? '<i class="fa-solid fa-circle-check mr-1"></i>Full balance — invoice will be marked <strong>Paid</strong>.'
        : `<i class="fa-solid fa-circle-info mr-1"></i>Rs. ${fmt(left)} will remain due after this payment.`;
      hint.classList.remove('hidden');
    } else {
      hint.classList.add('hidden');
    }
  }
}
amtInput.addEventListener('input', updateHint);

/* Client-side guards + submit UX */
document.getElementById('payForm').addEventListener('submit', function (e) {
  const v = parseFloat(amtInput.value) || 0;
  if (v <= 0 || v > BALANCE + 0.001) {
    e.preventDefault();
    amtInput.classList.add('shake', 'border-red-400');
    setTimeout(() => amtInput.classList.remove('shake'), 350);
    amtInput.focus();
    return;
  }
  const btn = document.getElementById('payBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Recording payment…';
});

/* Ctrl/Cmd + Enter shortcut */
document.addEventListener('keydown', function (e) {
  if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
    e.preventDefault();
    document.getElementById('payForm').requestSubmit();
  }
});

/* Focus the amount field on load */
amtInput.focus();
amtInput.select();
updateHint();
</script>
<?php page_footer(); ?>
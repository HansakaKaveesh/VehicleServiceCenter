<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

// --- Prefill from a service job ---
$service = null;
if (!empty($_GET['service_id'])) {
    $st = $pdo->prepare('SELECT s.*, v.reg_no, v.customer_id FROM services s JOIN vehicles v ON v.id=s.vehicle_id WHERE s.id=?');
    $st->execute([(int)$_GET['service_id']]);
    $service = $st->fetch();
    if (!$service) { flash('Service job not found.', 'error'); redirect('services.php'); }
    if ($pdo->prepare('SELECT COUNT(*) c FROM invoices WHERE service_id=?')) {
        $chk = $pdo->prepare('SELECT COUNT(*) c FROM invoices WHERE service_id=?');
        $chk->execute([$service['id']]);
        if ($chk->fetch()['c'] > 0) { flash('An invoice already exists for this service job.', 'error'); redirect('invoices.php'); }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicle_id = (int)$_POST['vehicle_id'];
    $service_id = $_POST['service_id'] ?: null;
    $labor = (float)($_POST['labor_charge'] ?? 0);
    $discount = (float)($_POST['discount'] ?? 0);
    $part_ids = $_POST['part_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $prices = $_POST['unit_price'] ?? [];

    $parts_total = 0; $items = [];
    foreach ($part_ids as $i => $pid) {
        if (!$pid) continue;
        $q = max(1, (int)$qtys[$i]);
        $pr = (float)$prices[$i];
        $items[] = [(int)$pid, $q, $pr];
        $parts_total += $q * $pr;
    }
    $subtotal = $labor + $parts_total;
    $tax = round(max(0, $subtotal - $discount) * TAX_RATE / 100, 2);
    $grand = max(0, $subtotal - $discount) + $tax;

    $pdo->beginTransaction();
    try {
        $no = 'INV-' . date('Ymd') . '-' . str_pad((int)$pdo->query('SELECT COUNT(*) c FROM invoices')->fetch()['c'] + 1, 4, '0', STR_PAD_LEFT);
        $pdo->prepare('INSERT INTO invoices (invoice_no, vehicle_id, service_id, invoice_date, labor_total, parts_total, discount, tax, grand_total, paid_amount, status) VALUES (?,?,?,?,?,?,?,?,?,0,"Unpaid")')
            ->execute([$no, $vehicle_id, $service_id, $_POST['invoice_date'], $labor, $parts_total, $discount, $tax, $grand]);
        $inv_id = $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO invoice_items (invoice_id, part_id, quantity, unit_price) VALUES (?,?,?,?)');
        $use = $pdo->prepare('INSERT INTO stock_movements (part_id, movement_type, quantity, unit_price, reference, notes) VALUES (?,"Usage",?,?,?,?)');
        $upd = $pdo->prepare('UPDATE parts SET stock_qty = GREATEST(0, stock_qty - ?) WHERE id = ?');
        foreach ($items as [$pid, $q, $pr]) {
            $ins->execute([$inv_id, $pid, $q, $pr]);
            $use->execute([$pid, $q, $pr, $no, 'Invoiced']);
            $upd->execute([$q, $pid]);
        }
        $pdo->commit();
        flash("Invoice $no created.");
        redirect('invoice_print.php?id=' . $inv_id);
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('Error creating invoice: ' . $ex->getMessage(), 'error');
    }
}

$vehicles = $pdo->query('SELECT v.id, v.reg_no, v.make, v.model, c.name AS customer FROM vehicles v JOIN customers c ON c.id=v.customer_id ORDER BY v.reg_no')->fetchAll();
$parts = $pdo->query('SELECT id, name, part_no, unit_price, stock_qty FROM parts ORDER BY name')->fetchAll();
page_header('Create Invoice');
?>
<form method="post" class="grid lg:grid-cols-3 gap-6" id="invForm">
  <div class="space-y-6">
    <div class="bg-white rounded-2xl shadow p-5 space-y-3">
      <h2 class="font-bold text-gray-700">Invoice Details</h2>
      <input type="hidden" name="service_id" value="<?= e($service['id'] ?? '') ?>">
      <select name="vehicle_id" required class="w-full border rounded-lg px-3 py-2 text-sm bg-white">
        <option value="">-- Select Vehicle --</option>
        <?php foreach ($vehicles as $v): ?>
        <option value="<?= $v['id'] ?>" <?= ($service['vehicle_id'] ?? $_GET['vehicle_id'] ?? '') == $v['id'] ? 'selected' : '' ?>><?= e($v['reg_no']) ?> - <?= e($v['make']) ?> <?= e($v['model']) ?> (<?= e($v['customer']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <div class="grid grid-cols-2 gap-2">
        <input name="invoice_date" type="date" required value="<?= date('Y-m-d') ?>" class="border rounded-lg px-3 py-2 text-sm">
        <input name="labor_charge" id="labor" type="number" step="0.01" min="0" placeholder="Labor charge" value="<?= e($service['labor_charge'] ?? '') ?>" class="border rounded-lg px-3 py-2 text-sm" oninput="calc()">
      </div>
      <input name="discount" id="discount" type="number" step="0.01" min="0" placeholder="Discount" value="0" class="w-full border rounded-lg px-3 py-2 text-sm" oninput="calc()">
      <p class="text-xs text-gray-400">Tax (<?= TAX_RATE ?>%) applied automatically on (labor + parts - discount).</p>
    </div>
    <div class="bg-white rounded-2xl shadow p-5 space-y-2 text-sm">
      <div class="flex justify-between text-gray-600"><span>Parts subtotal</span><span id="showParts">0.00</span></div>
      <div class="flex justify-between text-gray-600"><span>Labor</span><span id="showLabor">0.00</span></div>
      <div class="flex justify-between text-gray-600"><span>Discount</span><span id="showDisc">0.00</span></div>
      <div class="flex justify-between text-gray-600"><span>Tax (<?= TAX_RATE ?>%)</span><span id="showTax">0.00</span></div>
      <div class="flex justify-between font-bold text-lg border-t pt-2"><span>Grand Total</span><span id="showGrand">0.00</span></div>
      <button class="w-full mt-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg py-2.5 text-sm font-medium"><i class="fa-solid fa-file-invoice mr-1"></i> Generate Invoice</button>
    </div>
  </div>
  <div class="lg:col-span-2 bg-white rounded-2xl shadow p-5">
    <div class="flex items-center justify-between mb-3">
      <h2 class="font-bold text-gray-700">Parts Used</h2>
      <button type="button" onclick="addRow()" class="text-sm bg-gray-800 hover:bg-gray-700 text-white px-3 py-1.5 rounded-lg"><i class="fa-solid fa-plus mr-1"></i> Add Part</button>
    </div>
    <table class="w-full text-sm" id="partsTable">
      <thead><tr class="text-left text-gray-500 border-b">
        <th class="py-2 w-1/2">Part</th><th>Qty</th><th>Unit Price</th><th class="text-right">Amount</th><th></th>
      </tr></thead>
      <tbody></tbody>
    </table>
    <p class="text-xs text-gray-400 mt-3"><i class="fa-solid fa-circle-info mr-1"></i>Stock will be deducted automatically when the invoice is created.</p>
  </div>
</form>

<script>
const parts = <?= json_encode($parts) ?>;
const TAX = <?= (int)TAX_RATE ?>;
let idx = 0;
function addRow() {
  idx++;
  const options = parts.map(p => `<option value="${p.id}" data-price="${p.unit_price}" data-stock="${p.stock_qty}">${p.name} (${p.part_no||'-'}) - stock: ${p.stock_qty}</option>`).join('');
  const row = document.createElement('tr');
  row.className = 'border-b';
  row.innerHTML = `
    <td class="py-2 pr-2"><select name="part_id[]" class="w-full border rounded-lg px-2 py-1.5 bg-white" onchange="pickPart(this)"><option value="">-- select --</option>${options}</select></td>
    <td class="pr-2"><input name="qty[]" type="number" min="1" value="1" class="w-20 border rounded-lg px-2 py-1.5" oninput="calc()"></td>
    <td class="pr-2"><input name="unit_price[]" type="number" step="0.01" min="0" value="0" class="w-28 border rounded-lg px-2 py-1.5" oninput="calc()"></td>
    <td class="text-right amt font-medium">0.00</td>
    <td class="text-right"><button type="button" onclick="this.closest('tr').remove();calc()" class="text-red-600"><i class="fa-solid fa-xmark"></i></button></td>`;
  document.querySelector('#partsTable tbody').appendChild(row);
}
function pickPart(sel) {
  const opt = sel.selectedOptions[0];
  const row = sel.closest('tr');
  if (opt && opt.dataset.price) row.querySelector('[name="unit_price[]"]').value = opt.dataset.price;
  calc();
}
function calc() {
  let partsTotal = 0;
  document.querySelectorAll('#partsTable tbody tr').forEach(tr => {
    const qty = parseFloat(tr.querySelector('[name="qty[]"]').value) || 0;
    const pr  = parseFloat(tr.querySelector('[name="unit_price[]"]').value) || 0;
    const amt = qty * pr;
    tr.querySelector('.amt').textContent = amt.toFixed(2);
    partsTotal += amt;
  });
  const labor = parseFloat(document.getElementById('labor').value) || 0;
  const disc  = parseFloat(document.getElementById('discount').value) || 0;
  const taxable = Math.max(0, partsTotal + labor - disc);
  const tax = taxable * TAX / 100;
  document.getElementById('showParts').textContent = partsTotal.toFixed(2);
  document.getElementById('showLabor').textContent = labor.toFixed(2);
  document.getElementById('showDisc').textContent = disc.toFixed(2);
  document.getElementById('showTax').textContent = tax.toFixed(2);
  document.getElementById('showGrand').textContent = (taxable + tax).toFixed(2);
}
addRow();
</script>
<?php page_footer(); ?>

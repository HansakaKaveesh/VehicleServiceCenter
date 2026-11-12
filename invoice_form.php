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
    $chk = $pdo->prepare('SELECT COUNT(*) c FROM invoices WHERE service_id=?');
    $chk->execute([$service['id']]);
    if ($chk->fetch()['c'] > 0) {
        flash('An invoice already exists for this service job.', 'error');
        redirect('invoices.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicle_id = (int)$_POST['vehicle_id'];
    if ($vehicle_id <= 0) {
        flash('Please select a vehicle.', 'error');
        redirect($_SERVER['REQUEST_URI']);
    }
    $service_id = $_POST['service_id'] ?: null;
    $labor      = max(0, (float)($_POST['labor_charge'] ?? 0));
    $discount   = max(0, (float)($_POST['discount'] ?? 0));
    $part_ids   = $_POST['part_id']   ?? [];
    $qtys       = $_POST['qty']       ?? [];
    $prices     = $_POST['unit_price'] ?? [];

    // ---- Server-side validation: stock check & de-dup (the JS only warns) ----
    $stockMap = [];
    foreach ($pdo->query('SELECT id, name, stock_qty FROM parts')->fetchAll() as $p) {
        $stockMap[(int)$p['id']] = $p;
    }

    $items = [];
    foreach ($part_ids as $i => $pid) {
        if (!$pid) continue;
        $pid = (int)$pid;
        $q   = max(1, (int)$qtys[$i]);
        $pr  = max(0, (float)$prices[$i]);
        if (!isset($stockMap[$pid])) {
            flash('Unknown part selected.', 'error');
            redirect($_SERVER['REQUEST_URI']);
        }
        if ($q > (int)$stockMap[$pid]['stock_qty']) {
            flash("Not enough stock for {$stockMap[$pid]['name']} (only {$stockMap[$pid]['stock_qty']} available).", 'error');
            redirect($_SERVER['REQUEST_URI']);
        }
        if (isset($items[$pid])) {                       // merge duplicate lines
            $items[$pid][1] += $q;
        } else {
            $items[$pid] = [$pid, $q, $pr];
        }
    }
    $items = array_values($items);

    $parts_total = 0;
    foreach ($items as [, $q, $pr]) $parts_total += $q * $pr;
    $subtotal = $labor + $parts_total;
    $tax   = round(max(0, $subtotal - $discount) * TAX_RATE / 100, 2);
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
$parts    = $pdo->query('SELECT id, name, part_no, unit_price, stock_qty FROM parts ORDER BY name')->fetchAll();
page_header('Create Invoice');
?>
<style>
  /* --- Friendly touches --- */
  .inv-total-row { transition: background .2s; }
  .inv-total-row:hover { background: #f8fafc; }
  #partsTable select, #partsTable input { transition: border-color .15s, box-shadow .15s; }
  #partsTable select:focus, #partsTable input:focus {
    border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.15); outline: none;
  }
  .stock-badge { font-size: 11px; padding: 2px 8px; border-radius: 9999px; font-weight: 600; }
  .stock-ok   { background: #dcfce7; color: #166534; }
  .stock-low  { background: #fef9c3; color: #854d0e; }
  .stock-out  { background: #fee2e2; color: #991b1b; }
  .row-warn   { background: #fff7ed !important; }
  .currency::before { content: "$"; opacity: .45; margin-right: 2px; }
  @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
  #partsTable tbody tr { animation: fadeIn .25s ease; }
</style>

<form method="post" class="grid lg:grid-cols-3 gap-6" id="invForm" novalidate>
  <div class="space-y-6">
    <!-- Step 1: vehicle & billing -->
    <div class="bg-white rounded-2xl shadow p-5 space-y-3">
      <div class="flex items-center gap-2">
        <span class="w-6 h-6 rounded-full bg-blue-600 text-white text-xs font-bold flex items-center justify-center">1</span>
        <h2 class="font-bold text-gray-700">Invoice Details</h2>
      </div>
      <input type="hidden" name="service_id" value="<?= e($service['id'] ?? '') ?>">

      <label class="block text-xs font-medium text-gray-500">Vehicle / Customer</label>
      <select name="vehicle_id" id="vehicleSel" required
              class="w-full border rounded-lg px-3 py-2 text-sm bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none">
        <option value="">-- Select Vehicle --</option>
        <?php foreach ($vehicles as $v): ?>
        <option value="<?= $v['id'] ?>" <?= ($service['vehicle_id'] ?? $_GET['vehicle_id'] ?? '') == $v['id'] ? 'selected' : '' ?>>
          <?= e($v['reg_no']) ?> · <?= e($v['make']) ?> <?= e($v['model']) ?> — <?= e($v['customer']) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <p id="vehicleHint" class="text-xs text-gray-400 hidden"><i class="fa-solid fa-circle-check text-green-600 mr-1"></i>Vehicle selected.</p>

      <label class="block text-xs font-medium text-gray-500">Invoice Date</label>
      <input name="invoice_date" type="date" required value="<?= date('Y-m-d') ?>"
             class="w-full border rounded-lg px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none">

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-medium text-gray-500">Labor Charge</label>
          <input name="labor_charge" id="labor" type="number" step="0.01" min="0" placeholder="0.00"
                 value="<?= e($service['labor_charge'] ?? '') ?>"
                 class="w-full border rounded-lg px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none" oninput="calc()">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500">Discount</label>
          <input name="discount" id="discount" type="number" step="0.01" min="0" placeholder="0.00" value="0"
                 class="w-full border rounded-lg px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none" oninput="calc()">
        </div>
      </div>
      <p class="text-xs text-gray-400"><i class="fa-solid fa-circle-info mr-1"></i>Tax (<?= TAX_RATE ?>%) is applied automatically on (labor + parts − discount).</p>
    </div>

    <!-- Live totals -->
    <div class="bg-white rounded-2xl shadow p-5 space-y-2 text-sm sticky top-4">
      <h2 class="font-bold text-gray-700 mb-1">Summary</h2>
      <div class="inv-total-row flex justify-between text-gray-600 px-2 rounded-lg py-1"><span>Parts subtotal</span><span class="currency" id="showParts">0.00</span></div>
      <div class="inv-total-row flex justify-between text-gray-600 px-2 rounded-lg py-1"><span>Labor</span><span class="currency" id="showLabor">0.00</span></div>
      <div class="inv-total-row flex justify-between text-gray-600 px-2 rounded-lg py-1"><span>Discount</span><span class="text-red-500 currency" id="showDisc">0.00</span></div>
      <div class="inv-total-row flex justify-between text-gray-600 px-2 rounded-lg py-1"><span>Tax (<?= TAX_RATE ?>%)</span><span class="currency" id="showTax">0.00</span></div>
      <div class="inv-total-row flex justify-between font-bold text-lg border-t pt-2 mt-1 px-2"><span>Grand Total</span><span class="currency text-blue-700" id="showGrand">0.00</span></div>

      <div id="formError" class="hidden text-xs bg-red-50 text-red-700 rounded-lg px-3 py-2 mt-2"></div>

      <button id="submitBtn"
              class="w-full mt-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg py-2.5 text-sm font-medium transition">
        <i class="fa-solid fa-file-invoice mr-1"></i> Generate Invoice
      </button>
    </div>
  </div>

  <!-- Step 2: parts -->
  <div class="lg:col-span-2 bg-white rounded-2xl shadow p-5">
    <div class="flex items-center justify-between mb-3">
      <div class="flex items-center gap-2">
        <span class="w-6 h-6 rounded-full bg-blue-600 text-white text-xs font-bold flex items-center justify-center">2</span>
        <h2 class="font-bold text-gray-700">Parts Used</h2>
      </div>
      <button type="button" onclick="addRow()"
              class="text-sm bg-gray-800 hover:bg-gray-700 text-white px-3 py-1.5 rounded-lg transition">
        <i class="fa-solid fa-plus mr-1"></i> Add Part
      </button>
    </div>

    <div class="mb-3">
      <input id="partSearch" type="text" placeholder="🔍 Filter parts list (type to search)…"
             class="w-full border rounded-lg px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none"
             oninput="filterOptions(this.value)">
    </div>

    <table class="w-full text-sm" id="partsTable">
      <thead><tr class="text-left text-gray-500 border-b">
        <th class="py-2 w-1/2">Part</th><th>Qty</th><th>Unit Price</th><th class="text-right">Amount</th><th></th>
      </tr></thead>
      <tbody></tbody>
    </table>

    <p class="text-xs text-gray-400 mt-3">
      <i class="fa-solid fa-circle-info mr-1"></i>Stock is deducted automatically when the invoice is created.
      Rows turn <span class="text-amber-700 font-medium">amber</span> if qty exceeds available stock.
    </p>
  </div>
</form>

<script>
const parts = <?= json_encode($parts) ?>;
const TAX   = <?= (int)TAX_RATE ?>;
let idx = 0;

/* ---------- helpers ---------- */
const fmt = n => n.toFixed(2);

function stockBadge(stock) {
  if (stock <= 0) return `<span class="stock-badge stock-out">out of stock</span>`;
  if (stock <= 5) return `<span class="stock-badge stock-low">low: ${stock}</span>`;
  return `<span class="stock-badge stock-ok">stock: ${stock}</span>`;
}

/* ---------- add a part row ---------- */
function addRow(prefillPartId = null, prefillQty = 1) {
  idx++;
  const options = parts.map(p =>
    `<option value="${p.id}" data-price="${p.unit_price}" data-stock="${p.stock_qty}" ${p.stock_qty<=0?'disabled':''}>
       ${p.name} (${p.part_no||'-'})${p.stock_qty<=0?' — OUT OF STOCK':''}
     </option>`).join('');
  const row = document.createElement('tr');
  row.className = 'border-b';
  row.innerHTML = `
    <td class="py-2 pr-2">
      <select name="part_id[]" class="w-full border rounded-lg px-2 py-1.5 bg-white" onchange="pickPart(this)">
        <option value="">-- select a part --</option>${options}
      </select>
    </td>
    <td class="pr-2">
      <input name="qty[]" type="number" min="1" value="${prefillQty}" class="w-20 border rounded-lg px-2 py-1.5" oninput="calc()">
    </td>
    <td class="pr-2">
      <input name="unit_price[]" type="number" step="0.01" min="0" value="0" class="w-28 border rounded-lg px-2 py-1.5" oninput="calc()">
    </td>
    <td class="text-right amt font-medium">0.00</td>
    <td class="text-right">
      <button type="button" onclick="this.closest('tr').remove();calc()" class="text-red-600 hover:text-red-800 transition" title="Remove line">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </td>`;
  document.querySelector('#partsTable tbody').appendChild(row);
  if (prefillPartId) {
    const sel = row.querySelector('select');
    sel.value = prefillPartId;
    pickPart(sel);
  }
  calc();
}

/* ---------- when a part is picked ---------- */
function pickPart(sel) {
  const opt = sel.selectedOptions[0];
  const row = sel.closest('tr');
  if (opt && opt.dataset.price) {
    row.querySelector('[name="unit_price[]"]').value = opt.dataset.price;
    row.querySelector('[name="qty[]"]').max = opt.dataset.stock;   // hint for browser UI
  }
  calc();
}

/* ---------- live totals + validation feedback ---------- */
function calc() {
  let partsTotal = 0;
  let problems = [];

  document.querySelectorAll('#partsTable tbody tr').forEach(tr => {
    const sel = tr.querySelector('[name="part_id[]"]');
    const qty = parseFloat(tr.querySelector('[name="qty[]"]').value) || 0;
    const pr  = parseFloat(tr.querySelector('[name="unit_price[]"]').value) || 0;
    const amt = qty * pr;
    tr.querySelector('.amt').textContent = fmt(amt);
    partsTotal += amt;

    // stock check per row
    const opt = sel.selectedOptions[0];
    if (opt && opt.dataset.stock !== undefined && sel.value) {
      const stock = parseInt(opt.dataset.stock);
      if (qty > stock) {
        tr.classList.add('row-warn');
        problems.push(`${sel.selectedOptions[0].text.split('(')[0].trim()}: only ${stock} in stock`);
      } else {
        tr.classList.remove('row-warn');
      }
    }
  });

  const labor = Math.max(0, parseFloat(document.getElementById('labor').value)   || 0);
  const disc  = Math.max(0, parseFloat(document.getElementById('discount').value) || 0);
  const taxable = Math.max(0, partsTotal + labor - disc);
  const tax  = taxable * TAX / 100;
  const grand = taxable + tax;

  document.getElementById('showParts').textContent = fmt(partsTotal);
  document.getElementById('showLabor').textContent = fmt(labor);
  document.getElementById('showDisc').textContent  = fmt(disc);
  document.getElementById('showTax').textContent   = fmt(tax);
  document.getElementById('showGrand').textContent = fmt(grand);

  // surface inline problems instead of letting the user fail at submit
  const errBox = document.getElementById('formError');
  if (problems.length) {
    errBox.innerHTML = '<i class="fa-solid fa-triangle-exclamation mr-1"></i>' + problems.join('<br>');
    errBox.classList.remove('hidden');
  } else {
    errBox.classList.add('hidden');
  }
}

/* ---------- filter the part dropdowns with the search box ---------- */
function filterOptions(q) {
  q = q.toLowerCase();
  document.querySelectorAll('#partsTable tbody tr select').forEach(sel => {
    const current = sel.value;
    [...sel.options].forEach(opt => {
      if (!opt.value) return;                       // keep the "-- select --" placeholder
      opt.hidden = q && !opt.textContent.toLowerCase().includes(q);
    });
  });
}

/* ---------- client-side guards + submit UX ---------- */
document.getElementById('invForm').addEventListener('submit', function (e) {
  const vehicle = document.getElementById('vehicleSel');
  if (!vehicle.value) {
    e.preventDefault();
    vehicle.classList.add('border-red-500');
    vehicle.focus();
    vehicle.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return;
  }
  // duplicate part check
  const seen = {}, dup = [];
  document.querySelectorAll('#partsTable tbody tr select').forEach(sel => {
    if (sel.value) { seen[sel.value] = (seen[sel.value]||0) + 1; if (seen[sel.value] === 2) dup.push(sel.selectedOptions[0].text.split('(')[0].trim()); }
  });
  if (dup.length) {
    e.preventDefault();
    alert('Duplicate part lines: ' + dup.join(', ') + '\nPlease merge them into one line.');
    return;
  }
  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Creating invoice…';
});

// show a friendly hint once a vehicle is picked
document.getElementById('vehicleSel').addEventListener('change', function () {
  this.classList.remove('border-red-500');
  document.getElementById('vehicleHint').classList.toggle('hidden', !this.value);
});

addRow();
</script>
<?php page_footer(); ?>
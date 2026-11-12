<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

if (isset($_GET['delete'])) {
    try {
        $pdo->prepare('DELETE FROM vehicles WHERE id = ?')->execute([(int)$_GET['delete']]);
        flash('Vehicle deleted.');
    } catch (PDOException $ex) {
        flash('Unable to delete vehicle. Check linked service records.', 'error');
    }
    redirect('vehicles.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $data = [(int)$_POST['customer_id'], strtoupper(trim($_POST['reg_no'])), trim($_POST['make']), trim($_POST['model']),
             trim($_POST['year']), trim($_POST['engine_no']), trim($_POST['chassis_no']), (int)$_POST['mileage']];
    try {
        if ($id) {
            $pdo->prepare('UPDATE vehicles SET customer_id=?, reg_no=?, make=?, model=?, year=?, engine_no=?, chassis_no=?, mileage=? WHERE id=?')->execute([...$data, $id]);
            flash('Vehicle updated.');
        } else {
            $pdo->prepare('INSERT INTO vehicles (customer_id, reg_no, make, model, year, engine_no, chassis_no, mileage) VALUES (?,?,?,?,?,?,?,?)')->execute($data);
            flash('Vehicle registered.');
        }
    } catch (PDOException $ex) {
        flash('Error: registration number already exists.', 'error');
    }
    redirect('vehicles.php');
}

$customers = $pdo->query('SELECT id, name FROM customers ORDER BY name')->fetchAll();

$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM vehicles WHERE id=?'); $st->execute([(int)$_GET['edit']]); $edit = $st->fetch();
}

// ------------------------------------------------------------------
// SEARCH / FILTER / PAGINATION
// ------------------------------------------------------------------
$search     = trim($_GET['search'] ?? '');
$customerId = (int)($_GET['customer_id'] ?? 0);
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 12;

$conds  = [];
$params = [];
if ($customerId) { $conds[] = 'v.customer_id = ?'; $params[] = $customerId; }
if ($search !== '') {
    $kw = "%$search%";
    $conds[] = '(v.reg_no LIKE ? OR v.make LIKE ? OR v.model LIKE ? OR c.name LIKE ?)';
    array_push($params, $kw, $kw, $kw, $kw);
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

// CSV export (respects filters)
if (($_GET['export'] ?? '') === 'csv') {
    $stmt = $pdo->prepare("SELECT v.reg_no, v.make, v.model, v.year, v.engine_no, v.chassis_no, v.mileage, c.name AS owner
                           FROM vehicles v JOIN customers c ON c.id = v.customer_id
                           $where ORDER BY v.id DESC");
    $stmt->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="vehicles_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Reg No', 'Make', 'Model', 'Year', 'Engine No', 'Chassis No', 'Mileage (km)', 'Owner']);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) fputcsv($out, $row);
    fclose($out);
    exit;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles v JOIN customers c ON c.id = v.customer_id $where");
$countStmt->execute($params);
$totalFiltered = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$st = $pdo->prepare("SELECT v.*, c.name AS customer FROM vehicles v JOIN customers c ON c.id = v.customer_id $where ORDER BY v.id DESC LIMIT $perPage OFFSET $offset");
$st->execute($params);
$vehicles = $st->fetchAll();

$totalVehicles = (int)$pdo->query('SELECT COUNT(*) FROM vehicles')->fetchColumn();
$totalKm = (int)$pdo->query('SELECT COALESCE(SUM(mileage),0) FROM vehicles')->fetchColumn();

// Auto-open modal state
$openAdd  = isset($_GET['add']);
$openEdit = (bool)$edit;
$prefillCustomer = (int)($edit['customer_id'] ?? $customerId ?? 0);

page_header('Vehicle Management');
?>

<style>
  .vm-input { width:100%; border:1px solid #e2e8f0; border-radius:.65rem; padding:.55rem .85rem; font-size:.85rem;
              background:#f8fafc; transition:all .2s; color:#334155; }
  .vm-input:focus { outline:none; border-color:#3b82f6; background:#fff; box-shadow:0 0 0 3px rgba(59,130,246,.12); }
  .vm-icon { color:#94a3b8; position:absolute; left:.8rem; top:50%; transform:translateY(-50%); font-size:.8rem; }
  .vm-field { position:relative; }
  .vm-field .vm-input { padding-left:2.2rem; }
  .vm-badge { display:inline-flex; align-items:center; gap:.35rem; padding:.15rem .6rem; border-radius:999px;
              font-size:.72rem; font-weight:600; background:#eff6ff; color:#1d4ed8; }
  .vm-action { display:inline-flex; align-items:center; justify-content:center; width:2rem; height:2rem;
               border-radius:.55rem; transition:all .18s; }
  .vm-action:hover { transform:translateY(-2px); }
  .vm-card { background:#fff; border-radius:1.25rem; box-shadow:0 1px 3px rgba(15,23,42,.07), 0 8px 24px -12px rgba(15,23,42,.12); }

  /* ---------- Modals ---------- */
  .modal-backdrop {
      position: fixed; inset: 0; background: rgba(15,23,42,.55);
      backdrop-filter: blur(2px); z-index: 50;
      display: flex; align-items: center; justify-content: center;
      padding: 1rem; opacity: 0; pointer-events: none; transition: opacity .18s ease;
  }
  .modal-backdrop.open { opacity: 1; pointer-events: auto; }
  .modal-card {
      background: #fff; border-radius: 1.25rem; width: 100%; max-width: 32rem;
      max-height: 92vh; overflow-y: auto; padding: 1.5rem;
      transform: translateY(14px) scale(.98); transition: transform .18s ease;
      box-shadow: 0 20px 50px rgba(0,0,0,.25);
  }
  .modal-backdrop.open .modal-card { transform: none; }

  /* ---------- Mobile: table -> cards ---------- */
  @media (max-width: 767px) {
    .responsive-table thead { display: none; }
    .responsive-table, .responsive-table tbody, .responsive-table tr, .responsive-table td { display: block; width: 100%; }
    .responsive-table tr { border: 1px solid #e5e7eb; border-radius: .85rem; margin-bottom: .75rem; padding: .75rem 1rem; background: #fff; }
    .responsive-table td { border: none; padding: .35rem 0; display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
    .responsive-table td::before {
        content: attr(data-label); font-size: .7rem; font-weight: 600;
        text-transform: uppercase; letter-spacing: .04em; color: #94a3b8;
    }
  }
</style>

<script>
// ---------- Modal helpers ----------
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
    const first = document.querySelector('#' + id + ' [autofocus]');
    if (first) setTimeout(() => first.focus(), 120);
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-backdrop.open')
        .forEach(m => closeModal(m.id));
});
document.addEventListener('click', e => {
    if (e.target.classList.contains('modal-backdrop')) closeModal(e.target.id);
});

// ---------- Debounced live search ----------
let searchTimer;
function liveSearch(form) {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => form.submit(), 450);
}

// ---------- Vehicle modal ----------
function openVehicle(v) {
    const f = document.getElementById('vehicleForm');
    f.reset();

    document.getElementById('v_id').value        = v?.id || '';
    document.getElementById('v_customer').value  = v?.customer_id || '';
    document.getElementById('v_reg').value       = v?.reg_no || '';
    document.getElementById('v_make').value      = v?.make || '';
    document.getElementById('v_model').value     = v?.model || '';
    document.getElementById('v_year').value      = v?.year || '';
    document.getElementById('v_engine').value    = v?.engine_no || '';
    document.getElementById('v_chassis').value   = v?.chassis_no || '';
    document.getElementById('v_mileage').value   = v?.mileage || '';

    const editing = !!(v && v.id);
    document.getElementById('vmTitle').textContent       = editing ? 'Edit Vehicle' : 'Register Vehicle';
    document.getElementById('vmSubmitLabel').textContent = editing ? 'Update Vehicle' : 'Register Vehicle';
    document.querySelector('#vmIcon i').className = editing ? 'fa-solid fa-pen' : 'fa-solid fa-plus';
    document.getElementById('vmSubmit').className =
        'w-full text-white rounded-xl py-2.5 text-sm font-semibold shadow-md hover:shadow-lg transition-all ' +
        (editing ? 'bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600'
                 : 'bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700');

    openModal('vehicleModal');
}

// ---------- Delete confirmation ----------
let deleteTarget = '';
function confirmDelete(id, reg) {
    deleteTarget = id;
    document.getElementById('delReg').textContent = reg;
    openModal('deleteModal');
}
function doDelete() {
    window.location.href = 'vehicles.php?delete=' + deleteTarget;
}

// ---------- Auto-open modal (edit / preselected customer / ?add=1) ----------
document.addEventListener('DOMContentLoaded', () => {
    <?php if ($openEdit): ?>
        openVehicle(<?= json_encode([
            'id' => (int)$edit['id'], 'customer_id' => (int)$edit['customer_id'],
            'reg_no' => $edit['reg_no'], 'make' => $edit['make'], 'model' => $edit['model'],
            'year' => $edit['year'], 'engine_no' => $edit['engine_no'],
            'chassis_no' => $edit['chassis_no'], 'mileage' => (int)$edit['mileage'],
        ]) ?>);
    <?php elseif ($openAdd): ?>
        openVehicle(<?= json_encode(['customer_id' => $prefillCustomer]) ?>);
    <?php endif; ?>
});
</script>

<!-- Page hero -->
<div class="rounded-2xl bg-gradient-to-r from-blue-700 via-indigo-700 to-violet-700 text-white px-6 py-5 mb-6 flex flex-wrap items-center justify-between gap-4 shadow-lg">
  <div>
    <h1 class="text-xl font-bold flex items-center gap-2"><i class="fa-solid fa-car-side"></i> Vehicle Management</h1>
    <p class="text-blue-100 text-sm mt-1">Register vehicles, track owners, and manage service history.</p>
  </div>
  <div class="flex gap-3 items-center">
    <div class="flex gap-3 text-center">
      <div class="bg-white/15 backdrop-blur rounded-xl px-4 py-2">
        <div class="text-lg font-bold leading-tight"><?= number_format($totalVehicles) ?></div>
        <div class="text-[11px] text-blue-100 uppercase tracking-wide">Vehicles</div>
      </div>
      <div class="bg-white/15 backdrop-blur rounded-xl px-4 py-2">
        <div class="text-lg font-bold leading-tight"><?= number_format($totalKm) ?></div>
        <div class="text-[11px] text-blue-100 uppercase tracking-wide">Total km</div>
      </div>
    </div>
    <button onclick="openVehicle(null)"
            class="bg-white text-indigo-700 hover:bg-blue-50 rounded-xl px-4 py-2.5 text-sm font-semibold shadow transition flex items-center gap-2">
      <i class="fa-solid fa-plus"></i> Register Vehicle
    </button>
  </div>
</div>

<!-- ── Filter toolbar ───────────────────────────────────── -->
<div class="vm-card p-4 mb-5">

  <form method="get" action="vehicles.php" class="flex flex-wrap gap-2">

    <input type="hidden" name="customer_id" value="<?= $customerId ?: '' ?>">

    <div class="vm-field flex-1 min-w-[220px]">
      <i class="fa-solid fa-search vm-icon"></i>
      <input name="search" value="<?= e($search) ?>"
             oninput="liveSearch(this.form)"
             placeholder="Search reg no, make, model, owner... (auto-searches)"
             class="vm-input pl-9">
    </div>

    <select name="customer_id" onchange="this.form.submit()" class="vm-input !w-auto">
      <option value="">All owners</option>
      <?php foreach ($customers as $c): ?>
      <option value="<?= $c['id'] ?>" <?= $customerId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <a href="vehicles.php?export=csv&search=<?= urlencode($search) ?>&customer_id=<?= $customerId ?>"
       class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl border border-slate-200 text-sm font-medium text-slate-600 hover:text-blue-600 hover:bg-slate-50 transition">
      <i class="fa-solid fa-file-csv"></i> CSV
    </a>

    <?php if ($search !== '' || $customerId): ?>
      <a href="vehicles.php" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-sm font-medium text-slate-500 hover:text-slate-700 transition">
        <i class="fa-solid fa-rotate-left"></i> Reset
      </a>
    <?php endif; ?>

  </form>

  <div class="flex justify-between items-center mt-3 text-xs text-slate-400">
    <p><?= $totalFiltered ?> vehicle(s) found<?= $search ? ' for &ldquo;' . e($search) . '&rdquo;' : '' ?></p>
    <?php if ($customerId): ?>
      <p><i class="fa-solid fa-user mr-1"></i>Filtered by owner</p>
    <?php endif; ?>
  </div>

</div>

<!-- ── Vehicle list ─────────────────────────────────────── -->
<div class="vm-card overflow-hidden">

  <div class="overflow-x-auto">
    <table class="responsive-table w-full text-sm">
      <thead>
        <tr class="text-left text-gray-500 border-b bg-gray-50/80">
          <th class="py-3 px-5 font-semibold uppercase text-[11px] tracking-wider">Reg No</th>
          <th class="py-3 px-3 font-semibold uppercase text-[11px] tracking-wider">Vehicle</th>
          <th class="py-3 px-3 font-semibold uppercase text-[11px] tracking-wider">Owner</th>
          <th class="py-3 px-3 font-semibold uppercase text-[11px] tracking-wider">Mileage</th>
          <th class="py-3 px-5 font-semibold uppercase text-[11px] tracking-wider text-right">Actions</th>
        </tr>
      </thead>
      <tbody>

      <?php foreach ($vehicles as $v):
          $vJson = htmlspecialchars(json_encode([
              'id' => (int)$v['id'], 'customer_id' => (int)$v['customer_id'],
              'reg_no' => $v['reg_no'], 'make' => $v['make'], 'model' => $v['model'],
              'year' => $v['year'], 'engine_no' => $v['engine_no'],
              'chassis_no' => $v['chassis_no'], 'mileage' => (int)$v['mileage'],
          ]), ENT_QUOTES, 'UTF-8');
      ?>
        <tr class="border-b last:border-0 hover:bg-slate-50/70 transition">

          <td class="py-3 px-5" data-label="Reg No">
            <span class="vm-badge"><i class="fa-solid fa-car"></i><?= e($v['reg_no']) ?></span>
          </td>

          <td class="py-3 px-3" data-label="Vehicle">
            <div class="font-semibold text-gray-800"><?= e($v['make']) ?> <?= e($v['model']) ?></div>
            <div class="text-xs text-gray-400"><?= e($v['year']) ?></div>
          </td>

          <td class="py-3 px-3" data-label="Owner">
            <span class="inline-flex items-center gap-1.5 text-gray-600">
              <span class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-600 inline-flex items-center justify-center text-[10px] font-bold">
                <?= e(strtoupper(mb_substr($v['customer'], 0, 1))) ?>
              </span>
              <?= e($v['customer']) ?>
            </span>
          </td>

          <td class="py-3 px-3 text-gray-600" data-label="Mileage"><?= number_format((int)$v['mileage']) ?> km</td>

          <td class="py-3 px-5 text-right whitespace-nowrap" data-label="Actions">
            <div class="inline-flex gap-1">
              <button onclick='openVehicle(<?= $vJson ?>)' title="Edit"
                      class="vm-action text-blue-600 bg-blue-50 hover:bg-blue-100"><i class="fa-solid fa-pen"></i></button>
              <a href="service_history.php?vehicle_id=<?= $v['id'] ?>" title="Service history"
                 class="vm-action text-indigo-600 bg-indigo-50 hover:bg-indigo-100"><i class="fa-solid fa-clock-rotate-left"></i></a>
              <a href="services.php?vehicle_id=<?= $v['id'] ?>" title="New service job"
                 class="vm-action text-emerald-600 bg-emerald-50 hover:bg-emerald-100"><i class="fa-solid fa-screwdriver-wrench"></i></a>
              <button onclick='confirmDelete(<?= (int)$v['id'] ?>, <?= json_encode($v['reg_no']) ?>)' title="Delete"
                      class="vm-action text-red-500 bg-red-50 hover:bg-red-100"><i class="fa-solid fa-trash"></i></button>
            </div>
          </td>

        </tr>
      <?php endforeach; ?>

      <?php if (!$vehicles): ?>
        <tr><td colspan="5" class="py-10 text-center">
          <i class="fa-solid fa-car-side text-3xl text-gray-200 mb-2"></i>
          <div class="text-gray-400"><?= $search || $customerId ? 'No vehicles match your filters.' : 'No vehicles registered yet.' ?></div>
        </td></tr>
      <?php endif; ?>

      </tbody>
    </table>
  </div>

  <!-- PAGINATION -->
  <?php if ($totalPages > 1):
      $qs = fn($p) => 'vehicles.php?search=' . urlencode($search) . '&customer_id=' . $customerId . '&page=' . $p;
      $win = range(max(1, $page - 2), min($totalPages, $page + 2));
  ?>
  <div class="px-5 py-3 border-t bg-gray-50/60 text-xs text-gray-400 flex items-center justify-between">

    <span><i class="fa-solid fa-list mr-1"></i> Page <?= $page ?> of <?= $totalPages ?></span>

    <div class="flex gap-1">
      <?php if ($page > 1): ?>
        <a href="<?= $qs($page - 1) ?>" class="px-2.5 py-1.5 rounded-lg border bg-white hover:bg-gray-50"><i class="fa-solid fa-chevron-left"></i></a>
      <?php endif;
      foreach ($win as $pn): ?>
        <a href="<?= $qs($pn) ?>" class="px-2.5 py-1.5 rounded-lg border <?= $pn === $page ? 'bg-blue-600 text-white border-blue-600' : 'bg-white hover:bg-gray-50' ?>"><?= $pn ?></a>
      <?php endforeach;
      if ($page < $totalPages): ?>
        <a href="<?= $qs($page + 1) ?>" class="px-2.5 py-1.5 rounded-lg border bg-white hover:bg-gray-50"><i class="fa-solid fa-chevron-right"></i></a>
      <?php endif; ?>
    </div>

  </div>
  <?php endif; ?>

</div>

<!-- ============ REGISTER / EDIT VEHICLE MODAL ============ -->
<div id="vehicleModal" class="modal-backdrop">
  <form method="post" id="vehicleForm" class="modal-card space-y-3" autocomplete="off">

    <div class="flex items-center justify-between">
      <h2 class="font-bold text-gray-800 flex items-center gap-2">
        <span id="vmIcon" class="w-8 h-8 rounded-lg bg-blue-100 text-blue-700 inline-flex items-center justify-center">
          <i class="fa-solid fa-plus"></i>
        </span>
        <span id="vmTitle">Register Vehicle</span>
      </h2>
      <button type="button" onclick="closeModal('vehicleModal')"
              class="w-8 h-8 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <input type="hidden" name="id" id="v_id">

    <div class="vm-field">
      <i class="fa-solid fa-user vm-icon"></i>
      <select name="customer_id" id="v_customer" required class="vm-input pl-9">
        <option value="">-- Owner (Customer) --</option>
        <?php foreach ($customers as $c): ?>
        <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="vm-field">
      <i class="fa-solid fa-id-card vm-icon"></i>
      <input name="reg_no" id="v_reg" required autofocus placeholder="Registration No (e.g. ABC-1234)"
             class="vm-input pl-9 uppercase font-semibold tracking-wide">
    </div>

    <div class="grid grid-cols-3 gap-2">
      <input name="make" id="v_make" placeholder="Make" class="vm-input" title="Make">
      <input name="model" id="v_model" placeholder="Model" class="vm-input" title="Model">
      <input name="year" id="v_year" placeholder="Year" class="vm-input" title="Year">
    </div>

    <div class="vm-field">
      <i class="fa-solid fa-gears vm-icon"></i>
      <input name="engine_no" id="v_engine" placeholder="Engine No" class="vm-input pl-9">
    </div>

    <div class="vm-field">
      <i class="fa-solid fa-barcode vm-icon"></i>
      <input name="chassis_no" id="v_chassis" placeholder="Chassis No" class="vm-input pl-9">
    </div>

    <div class="vm-field">
      <i class="fa-solid fa-gauge-high vm-icon"></i>
      <input name="mileage" id="v_mileage" type="number" min="0" placeholder="Current mileage (km)" class="vm-input pl-9">
    </div>

    <button id="vmSubmit"
            class="w-full text-white rounded-xl py-2.5 text-sm font-semibold shadow-md hover:shadow-lg transition-all bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700">
      <i class="fa-solid fa-floppy-disk mr-1"></i> <span id="vmSubmitLabel">Register Vehicle</span>
    </button>

  </form>
</div>

<!-- ============ DELETE CONFIRM MODAL ============ -->
<div id="deleteModal" class="modal-backdrop">
  <div class="modal-card !max-w-sm text-center space-y-4">

    <div class="w-14 h-14 mx-auto rounded-full bg-red-100 text-red-600 flex items-center justify-center text-2xl">
      <i class="fa-solid fa-trash"></i>
    </div>

    <div>
      <h3 class="font-bold text-gray-800 text-lg">Delete Vehicle?</h3>
      <p class="text-sm text-gray-500 mt-1">
        <span id="delReg" class="vm-badge !bg-red-50 !text-red-600"></span>
        will be permanently removed along with its service history. This cannot be undone.
      </p>
    </div>

    <div class="flex gap-2">
      <button onclick="closeModal('deleteModal')"
              class="flex-1 px-4 py-2.5 rounded-lg border text-sm text-gray-600 hover:bg-gray-50 transition">Cancel</button>
      <button onclick="doDelete()"
              class="flex-1 px-4 py-2.5 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-medium transition">
        <i class="fa-solid fa-trash mr-1"></i> Delete
      </button>
    </div>

  </div>
</div>

<?php page_footer(); ?>
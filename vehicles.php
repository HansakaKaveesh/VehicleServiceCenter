<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

if (isset($_GET['delete'])) {
    $pdo->prepare('DELETE FROM vehicles WHERE id = ?')->execute([(int)$_GET['delete']]);
    flash('Vehicle deleted.'); redirect('vehicles.php');
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
$where = '';
$params = [];
if (!empty($_GET['customer_id'])) { $where = 'WHERE v.customer_id = ?'; $params = [(int)$_GET['customer_id']]; }
if (!empty($_GET['search'])) { $where = 'WHERE v.reg_no LIKE ?'; $params = ['%' . trim($_GET['search']) . '%']; }
$st = $pdo->prepare("SELECT v.*, c.name AS customer FROM vehicles v JOIN customers c ON c.id = v.customer_id $where ORDER BY v.id DESC");
$st->execute($params);
$vehicles = $st->fetchAll();

$totalVehicles = $pdo->query('SELECT COUNT(*) FROM vehicles')->fetchColumn();
$totalKm = (int)$pdo->query('SELECT SUM(mileage) FROM vehicles')->fetchColumn();

page_header('Vehicle Management');
?>

<style>
  .vm-input { width:100%; border:1px solid #e2e8f0; border-radius:.65rem; padding:.55rem .85rem; font-size:.85rem;
              background:#f8fafc; transition:all .2s; color:#334155; }
  .vm-input:focus { outline:none; border-color:#3b82f6; background:#fff; box-shadow:0 0 0 3px rgba(59,130,246,.12); }
  .vm-icon { color:#94a3b8; position:absolute; left:.8rem; top:50%; transform:translateY(-50%); font-size:.8rem; }
  .vm-field { position:relative; }
  .vm-field .vm-input { padding-left:2.2rem; }
  .vm-row { transition:background .15s; }
  .vm-row:hover { background:#f8fafc; }
  .vm-badge { display:inline-flex; align-items:center; gap:.35rem; padding:.15rem .6rem; border-radius:999px;
              font-size:.72rem; font-weight:600; background:#eff6ff; color:#1d4ed8; }
  .vm-action { display:inline-flex; align-items:center; justify-content:center; width:2rem; height:2rem;
               border-radius:.55rem; transition:all .18s; }
  .vm-action:hover { transform:translateY(-2px); }
  .vm-card { background:#fff; border-radius:1.25rem; box-shadow:0 1px 3px rgba(15,23,42,.07), 0 8px 24px -12px rgba(15,23,42,.12); }
</style>

<!-- Page hero -->
<div class="rounded-2xl bg-gradient-to-r from-blue-700 via-indigo-700 to-violet-700 text-white px-6 py-5 mb-6 flex flex-wrap items-center justify-between gap-4 shadow-lg">
  <div>
    <h1 class="text-xl font-bold flex items-center gap-2"><i class="fa-solid fa-car-side"></i> Vehicle Management</h1>
    <p class="text-blue-100 text-sm mt-1">Register vehicles, track owners, and manage service history.</p>
  </div>
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
</div>

<div class="grid lg:grid-cols-3 gap-6">

  <!-- ── Registration form ─────────────────────────────── -->
  <div class="vm-card p-6 h-fit border-t-4 border-blue-600">
    <h2 class="font-bold text-gray-800 flex items-center gap-2 mb-4">
      <span class="w-8 h-8 rounded-lg bg-blue-100 text-blue-700 inline-flex items-center justify-center">
        <i class="fa-solid <?= $edit ? 'fa-pen' : 'fa-plus' ?>"></i>
      </span>
      <?= $edit ? 'Edit Vehicle' : 'Register Vehicle' ?>
    </h2>

    <form method="post" class="space-y-3">
      <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">

      <div class="vm-field">
        <i class="fa-solid fa-user vm-icon"></i>
        <select name="customer_id" required class="vm-input pl-9">
          <option value="">-- Owner (Customer) --</option>
          <?php foreach ($customers as $c): ?>
          <option value="<?= $c['id'] ?>" <?= ($edit['customer_id'] ?? $_GET['customer_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="vm-field">
        <i class="fa-solid fa-id-card vm-icon"></i>
        <input name="reg_no" required placeholder="Registration No (e.g. ABC-1234)" value="<?= e($edit['reg_no'] ?? '') ?>" class="vm-input pl-9 uppercase font-semibold tracking-wide">
      </div>

      <div class="grid grid-cols-3 gap-2">
        <input name="make" placeholder="Make" value="<?= e($edit['make'] ?? '') ?>" class="vm-input" title="Make">
        <input name="model" placeholder="Model" value="<?= e($edit['model'] ?? '') ?>" class="vm-input" title="Model">
        <input name="year" placeholder="Year" value="<?= e($edit['year'] ?? '') ?>" class="vm-input" title="Year">
      </div>

      <div class="vm-field">
        <i class="fa-solid fa-gears vm-icon"></i>
        <input name="engine_no" placeholder="Engine No" value="<?= e($edit['engine_no'] ?? '') ?>" class="vm-input pl-9">
      </div>

      <div class="vm-field">
        <i class="fa-solid fa-barcode vm-icon"></i>
        <input name="chassis_no" placeholder="Chassis No" value="<?= e($edit['chassis_no'] ?? '') ?>" class="vm-input pl-9">
      </div>

      <div class="vm-field">
        <i class="fa-solid fa-gauge-high vm-icon"></i>
        <input name="mileage" type="number" min="0" placeholder="Current mileage (km)" value="<?= e($edit['mileage'] ?? '') ?>" class="vm-input pl-9">
      </div>

      <button class="w-full bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-xl py-2.5 text-sm font-semibold shadow-md hover:shadow-lg transition-all">
        <i class="fa-solid fa-floppy-disk mr-1"></i> <?= $edit ? 'Update Vehicle' : 'Register Vehicle' ?>
      </button>

      <?php if ($edit): ?>
      <a href="vehicles.php" class="block text-center text-sm text-gray-500 hover:text-gray-700">
        <i class="fa-solid fa-xmark mr-1"></i> Cancel editing
      </a>
      <?php endif; ?>
    </form>
  </div>

  <!-- ── Vehicle list ──────────────────────────────────── -->
  <div class="lg:col-span-2 space-y-4">

    <div class="vm-card p-4 flex flex-wrap gap-2">
      <form method="get" class="flex gap-2 flex-1 min-w-[260px]">
        <div class="vm-field flex-1">
          <i class="fa-solid fa-search vm-icon"></i>
          <input name="search" value="<?= e($_GET['search'] ?? '') ?>" placeholder="Search by registration number..." class="vm-input pl-9">
        </div>
        <button class="bg-gray-800 hover:bg-gray-900 text-white px-5 rounded-xl text-sm font-medium transition-colors">
          <i class="fa-solid fa-search mr-1"></i> Search
        </button>
      </form>
      <form method="get" class="flex gap-2">
        <select name="customer_id" onchange="this.form.submit()" class="vm-input !w-auto">
          <option value="">All owners</option>
          <?php foreach ($customers as $c): ?>
          <option value="<?= $c['id'] ?>" <?= ($_GET['customer_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>

    <div class="vm-card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
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
          <?php foreach ($vehicles as $v): ?>
            <tr class="vm-row border-b last:border-0">
              <td class="py-3 px-5">
                <span class="vm-badge"><i class="fa-solid fa-car"></i><?= e($v['reg_no']) ?></span>
              </td>
              <td class="py-3 px-3">
                <div class="font-semibold text-gray-800"><?= e($v['make']) ?> <?= e($v['model']) ?></div>
                <div class="text-xs text-gray-400"><?= e($v['year']) ?></div>
              </td>
              <td class="py-3 px-3">
                <span class="inline-flex items-center gap-1.5 text-gray-600">
                  <span class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-600 inline-flex items-center justify-center text-[10px] font-bold">
                    <?= e(strtoupper(mb_substr($v['customer'], 0, 1))) ?>
                  </span>
                  <?= e($v['customer']) ?>
                </span>
              </td>
              <td class="py-3 px-3 text-gray-600"><?= number_format((int)$v['mileage']) ?> km</td>
              <td class="py-3 px-5 text-right whitespace-nowrap space-x-1">
                <a href="vehicles.php?edit=<?= $v['id'] ?>" title="Edit" class="vm-action text-blue-600 bg-blue-50 hover:bg-blue-100"><i class="fa-solid fa-pen"></i></a>
                <a href="service_history.php?vehicle_id=<?= $v['id'] ?>" title="Service history" class="vm-action text-indigo-600 bg-indigo-50 hover:bg-indigo-100"><i class="fa-solid fa-clock-rotate-left"></i></a>
                <a href="services.php?vehicle_id=<?= $v['id'] ?>" title="New service job" class="vm-action text-emerald-600 bg-emerald-50 hover:bg-emerald-100"><i class="fa-solid fa-screwdriver-wrench"></i></a>
                <a href="vehicles.php?delete=<?= $v['id'] ?>" onclick="return confirm('Delete this vehicle and its service history?')" title="Delete" class="vm-action text-red-500 bg-red-50 hover:bg-red-100"><i class="fa-solid fa-trash"></i></a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$vehicles): ?>
            <tr><td colspan="5" class="py-10 text-center">
              <i class="fa-solid fa-car-side text-3xl text-gray-200 mb-2"></i>
              <div class="text-gray-400">No vehicles found.</div>
            </td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($vehicles): ?>
      <div class="px-5 py-3 border-t bg-gray-50/60 text-xs text-gray-400 flex justify-between">
        <span><i class="fa-solid fa-list mr-1"></i> <?= count($vehicles) ?> vehicle(s) shown</span>
        <span>Ordered by newest first</span>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php page_footer(); ?>
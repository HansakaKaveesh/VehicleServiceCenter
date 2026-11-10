<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

const UPLOAD_DIR = __DIR__ . '/uploads/services/';
const MAX_PDF_SIZE = 5 * 1024 * 1024; // 5 MB

// Helper: validate + save the uploaded PDF, returns the stored filename or null
function save_report_pdf(array $file, int $service_id): ?string {
    if (empty($file['name']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) {
        flash('Upload failed (error code ' . $file['error'] . ').', 'error');
        redirect('services.php');
    }
    if ($file['size'] > MAX_PDF_SIZE) {
        flash('PDF is too large. Maximum size is 5 MB.', 'error');
        redirect('services.php');
    }
    // Verify it's really a PDF (check extension AND content)
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if ($ext !== 'pdf' || $mime !== 'application/pdf') {
        flash('Only PDF files are allowed.', 'error');
        redirect('services.php');
    }
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $filename = 'service_' . $service_id . '_' . bin2hex(random_bytes(6)) . '.pdf';
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $filename)) {
        flash('Could not save the file. Check folder permissions.', 'error');
        redirect('services.php');
    }
    return $filename;
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // Optionally remove the PDF file too
    $st = $pdo->prepare('SELECT report_path FROM services WHERE id = ?');
    $st->execute([$id]);
    if ($row = $st->fetch()) {
        if ($row['report_path'] && file_exists(UPLOAD_DIR . $row['report_path'])) {
            unlink(UPLOAD_DIR . $row['report_path']);
        }
    }
    $pdo->prepare('DELETE FROM services WHERE id = ?')->execute([$id]);
    flash('Service record deleted.'); redirect('services.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $data = [(int)$_POST['vehicle_id'], $_POST['service_date'], (int)$_POST['mileage'], trim($_POST['description']),
             (float)$_POST['labor_charge'], $_POST['status'], $_POST['next_service_date'] ?: null,
             $_POST['next_service_mileage'] !== '' ? (int)$_POST['next_service_mileage'] : null, trim($_POST['notes'])];
    if ($id) {
        $pdo->prepare('UPDATE services SET vehicle_id=?, service_date=?, mileage=?, description=?, labor_charge=?, status=?, next_service_date=?, next_service_mileage=?, notes=? WHERE id=?')->execute([...$data, $id]);
        $service_id = $id;
        flash('Service record updated.');
    } else {
        $pdo->prepare('INSERT INTO services (vehicle_id, service_date, mileage, description, labor_charge, status, next_service_date, next_service_mileage, notes) VALUES (?,?,?,?,?,?,?,?,?)')->execute($data);
        $service_id = (int)$pdo->lastInsertId();
        flash('Service job created.');
    }

    // Save technicians for this service
    $pdo->prepare('DELETE FROM service_technicians WHERE service_id = ?')->execute([$service_id]);
    if (!empty($_POST['technician_ids']) && is_array($_POST['technician_ids'])) {
        $stmt = $pdo->prepare('INSERT IGNORE INTO service_technicians (service_id, technician_id) VALUES (?,?)');
        foreach ($_POST['technician_ids'] as $tid) {
            $stmt->execute([$service_id, (int)$tid]);
        }
    }

    // Handle PDF upload
    $pdf = save_report_pdf($_FILES['report'] ?? [], $service_id);
    if ($pdf) {
        // Remove old file if replacing
        $st = $pdo->prepare('SELECT report_path FROM services WHERE id = ?');
        $st->execute([$service_id]);
        if ($old = $st->fetch()['report_path'] ?? null) {
            if ($old !== $pdf && file_exists(UPLOAD_DIR . $old)) unlink(UPLOAD_DIR . $old);
        }
        $pdo->prepare('UPDATE services SET report_path = ? WHERE id = ?')->execute([$pdf, $service_id]);
    }

    // Sync vehicle mileage
    if (!empty($_POST['mileage'])) {
        $pdo->prepare('UPDATE vehicles SET mileage = GREATEST(mileage, ?) WHERE id = ?')->execute([(int)$_POST['mileage'], (int)$_POST['vehicle_id']]);
    }
    redirect('services.php');
}

$vehicles = $pdo->query('SELECT v.id, v.reg_no, v.make, v.model, c.name AS customer FROM vehicles v JOIN customers c ON c.id=v.customer_id ORDER BY v.reg_no')->fetchAll();
$technicians = $pdo->query('SELECT id, name, specialty FROM technicians WHERE active = 1 ORDER BY name')->fetchAll();

$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM services WHERE id=?'); $st->execute([(int)$_GET['edit']]); $edit = $st->fetch();
    $st = $pdo->prepare('SELECT technician_id FROM service_technicians WHERE service_id=?');
    $st->execute([(int)$_GET['edit']]);
    $edit['technician_ids'] = array_column($st->fetchAll(), 'technician_id');
}

$status_filter = $_GET['status'] ?? '';
$where = $status_filter ? "WHERE s.status = " . $pdo->quote($status_filter) : '';
$services = $pdo->query("SELECT s.*, v.reg_no, v.make, v.model, c.name AS customer,
    (SELECT GROUP_CONCAT(t.name SEPARATOR ', ') FROM service_technicians st JOIN technicians t ON t.id = st.technician_id WHERE st.service_id = s.id) AS tech_names
    FROM services s JOIN vehicles v ON v.id=s.vehicle_id JOIN customers c ON c.id=v.customer_id $where
    ORDER BY s.service_date DESC, s.id DESC LIMIT 100")->fetchAll();

// Header stats
$stats = ['Pending' => 0, 'In Progress' => 0, 'Completed' => 0];
$statColors = ['Pending' => 'text-gray-700', 'In Progress' => 'text-amber-600', 'Completed' => 'text-emerald-600'];
$statIcons  = ['Pending' => 'fa-clock', 'In Progress' => 'fa-spinner', 'Completed' => 'fa-circle-check'];
$st = $pdo->query("SELECT status, COUNT(*) AS n FROM services GROUP BY status");
foreach ($st->fetchAll() as $r) { if (isset($stats[$r['status']])) $stats[$r['status']] = (int)$r['n']; }

$statusStyles = [
    'Completed'   => 'bg-emerald-100 text-emerald-700',
    'In Progress' => 'bg-amber-100 text-amber-700',
];
page_header('Service Management');
?>

<style>
  .sm-input { width:100%; border:1px solid #e2e8f0; border-radius:.65rem; padding:.55rem .85rem; font-size:.85rem;
              background:#f8fafc; transition:all .2s; color:#334155; }
  .sm-input:focus { outline:none; border-color:#3b82f6; background:#fff; box-shadow:0 0 0 3px rgba(59,130,246,.12); }
  .sm-field { position:relative; }
  .sm-icon { color:#94a3b8; position:absolute; left:.8rem; top:50%; transform:translateY(-50%); font-size:.8rem; }
  .sm-field .sm-input { padding-left:2.2rem; }
  .sm-row { transition:background .15s; }
  .sm-row:hover { background:#f8fafc; }
  .sm-action { display:inline-flex; align-items:center; justify-content:center; width:2rem; height:2rem;
               border-radius:.55rem; transition:all .18s; }
  .sm-action:hover { transform:translateY(-2px); }
  .sm-badge { display:inline-flex; align-items:center; gap:.35rem; padding:.15rem .6rem; border-radius:999px;
              font-size:.72rem; font-weight:600; background:#eff6ff; color:#1d4ed8; }
  .sm-card { background:#fff; border-radius:1.25rem;
             box-shadow:0 1px 3px rgba(15,23,42,.07), 0 8px 24px -12px rgba(15,23,42,.12); }
  .sm-tech { display:inline-flex; align-items:center; gap:.3rem; background:#f1f5f9; border-radius:999px;
             padding:.1rem .55rem; font-size:.7rem; color:#475569; }
</style>

<!-- Page hero -->
<div class="rounded-2xl bg-gradient-to-r from-emerald-700 via-teal-700 to-cyan-700 text-white px-6 py-5 mb-6 flex flex-wrap items-center justify-between gap-4 shadow-lg">
  <div>
    <h1 class="text-xl font-bold flex items-center gap-2"><i class="fa-solid fa-screwdriver-wrench"></i> Service Management</h1>
    <p class="text-emerald-100 text-sm mt-1">Create and track service jobs, assign technicians, and attach reports.</p>
  </div>
  <div class="flex gap-3 text-center">
    <?php foreach (['Pending','In Progress','Completed'] as $k): ?>
    <div class="bg-white/15 backdrop-blur rounded-xl px-4 py-2">
      <div class="text-lg font-bold leading-tight <?= $statColors[$k] ?> bg-white/90 rounded-md px-2"><?= $stats[$k] ?></div>
      <div class="text-[11px] text-emerald-100 uppercase tracking-wide mt-1"><?= $k ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="grid lg:grid-cols-3 gap-6">

  <!-- ── Service job form ──────────────────────────────── -->
  <div class="sm-card p-6 h-fit border-t-4 border-teal-600">
    <h2 class="font-bold text-gray-800 flex items-center gap-2 mb-4">
      <span class="w-8 h-8 rounded-lg bg-teal-100 text-teal-700 inline-flex items-center justify-center">
        <i class="fa-solid <?= $edit ? 'fa-pen' : 'fa-plus' ?>"></i>
      </span>
      <?= $edit ? 'Edit Service Job' : 'Create Service Job' ?>
    </h2>

    <form method="post" enctype="multipart/form-data" class="space-y-3">
      <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
      <input type="hidden" name="MAX_FILE_SIZE" value="5242880">

      <div class="sm-field">
        <i class="fa-solid fa-car vm-icon sm-icon"></i>
        <select name="vehicle_id" required class="sm-input pl-9">
          <option value="">-- Select Vehicle --</option>
          <?php foreach ($vehicles as $v): ?>
          <option value="<?= $v['id'] ?>" <?= ($edit['vehicle_id'] ?? $_GET['vehicle_id'] ?? '') == $v['id'] ? 'selected' : '' ?>><?= e($v['reg_no']) ?> - <?= e($v['make']) ?> <?= e($v['model']) ?> (<?= e($v['customer']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="grid grid-cols-2 gap-2">
        <div class="sm-field">
          <i class="fa-regular fa-calendar sm-icon"></i>
          <input name="service_date" type="date" required value="<?= e($edit['service_date'] ?? date('Y-m-d')) ?>" class="sm-input pl-9">
        </div>
        <div class="sm-field">
          <i class="fa-solid fa-gauge-high sm-icon"></i>
          <input name="mileage" type="number" min="0" placeholder="Mileage (km)" value="<?= e($edit['mileage'] ?? '') ?>" class="sm-input pl-9">
        </div>
      </div>

      <textarea name="description" required placeholder="Inspection / repairs performed..." class="sm-input" rows="3"><?= e($edit['description'] ?? '') ?></textarea>

      <div class="grid grid-cols-2 gap-2">
        <div class="sm-field">
          <i class="fa-solid fa-money-bill-wave sm-icon"></i>
          <input name="labor_charge" type="number" step="0.01" min="0" placeholder="Labor charge" value="<?= e($edit['labor_charge'] ?? '') ?>" class="sm-input pl-9">
        </div>
        <div class="sm-field">
          <i class="fa-solid fa-flag sm-icon"></i>
          <select name="status" class="sm-input pl-9">
            <?php foreach (['Pending','In Progress','Completed'] as $s): ?>
            <option <?= ($edit['status'] ?? 'Pending') === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Technicians -->
      <div>
        <label class="flex items-center gap-1.5 text-xs font-semibold text-gray-500 mb-1.5">
          <i class="fa-solid fa-users-gear"></i> Technicians
        </label>
        <div class="sm-input !bg-white max-h-36 overflow-y-auto space-y-1.5 !py-2">
          <?php foreach ($technicians as $t): ?>
          <label class="flex items-center gap-2 hover:bg-gray-50 rounded px-1 cursor-pointer">
            <input type="checkbox" name="technician_ids[]" value="<?= $t['id'] ?>"
                   <?= in_array($t['id'], $edit['technician_ids'] ?? []) ? 'checked' : '' ?>
                   class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
            <span class="text-gray-700"><?= e($t['name']) ?></span>
            <?php if ($t['specialty']): ?>
            <span class="text-[10px] bg-teal-50 text-teal-600 px-1.5 py-0.5 rounded-full ml-auto"><?= e($t['specialty']) ?></span>
            <?php endif; ?>
          </label>
          <?php endforeach; ?>
          <?php if (!$technicians): ?>
          <p class="text-gray-400 text-xs">No technicians added yet.</p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Service report PDF -->
      <div>
        <label class="flex items-center gap-1.5 text-xs font-semibold text-gray-500 mb-1.5">
          <i class="fa-solid fa-file-pdf text-red-500"></i> Service Report (PDF, max 5 MB)
        </label>
        <input name="report" type="file" accept="application/pdf,.pdf"
               class="sm-input !bg-white file:mr-3 file:px-3 file:py-1.5 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-red-50 file:text-red-600 hover:file:bg-red-100">
        <?php if (!empty($edit['report_path'])): ?>
        <p class="mt-1.5 text-xs text-gray-500">
          <i class="fa-solid fa-file-pdf text-red-500 mr-1"></i>Current file:
          <a href="uploads/services/<?= e($edit['report_path']) ?>" target="_blank" class="text-blue-600 hover:underline"><?= e($edit['report_path']) ?></a>
          <span class="text-gray-400">— uploading a new file replaces it</span>
        </p>
        <?php endif; ?>
      </div>

      <div class="grid grid-cols-2 gap-2">
        <input name="next_service_date" type="date" title="Next service date" value="<?= e($edit['next_service_date'] ?? '') ?>" class="sm-input">
        <input name="next_service_mileage" type="number" min="0" placeholder="Next @ mileage" value="<?= e($edit['next_service_mileage'] ?? '') ?>" class="sm-input">
      </div>

      <textarea name="notes" placeholder="Service notes" class="sm-input" rows="2"><?= e($edit['notes'] ?? '') ?></textarea>

      <button class="w-full bg-gradient-to-r from-teal-600 to-cyan-600 hover:from-teal-700 hover:to-cyan-700 text-white rounded-xl py-2.5 text-sm font-semibold shadow-md hover:shadow-lg transition-all">
        <i class="fa-solid fa-floppy-disk mr-1"></i> <?= $edit ? 'Update Job' : 'Create Job' ?>
      </button>

      <?php if ($edit): ?>
      <a href="services.php" class="block text-center text-sm text-gray-500 hover:text-gray-700">
        <i class="fa-solid fa-xmark mr-1"></i> Cancel editing
      </a>
      <?php endif; ?>
    </form>
  </div>

  <!-- ── Service jobs table ────────────────────────────── -->
  <div class="lg:col-span-2 sm-card overflow-hidden">
    <div class="px-5 py-4 border-b flex flex-wrap items-center justify-between gap-3 bg-gray-50/60">
      <h2 class="font-bold text-gray-800 flex items-center gap-2">
        <span class="w-8 h-8 rounded-lg bg-teal-100 text-teal-700 inline-flex items-center justify-center"><i class="fa-solid fa-list-check"></i></span>
        Service Jobs
      </h2>
      <form method="get" class="flex items-center gap-2">
        <i class="fa-solid fa-filter text-gray-400 text-sm"></i>
        <select name="status" class="sm-input !w-auto !py-1.5" onchange="this.form.submit()">
          <option value="">All statuses</option>
          <?php foreach (['Pending','In Progress','Completed'] as $s): ?>
          <option <?= $status_filter === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-gray-500 border-b">
            <th class="py-3 px-5 font-semibold uppercase text-[11px] tracking-wider">Date</th>
            <th class="py-3 px-3 font-semibold uppercase text-[11px] tracking-wider">Vehicle</th>
            <th class="py-3 px-3 font-semibold uppercase text-[11px] tracking-wider">Customer</th>
            <th class="py-3 px-3 font-semibold uppercase text-[11px] tracking-wider">Technicians</th>
            <th class="py-3 px-3 font-semibold uppercase text-[11px] tracking-wider">Labor</th>
            <th class="py-3 px-3 font-semibold uppercase text-[11px] tracking-wider">Report</th>
            <th class="py-3 px-3 font-semibold uppercase text-[11px] tracking-wider">Status</th>
            <th class="py-3 px-5 font-semibold uppercase text-[11px] tracking-wider text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($services as $s): ?>
          <tr class="sm-row border-b last:border-0">
            <td class="py-3 px-5 whitespace-nowrap">
              <i class="fa-regular fa-calendar mr-1 text-gray-400"></i><span class="font-semibold text-gray-800"><?= e($s['service_date']) ?></span>
            </td>
            <td class="py-3 px-3">
              <span class="sm-badge"><i class="fa-solid fa-car"></i><?= e($s['reg_no']) ?></span>
              <span class="block text-xs text-gray-400 mt-0.5"><?= e($s['make']) ?> <?= e($s['model']) ?></span>
            </td>
            <td class="py-3 px-3 text-gray-600"><?= e($s['customer']) ?></td>
            <td class="py-3 px-3 text-gray-600 text-xs max-w-[140px]">
              <?php if ($s['tech_names']): ?>
                <?php foreach (array_slice(explode(', ', $s['tech_names']), 0, 2) as $tn): ?>
                <span class="sm-tech"><i class="fa-solid fa-user-wrench"></i><?= e($tn) ?></span>
                <?php endforeach; ?>
                <?php $extra = count(explode(', ', $s['tech_names'])) - 2; if ($extra > 0): ?>
                <span class="text-gray-400">+<?= $extra ?></span>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-gray-300">—</span>
              <?php endif; ?>
            </td>
            <td class="py-3 px-3 font-semibold text-gray-800"><?= money($s['labor_charge']) ?></td>
            <td class="py-3 px-3">
              <?php if (!empty($s['report_path'])): ?>
              <a href="uploads/services/<?= e($s['report_path']) ?>" target="_blank" title="View service report" class="sm-action !w-7 !h-7 text-red-500 bg-red-50 hover:bg-red-100">
                <i class="fa-solid fa-file-pdf"></i>
              </a>
              <?php else: ?>
              <span class="text-gray-300">—</span>
              <?php endif; ?>
            </td>
            <td class="py-3 px-3">
              <span class="px-2.5 py-1 rounded-full text-xs font-semibold <?= $statusStyles[$s['status']] ?? 'bg-gray-100 text-gray-600' ?>">
                <i class="fa-solid <?= $statIcons[$s['status']] ?? 'fa-circle' ?> mr-1"></i><?= e($s['status']) ?>
              </span>
            </td>
            <td class="py-3 px-5 text-right whitespace-nowrap space-x-1">
              <a href="services.php?edit=<?= $s['id'] ?>" title="Edit" class="sm-action text-blue-600 bg-blue-50 hover:bg-blue-100"><i class="fa-solid fa-pen"></i></a>
              <a href="invoice_form.php?service_id=<?= $s['id'] ?>" title="Generate invoice" class="sm-action text-emerald-600 bg-emerald-50 hover:bg-emerald-100"><i class="fa-solid fa-file-invoice"></i></a>
              <a href="services.php?delete=<?= $s['id'] ?>" onclick="return confirm('Delete this service record?')" title="Delete" class="sm-action text-red-500 bg-red-50 hover:bg-red-100"><i class="fa-solid fa-trash"></i></a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$services): ?>
          <tr><td colspan="8" class="py-10 text-center">
            <i class="fa-solid fa-clipboard-list text-3xl text-gray-200 mb-2"></i>
            <div class="text-gray-400">No service jobs found.</div>
          </td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($services): ?>
    <div class="px-5 py-3 border-t bg-gray-50/60 text-xs text-gray-400 flex justify-between">
      <span><i class="fa-solid fa-list mr-1"></i> Showing latest <?= count($services) ?> job(s)</span>
      <span>Ordered by newest first</span>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php page_footer(); ?>
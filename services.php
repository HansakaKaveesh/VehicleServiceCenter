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

    try {
        if ($id) {
            $pdo->prepare('UPDATE services SET vehicle_id=?, service_date=?, mileage=?, description=?, labor_charge=?, status=?, next_service_date=?, next_service_mileage=?, notes=? WHERE id=?')->execute([...$data, $id]);
            $service_id = $id;
            flash('Service record updated.');
        } else {
            $pdo->prepare('INSERT INTO services (vehicle_id, service_date, mileage, description, labor_charge, status, next_service_date, next_service_mileage, notes) VALUES (?,?,?,?,?,?,?,?,?)')->execute($data);
            $service_id = (int)$pdo->lastInsertId();
            flash('Service job created.');
        }
    } catch (PDOException $ex) {
        flash('Error saving service job. Please try again.', 'error');
        redirect('services.php');
    }

    $pdo->prepare('DELETE FROM service_technicians WHERE service_id = ?')->execute([$service_id]);
    if (!empty($_POST['technician_ids']) && is_array($_POST['technician_ids'])) {
        $stmt = $pdo->prepare('INSERT IGNORE INTO service_technicians (service_id, technician_id) VALUES (?,?)');
        foreach ($_POST['technician_ids'] as $tid) {
            $stmt->execute([$service_id, (int)$tid]);
        }
    }

    $pdf = save_report_pdf($_FILES['report'] ?? [], $service_id);
    if ($pdf) {
        $st = $pdo->prepare('SELECT report_path FROM services WHERE id = ?');
        $st->execute([$service_id]);
        if ($old = $st->fetch()['report_path'] ?? null) {
            if ($old !== $pdf && file_exists(UPLOAD_DIR . $old)) unlink(UPLOAD_DIR . $old);
        }
        $pdo->prepare('UPDATE services SET report_path = ? WHERE id = ?')->execute([$pdf, $service_id]);
    }

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
    if ($edit) {
        $st = $pdo->prepare('SELECT technician_id FROM service_technicians WHERE service_id=?');
        $st->execute([(int)$_GET['edit']]);
        $edit['technician_ids'] = array_map('intval', array_column($st->fetchAll(), 'technician_id'));
    }
}

// ------------------------------------------------------------------
// SEARCH / FILTER / PAGINATION / CSV
// ------------------------------------------------------------------
$search        = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = 12;

$conds  = [];
$params = [];
if ($status_filter !== '') { $conds[] = 's.status = ?'; $params[] = $status_filter; }
if ($search !== '') {
    $kw = "%$search%";
    $conds[] = '(v.reg_no LIKE ? OR v.make LIKE ? OR v.model LIKE ? OR c.name LIKE ? OR s.description LIKE ?)';
    array_push($params, $kw, $kw, $kw, $kw, $kw);
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$from = "FROM services s JOIN vehicles v ON v.id=s.vehicle_id JOIN customers c ON c.id=v.customer_id $where";

if (($_GET['export'] ?? '') === 'csv') {
    $stmt = $pdo->prepare("SELECT s.service_date, v.reg_no, v.make, v.model, c.name AS customer,
            s.description, s.labor_charge, s.status, s.next_service_date, s.next_service_mileage,
            (SELECT GROUP_CONCAT(t.name SEPARATOR ', ') FROM service_technicians st JOIN technicians t ON t.id = st.technician_id WHERE st.service_id = s.id) AS techs
            $from ORDER BY s.service_date DESC, s.id DESC");
    $stmt->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="services_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Reg No', 'Make', 'Model', 'Customer', 'Description', 'Labor', 'Status', 'Next Date', 'Next @ km', 'Technicians']);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) fputcsv($out, $row);
    fclose($out);
    exit;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) $from");
$countStmt->execute($params);
$totalFiltered = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$services = $pdo->prepare("SELECT s.*, v.reg_no, v.make, v.model, c.name AS customer,
    (SELECT GROUP_CONCAT(t.name SEPARATOR ', ') FROM service_technicians st JOIN technicians t ON t.id = st.technician_id WHERE st.service_id = s.id) AS tech_names
    $from ORDER BY s.service_date DESC, s.id DESC LIMIT $perPage OFFSET $offset");
$services->execute($params);
$services = $services->fetchAll();

// Header stats
$stats = ['Pending' => 0, 'In Progress' => 0, 'Completed' => 0];
$st = $pdo->query("SELECT status, COUNT(*) AS n FROM services GROUP BY status");
foreach ($st->fetchAll() as $r) { if (isset($stats[$r['status']])) $stats[$r['status']] = (int)$r['n']; }

$statusStyles = [
    'Completed'   => 'bg-emerald-100 text-emerald-700',
    'In Progress' => 'bg-amber-100 text-amber-700',
];
$statIcons = ['Pending' => 'fa-clock', 'In Progress' => 'fa-spinner', 'Completed' => 'fa-circle-check'];

// Modal auto-open state
$openJob  = (bool)$edit || isset($_GET['vehicle_id']) || isset($_GET['add']);
$prefillVehicle = (int)($edit['vehicle_id'] ?? $_GET['vehicle_id'] ?? 0);

page_header('Service Management');
?>

<style>
  .sm-input { width:100%; border:1px solid #e2e8f0; border-radius:.65rem; padding:.55rem .85rem; font-size:.85rem;
              background:#f8fafc; transition:all .2s; color:#334155; }
  .sm-input:focus { outline:none; border-color:#14b8a6; background:#fff; box-shadow:0 0 0 3px rgba(20,184,166,.12); }
  .sm-field { position:relative; }
  .sm-icon { color:#94a3b8; position:absolute; left:.8rem; top:50%; transform:translateY(-50%); font-size:.8rem; }
  .sm-field .sm-input { padding-left:2.2rem; }
  .sm-badge { display:inline-flex; align-items:center; gap:.35rem; padding:.15rem .6rem; border-radius:999px;
              font-size:.72rem; font-weight:600; background:#eff6ff; color:#1d4ed8; }
  .sm-action { display:inline-flex; align-items:center; justify-content:center; width:2rem; height:2rem;
               border-radius:.55rem; transition:all .18s; }
  .sm-action:hover { transform:translateY(-2px); }
  .sm-card { background:#fff; border-radius:1.25rem;
             box-shadow:0 1px 3px rgba(15,23,42,.07), 0 8px 24px -12px rgba(15,23,42,.12); }
  .sm-tech { display:inline-flex; align-items:center; gap:.3rem; background:#f1f5f9; border-radius:999px;
             padding:.1rem .55rem; font-size:.7rem; color:#475569; }

  /* ---------- Modals ---------- */
  .modal-backdrop {
      position: fixed; inset: 0; background: rgba(15,23,42,.55);
      backdrop-filter: blur(2px); z-index: 50;
      display: flex; align-items: center; justify-content: center;
      padding: 1rem; opacity: 0; pointer-events: none; transition: opacity .18s ease;
  }
  .modal-backdrop.open { opacity: 1; pointer-events: auto; }
  .modal-card {
      background: #fff; border-radius: 1.25rem; width: 100%; max-width: 36rem;
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

// ---------- Service job modal ----------
function openJob(j) {
    const f = document.getElementById('jobForm');
    f.reset();

    document.getElementById('j_id').value       = j?.id || '';
    document.getElementById('j_vehicle').value  = j?.vehicle_id || '';
    document.getElementById('j_date').value     = j?.service_date || new Date().toISOString().slice(0, 10);
    document.getElementById('j_mileage').value  = j?.mileage || '';
    document.getElementById('j_desc').value     = j?.description || '';
    document.getElementById('j_labor').value    = j?.labor_charge || '';
    document.getElementById('j_status').value   = j?.status || 'Pending';
    document.getElementById('j_next_date').value    = j?.next_service_date || '';
    document.getElementById('j_next_km').value      = j?.next_service_mileage || '';
    document.getElementById('j_notes').value    = j?.notes || '';
    document.getElementById('j_report').value   = '';

    // Technicians checkboxes
    const ids = j?.technician_ids || [];
    document.querySelectorAll('input[name="technician_ids[]"]').forEach(cb => {
        cb.checked = ids.includes(+cb.value);
    });

    // Current PDF hint
    document.getElementById('jCurrentPdf').innerHTML = (j?.report_path)
        ? `<i class="fa-solid fa-file-pdf text-red-500 mr-1"></i>Current file:
           <a href="uploads/services/${j.report_path}" target="_blank" class="text-blue-600 hover:underline">${j.report_path}</a>
           <span class="text-gray-400">— uploading replaces it</span>`
        : '';

    const editing = !!(j && j.id);
    document.getElementById('jmTitle').textContent       = editing ? 'Edit Service Job' : 'Create Service Job';
    document.getElementById('jmSubmitLabel').textContent = editing ? 'Update Job' : 'Create Job';
    document.querySelector('#jmIcon i').className = editing ? 'fa-solid fa-pen' : 'fa-solid fa-plus';
    document.getElementById('jmSubmit').className =
        'w-full text-white rounded-xl py-2.5 text-sm font-semibold shadow-md hover:shadow-lg transition-all ' +
        (editing ? 'bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600'
                 : 'bg-gradient-to-r from-teal-600 to-cyan-600 hover:from-teal-700 hover:to-cyan-700');

    openModal('jobModal');
}

// ---------- Delete confirmation ----------
let deleteTarget = '';
function confirmDelete(id, label) {
    deleteTarget = id;
    document.getElementById('delLabel').textContent = label;
    openModal('deleteModal');
}
function doDelete() {
    window.location.href = 'services.php?delete=' + deleteTarget;
}

// ---------- Auto-open modal ----------
document.addEventListener('DOMContentLoaded', () => {
    <?php if ($openJob): ?>
        openJob(<?= json_encode($edit ? [
            'id' => (int)$edit['id'], 'vehicle_id' => (int)$edit['vehicle_id'],
            'service_date' => $edit['service_date'], 'mileage' => (int)$edit['mileage'],
            'description' => $edit['description'], 'labor_charge' => (float)$edit['labor_charge'],
            'status' => $edit['status'], 'next_service_date' => $edit['next_service_date'],
            'next_service_mileage' => $edit['next_service_mileage'] !== null ? (int)$edit['next_service_mileage'] : '',
            'notes' => $edit['notes'], 'report_path' => $edit['report_path'],
            'technician_ids' => $edit['technician_ids'] ?? [],
        ] : ['vehicle_id' => $prefillVehicle]) ?>);
    <?php endif; ?>
});
</script>

<!-- Page hero -->
<div class="rounded-2xl bg-gradient-to-r from-emerald-700 via-teal-700 to-cyan-700 text-white px-6 py-5 mb-6 flex flex-wrap items-center justify-between gap-4 shadow-lg">
  <div>
    <h1 class="text-xl font-bold flex items-center gap-2"><i class="fa-solid fa-screwdriver-wrench"></i> Service Management</h1>
    <p class="text-emerald-100 text-sm mt-1">Create and track service jobs, assign technicians, and attach reports.</p>
  </div>
  <div class="flex gap-3 items-center">
    <div class="flex gap-3 text-center">
      <?php foreach (['Pending','In Progress','Completed'] as $k): ?>
      <div class="bg-white/15 backdrop-blur rounded-xl px-4 py-2">
        <div class="text-lg font-bold leading-tight <?= $k === 'Pending' ? 'text-gray-700' : ($k === 'In Progress' ? 'text-amber-600' : 'text-emerald-600') ?> bg-white/90 rounded-md px-2"><?= $stats[$k] ?></div>
        <div class="text-[11px] text-emerald-100 uppercase tracking-wide mt-1"><?= $k ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <button onclick="openJob(null)"
            class="bg-white text-teal-700 hover:bg-emerald-50 rounded-xl px-4 py-2.5 text-sm font-semibold shadow transition flex items-center gap-2">
      <i class="fa-solid fa-plus"></i> New Job
    </button>
  </div>
</div>

<!-- ── Filter toolbar ───────────────────────────────────── -->
<div class="sm-card p-4 mb-5">

  <form method="get" action="services.php" class="flex flex-wrap gap-2 items-center">

    <input type="hidden" name="status" value="<?= e($status_filter) ?>">

    <div class="sm-field flex-1 min-w-[220px]">
      <i class="fa-solid fa-search sm-icon"></i>
      <input name="search" value="<?= e($search) ?>"
             oninput="liveSearch(this.form)"
             placeholder="Search reg no, vehicle, customer, work done... (auto-searches)"
             class="sm-input pl-9">
    </div>

    <select name="status" onchange="this.form.submit()" class="sm-input !w-auto">
      <option value="">All statuses</option>
      <?php foreach (['Pending','In Progress','Completed'] as $s): ?>
      <option <?= $status_filter === $s ? 'selected' : '' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>

    <a href="services.php?export=csv&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>"
       class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl border border-slate-200 text-sm font-medium text-slate-600 hover:text-teal-600 hover:bg-slate-50 transition">
      <i class="fa-solid fa-file-csv"></i> CSV
    </a>

    <?php if ($search !== '' || $status_filter !== ''): ?>
      <a href="services.php" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-sm font-medium text-slate-500 hover:text-slate-700 transition">
        <i class="fa-solid fa-rotate-left"></i> Reset
      </a>
    <?php endif; ?>

  </form>

  <div class="flex justify-between items-center mt-3 text-xs text-slate-400">
    <p><?= $totalFiltered ?> job(s) found<?= $search ? ' for &ldquo;' . e($search) . '&rdquo;' : '' ?></p>
    <?php if ($status_filter): ?>
      <p><i class="fa-solid fa-filter mr-1"></i>Filtered: <?= e($status_filter) ?></p>
    <?php endif; ?>
  </div>

</div>

<!-- ── Service jobs table ───────────────────────────────── -->
<div class="sm-card overflow-hidden">

  <div class="overflow-x-auto">
    <table class="responsive-table w-full text-sm">
      <thead>
        <tr class="text-left text-gray-500 border-b bg-gray-50/80">
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

      <?php foreach ($services as $s):
          $sJson = htmlspecialchars(json_encode([
              'id' => (int)$s['id'], 'vehicle_id' => (int)$s['vehicle_id'],
              'service_date' => $s['service_date'], 'mileage' => (int)$s['mileage'],
              'description' => $s['description'], 'labor_charge' => (float)$s['labor_charge'],
              'status' => $s['status'], 'next_service_date' => $s['next_service_date'],
              'next_service_mileage' => $s['next_service_mileage'] !== null ? (int)$s['next_service_mileage'] : '',
              'notes' => $s['notes'], 'report_path' => $s['report_path'],
          ]), ENT_QUOTES, 'UTF-8');
          $techList = $s['tech_names'] ? explode(', ', $s['tech_names']) : [];
      ?>
        <tr class="border-b last:border-0 hover:bg-slate-50/70 transition">

          <td class="py-3 px-5 whitespace-nowrap" data-label="Date">
            <i class="fa-regular fa-calendar mr-1 text-gray-400"></i><span class="font-semibold text-gray-800"><?= e($s['service_date']) ?></span>
          </td>

          <td class="py-3 px-3" data-label="Vehicle">
            <span class="sm-badge"><i class="fa-solid fa-car"></i><?= e($s['reg_no']) ?></span>
            <span class="block text-xs text-gray-400 mt-0.5"><?= e($s['make']) ?> <?= e($s['model']) ?></span>
          </td>

          <td class="py-3 px-3 text-gray-600" data-label="Customer"><?= e($s['customer']) ?></td>

          <td class="py-3 px-3 text-gray-600 text-xs max-w-[140px]" data-label="Technicians">
            <?php if ($techList): ?>
              <?php foreach (array_slice($techList, 0, 2) as $tn): ?>
              <span class="sm-tech"><i class="fa-solid fa-user-wrench"></i><?= e($tn) ?></span>
              <?php endforeach; ?>
              <?php $extra = count($techList) - 2; if ($extra > 0): ?>
              <span class="text-gray-400">+<?= $extra ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="text-gray-300">—</span>
            <?php endif; ?>
          </td>

          <td class="py-3 px-3 font-semibold text-gray-800" data-label="Labor"><?= money($s['labor_charge']) ?></td>

          <td class="py-3 px-3" data-label="Report">
            <?php if (!empty($s['report_path'])): ?>
            <a href="uploads/services/<?= e($s['report_path']) ?>" target="_blank" title="View service report" class="sm-action !w-7 !h-7 text-red-500 bg-red-50 hover:bg-red-100">
              <i class="fa-solid fa-file-pdf"></i>
            </a>
            <?php else: ?>
            <span class="text-gray-300">—</span>
            <?php endif; ?>
          </td>

          <td class="py-3 px-3" data-label="Status">
            <span class="px-2.5 py-1 rounded-full text-xs font-semibold <?= $statusStyles[$s['status']] ?? 'bg-gray-100 text-gray-600' ?>">
              <i class="fa-solid <?= $statIcons[$s['status']] ?? 'fa-circle' ?> mr-1"></i><?= e($s['status']) ?>
            </span>
          </td>

          <td class="py-3 px-5 text-right whitespace-nowrap" data-label="Actions">
            <div class="inline-flex gap-1">
              <button onclick='openJob(<?= $sJson ?>)' title="Edit"
                      class="sm-action text-blue-600 bg-blue-50 hover:bg-blue-100"><i class="fa-solid fa-pen"></i></button>
              <a href="invoice_form.php?service_id=<?= $s['id'] ?>" title="Generate invoice"
                 class="sm-action text-emerald-600 bg-emerald-50 hover:bg-emerald-100"><i class="fa-solid fa-file-invoice"></i></a>
              <button onclick='confirmDelete(<?= (int)$s['id'] ?>, <?= json_encode($s['reg_no'] . ' · ' . $s['service_date']) ?>)' title="Delete"
                      class="sm-action text-red-500 bg-red-50 hover:bg-red-100"><i class="fa-solid fa-trash"></i></button>
            </div>
          </td>

        </tr>
      <?php endforeach; ?>

      <?php if (!$services): ?>
        <tr><td colspan="8" class="py-10 text-center">
          <i class="fa-solid fa-clipboard-list text-3xl text-gray-200 mb-2"></i>
          <div class="text-gray-400"><?= $search || $status_filter ? 'No jobs match your filters.' : 'No service jobs yet.' ?></div>
        </td></tr>
      <?php endif; ?>

      </tbody>
    </table>
  </div>

  <!-- PAGINATION -->
  <?php if ($totalPages > 1):
      $qs = fn($p) => 'services.php?search=' . urlencode($search) . '&status=' . urlencode($status_filter) . '&page=' . $p;
      $win = range(max(1, $page - 2), min($totalPages, $page + 2));
  ?>
  <div class="px-5 py-3 border-t bg-gray-50/60 text-xs text-gray-400 flex items-center justify-between">

    <span><i class="fa-solid fa-list mr-1"></i> Page <?= $page ?> of <?= $totalPages ?></span>

    <div class="flex gap-1">
      <?php if ($page > 1): ?>
        <a href="<?= $qs($page - 1) ?>" class="px-2.5 py-1.5 rounded-lg border bg-white hover:bg-gray-50"><i class="fa-solid fa-chevron-left"></i></a>
      <?php endif;
      foreach ($win as $pn): ?>
        <a href="<?= $qs($pn) ?>" class="px-2.5 py-1.5 rounded-lg border <?= $pn === $page ? 'bg-teal-600 text-white border-teal-600' : 'bg-white hover:bg-gray-50' ?>"><?= $pn ?></a>
      <?php endforeach;
      if ($page < $totalPages): ?>
        <a href="<?= $qs($page + 1) ?>" class="px-2.5 py-1.5 rounded-lg border bg-white hover:bg-gray-50"><i class="fa-solid fa-chevron-right"></i></a>
      <?php endif; ?>
    </div>

  </div>
  <?php endif; ?>

</div>

<!-- ============ CREATE / EDIT SERVICE JOB MODAL ============ -->
<div id="jobModal" class="modal-backdrop">
  <form method="post" id="jobForm" class="modal-card space-y-3" enctype="multipart/form-data" autocomplete="off">

    <div class="flex items-center justify-between">
      <h2 class="font-bold text-gray-800 flex items-center gap-2">
        <span id="jmIcon" class="w-8 h-8 rounded-lg bg-teal-100 text-teal-700 inline-flex items-center justify-center">
          <i class="fa-solid fa-plus"></i>
        </span>
        <span id="jmTitle">Create Service Job</span>
      </h2>
      <button type="button" onclick="closeModal('jobModal')"
              class="w-8 h-8 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <input type="hidden" name="id" id="j_id">
    <input type="hidden" name="MAX_FILE_SIZE" value="5242880">

    <div class="sm-field">
      <i class="fa-solid fa-car sm-icon"></i>
      <select name="vehicle_id" id="j_vehicle" required class="sm-input pl-9" autofocus>
        <option value="">-- Select Vehicle --</option>
        <?php foreach ($vehicles as $v): ?>
        <option value="<?= $v['id'] ?>"><?= e($v['reg_no']) ?> - <?= e($v['make']) ?> <?= e($v['model']) ?> (<?= e($v['customer']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="grid grid-cols-2 gap-2">
      <div class="sm-field">
        <i class="fa-regular fa-calendar sm-icon"></i>
        <input name="service_date" id="j_date" type="date" required class="sm-input pl-9">
      </div>
      <div class="sm-field">
        <i class="fa-solid fa-gauge-high sm-icon"></i>
        <input name="mileage" id="j_mileage" type="number" min="0" placeholder="Mileage (km)" class="sm-input pl-9">
      </div>
    </div>

    <textarea name="description" id="j_desc" required placeholder="Inspection / repairs performed..." class="sm-input" rows="3"></textarea>

    <div class="grid grid-cols-2 gap-2">
      <div class="sm-field">
        <i class="fa-solid fa-money-bill-wave sm-icon"></i>
        <input name="labor_charge" id="j_labor" type="number" step="0.01" min="0" placeholder="Labor charge" class="sm-input pl-9">
      </div>
      <div class="sm-field">
        <i class="fa-solid fa-flag sm-icon"></i>
        <select name="status" id="j_status" class="sm-input pl-9">
          <?php foreach (['Pending','In Progress','Completed'] as $s): ?>
          <option><?= $s ?></option>
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
      <input name="report" id="j_report" type="file" accept="application/pdf,.pdf"
             class="sm-input !bg-white file:mr-3 file:px-3 file:py-1.5 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-red-50 file:text-red-600 hover:file:bg-red-100">
      <p id="jCurrentPdf" class="mt-1.5 text-xs text-gray-500"></p>
    </div>

    <div class="grid grid-cols-2 gap-2">
      <input name="next_service_date" id="j_next_date" type="date" title="Next service date" class="sm-input">
      <input name="next_service_mileage" id="j_next_km" type="number" min="0" placeholder="Next @ mileage" class="sm-input">
    </div>

    <textarea name="notes" id="j_notes" placeholder="Service notes" class="sm-input" rows="2"></textarea>

    <button id="jmSubmit"
            class="w-full text-white rounded-xl py-2.5 text-sm font-semibold shadow-md hover:shadow-lg transition-all bg-gradient-to-r from-teal-600 to-cyan-600 hover:from-teal-700 hover:to-cyan-700">
      <i class="fa-solid fa-floppy-disk mr-1"></i> <span id="jmSubmitLabel">Create Job</span>
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
      <h3 class="font-bold text-gray-800 text-lg">Delete Service Record?</h3>
      <p class="text-sm text-gray-500 mt-1">
        <span id="delLabel" class="font-medium text-gray-700"></span>
        will be permanently deleted, including any attached PDF report. This cannot be undone.
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
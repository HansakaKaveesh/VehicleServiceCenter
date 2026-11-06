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
page_header('Service Management');
?>
<div class="grid lg:grid-cols-3 gap-6">
  <form method="post" enctype="multipart/form-data" class="bg-white rounded-2xl shadow p-5 space-y-3 h-fit">
    <h2 class="font-bold text-gray-700"><?= $edit ? 'Edit Service Job' : 'Create Service Job' ?></h2>
    <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
    <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
    <select name="vehicle_id" required class="w-full border rounded-lg px-3 py-2 text-sm bg-white">
      <option value="">-- Select Vehicle --</option>
      <?php foreach ($vehicles as $v): ?>
      <option value="<?= $v['id'] ?>" <?= ($edit['vehicle_id'] ?? $_GET['vehicle_id'] ?? '') == $v['id'] ? 'selected' : '' ?>><?= e($v['reg_no']) ?> - <?= e($v['make']) ?> <?= e($v['model']) ?> (<?= e($v['customer']) ?>)</option>
      <?php endforeach; ?>
    </select>
    <div class="grid grid-cols-2 gap-2">
      <input name="service_date" type="date" required value="<?= e($edit['service_date'] ?? date('Y-m-d')) ?>" class="border rounded-lg px-3 py-2 text-sm">
      <input name="mileage" type="number" min="0" placeholder="Mileage (km)" value="<?= e($edit['mileage'] ?? '') ?>" class="border rounded-lg px-3 py-2 text-sm">
    </div>
    <textarea name="description" required placeholder="Inspection / repairs performed..." class="w-full border rounded-lg px-3 py-2 text-sm" rows="3"><?= e($edit['description'] ?? '') ?></textarea>
    <input name="labor_charge" type="number" step="0.01" min="0" placeholder="Labor charge" value="<?= e($edit['labor_charge'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
    <select name="status" class="w-full border rounded-lg px-3 py-2 text-sm bg-white">
      <?php foreach (['Pending','In Progress','Completed'] as $s): ?>
      <option <?= ($edit['status'] ?? 'Pending') === $s ? 'selected' : '' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>

    <!-- Technicians -->
    <div>
      <label class="block text-xs font-semibold text-gray-500 mb-1.5">Technicians</label>
      <div class="w-full border rounded-lg px-3 py-2 text-sm bg-white max-h-36 overflow-y-auto space-y-1.5">
        <?php foreach ($technicians as $t): ?>
        <label class="flex items-center gap-2 hover:bg-gray-50 rounded px-1 cursor-pointer">
          <input type="checkbox" name="technician_ids[]" value="<?= $t['id'] ?>"
                 <?= in_array($t['id'], $edit['technician_ids'] ?? []) ? 'checked' : '' ?>
                 class="rounded border-gray-300 text-blue-600">
          <span class="text-gray-700"><?= e($t['name']) ?></span>
          <?php if ($t['specialty']): ?>
          <span class="text-[10px] bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded-full ml-auto"><?= e($t['specialty']) ?></span>
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
      <label class="block text-xs font-semibold text-gray-500 mb-1.5">Service Report (PDF, max 5 MB)</label>
      <input name="report" type="file" accept="application/pdf,.pdf"
             class="w-full border rounded-lg px-3 py-2 text-sm bg-white file:mr-3 file:px-3 file:py-1 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-600 hover:file:bg-blue-100">
      <?php if (!empty($edit['report_path'])): ?>
      <p class="mt-1.5 text-xs">
        <i class="fa-solid fa-file-pdf text-red-500 mr-1"></i>Current file:
        <a href="uploads/services/<?= e($edit['report_path']) ?>" target="_blank" class="text-blue-600 hover:underline"><?= e($edit['report_path']) ?></a>
        <span class="text-gray-400">— uploading a new file replaces it</span>
      </p>
      <?php endif; ?>
    </div>

    <div class="grid grid-cols-2 gap-2">
      <input name="next_service_date" type="date" title="Next service date" value="<?= e($edit['next_service_date'] ?? '') ?>" class="border rounded-lg px-3 py-2 text-sm">
      <input name="next_service_mileage" type="number" min="0" placeholder="Next @ mileage" value="<?= e($edit['next_service_mileage'] ?? '') ?>" class="border rounded-lg px-3 py-2 text-sm">
    </div>
    <textarea name="notes" placeholder="Service notes" class="w-full border rounded-lg px-3 py-2 text-sm" rows="2"><?= e($edit['notes'] ?? '') ?></textarea>
    <button class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-lg py-2 text-sm font-medium"><i class="fa-solid fa-floppy-disk mr-1"></i> <?= $edit ? 'Update' : 'Create Job' ?></button>
  </form>
  <div class="lg:col-span-2 bg-white rounded-2xl shadow p-5 overflow-x-auto">
    <div class="flex items-center justify-between mb-3">
      <h2 class="font-bold text-gray-700">Service Jobs</h2>
      <form method="get" class="flex gap-2">
        <select name="status" class="border rounded-lg px-3 py-1.5 text-sm bg-white" onchange="this.form.submit()">
          <option value="">All statuses</option>
          <?php foreach (['Pending','In Progress','Completed'] as $s): ?>
          <option <?= $status_filter === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b">
        <th class="py-2">Date</th><th>Vehicle</th><th>Customer</th><th>Technicians</th><th>Labor</th><th>Report</th><th>Status</th><th class="text-right">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($services as $s): ?>
        <tr class="border-b hover:bg-gray-50">
          <td class="py-2"><?= e($s['service_date']) ?></td>
          <td class="font-medium text-blue-700"><?= e($s['reg_no']) ?> <span class="text-gray-500 font-normal"><?= e($s['make']) ?> <?= e($s['model']) ?></span></td>
          <td><?= e($s['customer']) ?></td>
          <td class="text-gray-600 text-xs"><?= e($s['tech_names'] ?: '—') ?></td>
          <td><?= money($s['labor_charge']) ?></td>
          <td>
            <?php if (!empty($s['report_path'])): ?>
            <a href="uploads/services/<?= e($s['report_path']) ?>" target="_blank" title="View service report" class="inline-flex items-center justify-center w-7 h-7 rounded-lg text-red-500 hover:bg-red-50 transition">
              <i class="fa-solid fa-file-pdf"></i>
            </a>
            <?php else: ?>
            <span class="text-gray-300">—</span>
            <?php endif; ?>
          </td>
          <td><span class="px-2 py-0.5 rounded-full text-xs <?= $s['status']==='Completed'?'bg-green-100 text-green-700':($s['status']==='In Progress'?'bg-yellow-100 text-yellow-700':'bg-gray-100 text-gray-600') ?>"><?= $s['status'] ?></span></td>
          <td class="text-right space-x-2 whitespace-nowrap">
            <a href="services.php?edit=<?= $s['id'] ?>" class="text-blue-600"><i class="fa-solid fa-pen"></i></a>
            <a href="invoice_form.php?service_id=<?= $s['id'] ?>" class="text-emerald-600" title="Generate invoice"><i class="fa-solid fa-file-invoice"></i></a>
            <a href="services.php?delete=<?= $s['id'] ?>" onclick="return confirm('Delete this service record?')" class="text-red-600"><i class="fa-solid fa-trash"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$services): ?><tr><td colspan="8" class="py-6 text-center text-gray-400">No service jobs found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer(); ?>
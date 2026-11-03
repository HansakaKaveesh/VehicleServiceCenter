<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

if (isset($_GET['delete'])) {
    $pdo->prepare('DELETE FROM services WHERE id = ?')->execute([(int)$_GET['delete']]);
    flash('Service record deleted.'); redirect('services.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $data = [(int)$_POST['vehicle_id'], $_POST['service_date'], (int)$_POST['mileage'], trim($_POST['description']),
             (float)$_POST['labor_charge'], $_POST['status'], $_POST['next_service_date'] ?: null,
             $_POST['next_service_mileage'] !== '' ? (int)$_POST['next_service_mileage'] : null, trim($_POST['notes'])];
    if ($id) {
        $pdo->prepare('UPDATE services SET vehicle_id=?, service_date=?, mileage=?, description=?, labor_charge=?, status=?, next_service_date=?, next_service_mileage=?, notes=? WHERE id=?')->execute([...$data, $id]);
        flash('Service record updated.');
    } else {
        $pdo->prepare('INSERT INTO services (vehicle_id, service_date, mileage, description, labor_charge, status, next_service_date, next_service_mileage, notes) VALUES (?,?,?,?,?,?,?,?,?)')->execute($data);
        flash('Service job created.');
    }
    // Sync vehicle mileage
    if (!empty($_POST['mileage'])) {
        $pdo->prepare('UPDATE vehicles SET mileage = GREATEST(mileage, ?) WHERE id = ?')->execute([(int)$_POST['mileage'], (int)$_POST['vehicle_id']]);
    }
    redirect('services.php');
}

$vehicles = $pdo->query('SELECT v.id, v.reg_no, v.make, v.model, c.name AS customer FROM vehicles v JOIN customers c ON c.id=v.customer_id ORDER BY v.reg_no')->fetchAll();
$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM services WHERE id=?'); $st->execute([(int)$_GET['edit']]); $edit = $st->fetch();
}
$status_filter = $_GET['status'] ?? '';
$where = $status_filter ? "WHERE s.status = " . $pdo->quote($status_filter) : '';
$services = $pdo->query("SELECT s.*, v.reg_no, v.make, v.model, c.name AS customer FROM services s JOIN vehicles v ON v.id=s.vehicle_id JOIN customers c ON c.id=v.customer_id $where ORDER BY s.service_date DESC, s.id DESC LIMIT 100")->fetchAll();
page_header('Service Management');
?>
<div class="grid lg:grid-cols-3 gap-6">
  <form method="post" class="bg-white rounded-2xl shadow p-5 space-y-3 h-fit">
    <h2 class="font-bold text-gray-700"><?= $edit ? 'Edit Service Job' : 'Create Service Job' ?></h2>
    <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
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
        <th class="py-2">Date</th><th>Vehicle</th><th>Customer</th><th>Labor</th><th>Status</th><th class="text-right">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($services as $s): ?>
        <tr class="border-b hover:bg-gray-50">
          <td class="py-2"><?= e($s['service_date']) ?></td>
          <td class="font-medium text-blue-700"><?= e($s['reg_no']) ?> <span class="text-gray-500 font-normal"><?= e($s['make']) ?> <?= e($s['model']) ?></span></td>
          <td><?= e($s['customer']) ?></td>
          <td><?= money($s['labor_charge']) ?></td>
          <td><span class="px-2 py-0.5 rounded-full text-xs <?= $s['status']==='Completed'?'bg-green-100 text-green-700':($s['status']==='In Progress'?'bg-yellow-100 text-yellow-700':'bg-gray-100 text-gray-600') ?>"><?= $s['status'] ?></span></td>
          <td class="text-right space-x-2 whitespace-nowrap">
            <a href="services.php?edit=<?= $s['id'] ?>" class="text-blue-600"><i class="fa-solid fa-pen"></i></a>
            <a href="invoice_form.php?service_id=<?= $s['id'] ?>" class="text-emerald-600" title="Generate invoice"><i class="fa-solid fa-file-invoice"></i></a>
            <a href="services.php?delete=<?= $s['id'] ?>" onclick="return confirm('Delete this service record?')" class="text-red-600"><i class="fa-solid fa-trash"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$services): ?><tr><td colspan="6" class="py-6 text-center text-gray-400">No service jobs found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer(); ?>

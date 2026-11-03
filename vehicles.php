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
page_header('Vehicle Management');
?>
<div class="grid lg:grid-cols-3 gap-6">
  <form method="post" class="bg-white rounded-2xl shadow p-5 space-y-3 h-fit">
    <h2 class="font-bold text-gray-700"><?= $edit ? 'Edit Vehicle' : 'Register Vehicle' ?></h2>
    <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
    <select name="customer_id" required class="w-full border rounded-lg px-3 py-2 text-sm bg-white">
      <option value="">-- Owner (Customer) --</option>
      <?php foreach ($customers as $c): ?>
      <option value="<?= $c['id'] ?>" <?= ($edit['customer_id'] ?? $_GET['customer_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <input name="reg_no" required placeholder="Registration No (e.g. ABC-1234)" value="<?= e($edit['reg_no'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm uppercase">
    <div class="grid grid-cols-3 gap-2">
      <input name="make" placeholder="Make" value="<?= e($edit['make'] ?? '') ?>" class="border rounded-lg px-3 py-2 text-sm">
      <input name="model" placeholder="Model" value="<?= e($edit['model'] ?? '') ?>" class="border rounded-lg px-3 py-2 text-sm">
      <input name="year" placeholder="Year" value="<?= e($edit['year'] ?? '') ?>" class="border rounded-lg px-3 py-2 text-sm">
    </div>
    <input name="engine_no" placeholder="Engine No" value="<?= e($edit['engine_no'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
    <input name="chassis_no" placeholder="Chassis No" value="<?= e($edit['chassis_no'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
    <input name="mileage" type="number" min="0" placeholder="Current mileage (km)" value="<?= e($edit['mileage'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
    <button class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-lg py-2 text-sm font-medium"><i class="fa-solid fa-floppy-disk mr-1"></i> <?= $edit ? 'Update' : 'Register' ?></button>
  </form>
  <div class="lg:col-span-2 space-y-4">
    <form method="get" class="flex gap-2">
      <input name="search" value="<?= e($_GET['search'] ?? '') ?>" placeholder="Search by registration number..." class="flex-1 border rounded-lg px-3 py-2 text-sm">
      <button class="bg-gray-800 text-white px-4 rounded-lg text-sm"><i class="fa-solid fa-search mr-1"></i> Search</button>
    </form>
    <div class="bg-white rounded-2xl shadow p-5 overflow-x-auto">
      <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b">
          <th class="py-2">Reg No</th><th>Vehicle</th><th>Owner</th><th>Mileage</th><th class="text-right">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($vehicles as $v): ?>
          <tr class="border-b hover:bg-gray-50">
            <td class="py-2 font-semibold text-blue-700"><?= e($v['reg_no']) ?></td>
            <td><?= e($v['make']) ?> <?= e($v['model']) ?> <?= e($v['year']) ?></td>
            <td><?= e($v['customer']) ?></td>
            <td><?= number_format((int)$v['mileage']) ?> km</td>
            <td class="text-right space-x-2 whitespace-nowrap">
              <a href="vehicles.php?edit=<?= $v['id'] ?>" class="text-blue-600"><i class="fa-solid fa-pen"></i></a>
              <a href="service_history.php?vehicle_id=<?= $v['id'] ?>" class="text-indigo-600" title="Service history"><i class="fa-solid fa-clock-rotate-left"></i></a>
              <a href="services.php?vehicle_id=<?= $v['id'] ?>" class="text-emerald-600" title="New service job"><i class="fa-solid fa-screwdriver-wrench"></i></a>
              <a href="vehicles.php?delete=<?= $v['id'] ?>" onclick="return confirm('Delete this vehicle and its service history?')" class="text-red-600"><i class="fa-solid fa-trash"></i></a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$vehicles): ?><tr><td colspan="5" class="py-6 text-center text-gray-400">No vehicles found.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php page_footer(); ?>

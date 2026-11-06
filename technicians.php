<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

if (isset($_GET['delete'])) {
    $pdo->prepare('DELETE FROM technicians WHERE id = ?')->execute([(int)$_GET['delete']]);
    flash('Technician deleted.');
    redirect('technicians.php');
}

if (isset($_GET['toggle'])) {
    $pdo->prepare('UPDATE technicians SET active = 1 - active WHERE id = ?')->execute([(int)$_GET['toggle']]);
    flash('Technician status updated.');
    redirect('technicians.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);
    $specialty = trim($_POST['specialty']);
    if ($id) {
        $pdo->prepare('UPDATE technicians SET name=?, phone=?, specialty=? WHERE id=?')
            ->execute([$name, $phone, $specialty, $id]);
        flash('Technician updated.');
    } else {
        $pdo->prepare('INSERT INTO technicians (name, phone, specialty) VALUES (?,?,?)')
            ->execute([$name, $phone, $specialty]);
        flash('Technician added.');
    }
    redirect('technicians.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM technicians WHERE id=?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch();
}

$technicians = $pdo->query('SELECT t.*,
    (SELECT COUNT(*) FROM service_technicians st WHERE st.technician_id = t.id) AS job_count
    FROM technicians t ORDER BY t.active DESC, t.name')->fetchAll();

page_header('Technician Management');
?>
<div class="grid lg:grid-cols-3 gap-6">
  <form method="post" class="bg-white rounded-2xl shadow p-5 space-y-3 h-fit">
    <h2 class="font-bold text-gray-700"><?= $edit ? 'Edit Technician' : 'Add Technician' ?></h2>
    <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
    <input name="name" required placeholder="Full name" value="<?= e($edit['name'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
    <input name="phone" placeholder="Phone number" value="<?= e($edit['phone'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
    <input name="specialty" placeholder="Specialty (e.g. Engine, Electrical)" value="<?= e($edit['specialty'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
    <button class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-lg py-2 text-sm font-medium">
      <i class="fa-solid fa-user-gear mr-1"></i> <?= $edit ? 'Update' : 'Add Technician' ?>
    </button>
  </form>

  <div class="lg:col-span-2 bg-white rounded-2xl shadow p-5 overflow-x-auto">
    <h2 class="font-bold text-gray-700 mb-3">Technicians</h2>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b">
        <th class="py-2">Name</th><th>Phone</th><th>Specialty</th><th>Jobs Done</th><th>Status</th><th class="text-right">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($technicians as $t): ?>
        <tr class="border-b hover:bg-gray-50">
          <td class="py-2 font-medium"><?= e($t['name']) ?></td>
          <td><?= e($t['phone'] ?: '—') ?></td>
          <td>
            <?php if ($t['specialty']): ?>
            <span class="px-2 py-0.5 rounded-full text-xs bg-indigo-50 text-indigo-700"><?= e($t['specialty']) ?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="text-gray-600"><?= (int)$t['job_count'] ?></td>
          <td>
            <a href="technicians.php?toggle=<?= $t['id'] ?>" title="Click to toggle">
              <span class="px-2 py-0.5 rounded-full text-xs <?= $t['active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
                <?= $t['active'] ? 'Active' : 'Inactive' ?>
              </span>
            </a>
          </td>
          <td class="text-right space-x-2">
            <a href="technicians.php?edit=<?= $t['id'] ?>" class="text-blue-600"><i class="fa-solid fa-pen"></i></a>
            <a href="technicians.php?delete=<?= $t['id'] ?>" onclick="return confirm('Delete this technician?')" class="text-red-600"><i class="fa-solid fa-trash"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$technicians): ?>
        <tr><td colspan="6" class="py-6 text-center text-gray-400">No technicians added yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer(); ?>
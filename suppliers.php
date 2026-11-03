<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

if (isset($_GET['delete'])) {
    $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([(int)$_GET['delete']]);
    flash('Supplier deleted.'); redirect('suppliers.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $data = [trim($_POST['name']), trim($_POST['contact_person']), trim($_POST['phone']), trim($_POST['email']), trim($_POST['address'])];
    if ($id) {
        $pdo->prepare('UPDATE suppliers SET name=?, contact_person=?, phone=?, email=?, address=? WHERE id=?')->execute([...$data, $id]);
        flash('Supplier updated.');
    } else {
        $pdo->prepare('INSERT INTO suppliers (name, contact_person, phone, email, address) VALUES (?,?,?,?,?)')->execute($data);
        flash('Supplier added.');
    }
    redirect('suppliers.php');
}
$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM suppliers WHERE id=?'); $st->execute([(int)$_GET['edit']]); $edit = $st->fetch();
}
$suppliers = $pdo->query('SELECT s.*, (SELECT COUNT(*) FROM parts p WHERE p.supplier_id=s.id) AS parts FROM suppliers s ORDER BY s.id DESC')->fetchAll();
page_header('Supplier Management');
?>
<div class="grid lg:grid-cols-3 gap-6">
  <form method="post" class="bg-white rounded-2xl shadow p-5 space-y-3 h-fit">
    <h2 class="font-bold text-gray-700"><?= $edit ? 'Edit Supplier' : 'Add Supplier' ?></h2>
    <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
    <input name="name" required placeholder="Company name" value="<?= e($edit['name'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
    <input name="contact_person" placeholder="Contact person" value="<?= e($edit['contact_person'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
    <input name="phone" placeholder="Phone" value="<?= e($edit['phone'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
    <input name="email" type="email" placeholder="Email" value="<?= e($edit['email'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
    <textarea name="address" placeholder="Address" class="w-full border rounded-lg px-3 py-2 text-sm"><?= e($edit['address'] ?? '') ?></textarea>
    <button class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-lg py-2 text-sm font-medium"><i class="fa-solid fa-floppy-disk mr-1"></i> <?= $edit ? 'Update' : 'Save' ?></button>
  </form>
  <div class="lg:col-span-2 bg-white rounded-2xl shadow p-5 overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b">
        <th class="py-2">Name</th><th>Contact</th><th>Phone</th><th>Email</th><th>Parts</th><th class="text-right">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($suppliers as $s): ?>
        <tr class="border-b hover:bg-gray-50">
          <td class="py-2 font-medium"><?= e($s['name']) ?></td>
          <td><?= e($s['contact_person']) ?></td>
          <td><?= e($s['phone']) ?></td>
          <td><?= e($s['email']) ?></td>
          <td><span class="bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full text-xs"><?= (int)$s['parts'] ?></span></td>
          <td class="text-right space-x-2">
            <a href="suppliers.php?edit=<?= $s['id'] ?>" class="text-blue-600"><i class="fa-solid fa-pen"></i></a>
            <a href="suppliers.php?delete=<?= $s['id'] ?>" onclick="return confirm('Delete this supplier?')" class="text-red-600"><i class="fa-solid fa-trash"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$suppliers): ?><tr><td colspan="6" class="py-6 text-center text-gray-400">No suppliers yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer(); ?>

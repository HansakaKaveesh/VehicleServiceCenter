<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

// --- CSRF protection ---
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$old = ['name' => '', 'contact_person' => '', 'phone' => '', 'email' => '', 'address' => ''];

// --- Delete (POST only) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        flash('Invalid request. Please try again.', 'error');
        redirect('suppliers.php');
    }
    $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([(int)$_POST['delete_id']]);
    flash('Supplier deleted.');
    redirect('suppliers.php');
}

// --- Add / Update ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        flash('Invalid request. Please try again.', 'error');
        redirect('suppliers.php');
    }
    $id   = (int)($_POST['id'] ?? 0);
    $old  = array_map('trim', [
        'name'           => $_POST['name'] ?? '',
        'contact_person' => $_POST['contact_person'] ?? '',
        'phone'          => $_POST['phone'] ?? '',
        'email'          => $_POST['email'] ?? '',
        'address'        => $_POST['address'] ?? '',
    ]);

    if ($old['name'] === '') {
        $errors[] = 'Company name is required.';
    }
    if ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (!$errors) {
        $data = [$old['name'], $old['contact_person'], $old['phone'], $old['email'], $old['address']];
        if ($id) {
            $pdo->prepare('UPDATE suppliers SET name=?, contact_person=?, phone=?, email=?, address=? WHERE id=?')
                ->execute([...$data, $id]);
            flash('Supplier updated.');
        } else {
            $pdo->prepare('INSERT INTO suppliers (name, contact_person, phone, email, address) VALUES (?,?,?,?,?)')
                ->execute($data);
            flash('Supplier added.');
        }
        redirect('suppliers.php');
    }
}

// --- Edit mode ---
$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM suppliers WHERE id=?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
    if ($edit) $old = $edit; // prefill form with existing data
}

$suppliers = $pdo->query('SELECT s.*, (SELECT COUNT(*) FROM parts p WHERE p.supplier_id=s.id) AS parts FROM suppliers s ORDER BY s.id DESC')->fetchAll();

page_header('Supplier Management');
?>
<div class="grid lg:grid-cols-3 gap-6 items-start">

  <!-- Form -->
  <form method="post" class="bg-white rounded-2xl shadow p-5 space-y-3 lg:sticky lg:top-6">
    <h2 class="font-bold text-gray-700"><?= $edit ? 'Edit Supplier' : 'Add Supplier' ?></h2>

    <?php foreach ($errors as $err): ?>
      <div class="bg-red-50 text-red-700 text-sm rounded-lg px-3 py-2 flex items-center gap-2">
        <i class="fa-solid fa-circle-exclamation"></i> <?= e($err) ?>
      </div>
    <?php endforeach; ?>

    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">

    <label class="block text-xs font-medium text-gray-500">Company name <span class="text-red-500">*</span></label>
    <input name="name" required value="<?= e($old['name']) ?>" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400 outline-none">

    <label class="block text-xs font-medium text-gray-500">Contact person</label>
    <input name="contact_person" value="<?= e($old['contact_person']) ?>" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400 outline-none">

    <label class="block text-xs font-medium text-gray-500">Phone</label>
    <input name="phone" type="tel" value="<?= e($old['phone']) ?>" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400 outline-none">

    <label class="block text-xs font-medium text-gray-500">Email</label>
    <input name="email" type="email" value="<?= e($old['email']) ?>" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400 outline-none">

    <label class="block text-xs font-medium text-gray-500">Address</label>
    <textarea name="address" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-300 focus:border-blue-400 outline-none"><?= e($old['address']) ?></textarea>

    <div class="flex gap-2">
      <button name="save" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white rounded-lg py-2 text-sm font-medium transition">
        <i class="fa-solid fa-floppy-disk mr-1"></i> <?= $edit ? 'Update' : 'Save' ?>
      </button>
      <?php if ($edit): ?>
        <a href="suppliers.php" class="bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-lg px-4 py-2 text-sm font-medium transition">Cancel</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- Table -->
  <div class="lg:col-span-2 bg-white rounded-2xl shadow p-5 overflow-x-auto">
    <div class="flex items-center justify-between mb-3">
      <h2 class="font-bold text-gray-700">Suppliers <span class="text-gray-400 font-normal text-sm">(<?= count($suppliers) ?>)</span></h2>
      <div class="relative">
        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
        <input id="search" placeholder="Search suppliers..." class="border rounded-lg pl-8 pr-3 py-1.5 text-sm w-56 focus:ring-2 focus:ring-blue-300 focus:border-blue-400 outline-none">
      </div>
    </div>

    <table class="w-full text-sm">
      <thead>
        <tr class="text-left text-gray-500 border-b bg-gray-50 sticky top-0">
          <th class="py-2 px-2 rounded-tl-lg">Name</th><th class="px-2">Contact</th><th class="px-2">Phone</th>
          <th class="px-2">Email</th><th class="px-2">Parts</th><th class="px-2 text-right rounded-tr-lg">Actions</th>
        </tr>
      </thead>
      <tbody id="supplierRows">
      <?php foreach ($suppliers as $s): ?>
        <tr class="border-b hover:bg-blue-50 transition" data-search="<?= e(strtolower($s['name'].' '.$s['contact_person'].' '.$s['phone'].' '.$s['email'])) ?>">
          <td class="py-2 px-2 font-medium"><?= e($s['name']) ?></td>
          <td class="px-2"><?= e($s['contact_person']) ?></td>
          <td class="px-2"><?= e($s['phone']) ?></td>
          <td class="px-2"><?= e($s['email']) ?></td>
          <td class="px-2"><span class="bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full text-xs"><?= (int)$s['parts'] ?></span></td>
          <td class="px-2 text-right space-x-2 whitespace-nowrap">
            <a href="suppliers.php?edit=<?= $s['id'] ?>" title="Edit" class="text-blue-600 hover:text-blue-800"><i class="fa-solid fa-pen"></i></a>
            <form method="post" class="inline" onsubmit="return confirm('Delete this supplier? This cannot be undone.');">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="delete_id" value="<?= (int)$s['id'] ?>">
              <button type="submit" title="Delete" class="text-red-600 hover:text-red-800"><i class="fa-solid fa-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div id="noResults" class="hidden py-6 text-center text-gray-400">No suppliers match your search.</div>
    <?php if (!$suppliers): ?>
      <div class="py-10 text-center text-gray-400">
        <i class="fa-solid fa-box-open text-3xl mb-2"></i>
        <p>No suppliers yet. Add your first one using the form.</p>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
// Live search filter
const search = document.getElementById('search');
const rows = document.querySelectorAll('#supplierRows tr');
const noResults = document.getElementById('noResults');
search?.addEventListener('input', () => {
  const q = search.value.toLowerCase().trim();
  let visible = 0;
  rows.forEach(r => {
    const show = r.dataset.search.includes(q);
    r.classList.toggle('hidden', !show);
    if (show) visible++;
  });
  noResults?.classList.toggle('hidden', visible > 0);
});
</script>
<?php page_footer(); ?>
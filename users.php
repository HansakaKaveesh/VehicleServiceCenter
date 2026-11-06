<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_admin();

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id !== (int)$_SESSION['user_id']) {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        flash('User deleted.');
    } else {
        flash('You cannot delete your own account.', 'error');
    }
    redirect('users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name']);
    $username = trim($_POST['username']);
    $role = in_array($_POST['role'] ?? '', ['admin', 'staff', 'stock_manager'], true) ? $_POST['role'] : 'staff';
    try {
        if ($id) {
            if (!empty($_POST['password'])) {
                $pdo->prepare('UPDATE users SET name=?, username=?, role=?, password=? WHERE id=?')
                    ->execute([$name, $username, $role, password_hash($_POST['password'], PASSWORD_DEFAULT), $id]);
            } else {
                $pdo->prepare('UPDATE users SET name=?, username=?, role=? WHERE id=?')->execute([$name, $username, $role, $id]);
            }
            flash('User updated.');
        } else {
            $pdo->prepare('INSERT INTO users (name, username, password, role) VALUES (?,?,?,?)')
                ->execute([$name, $username, password_hash($_POST['password'], PASSWORD_DEFAULT), $role]);
            flash('User created.');
        }
    } catch (PDOException $ex) {
        flash('Username already exists.', 'error');
    }
    redirect('users.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT id, name, username, role FROM users WHERE id=?'); $st->execute([(int)$_GET['edit']]); $edit = $st->fetch();
}
$users = $pdo->query('SELECT id, name, username, role, created_at FROM users ORDER BY id')->fetchAll();
page_header('User Management');
?>
<div class="grid lg:grid-cols-3 gap-6">
  <form method="post" class="bg-white rounded-2xl shadow p-5 space-y-3 h-fit">
    <h2 class="font-bold text-gray-700"><?= $edit ? 'Edit User' : 'Add User' ?></h2>
    <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
    <input name="name" required placeholder="Full name" value="<?= e($edit['name'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
    <input name="username" required placeholder="Username" value="<?= e($edit['username'] ?? '') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
    <select name="role" class="w-full border rounded-lg px-3 py-2 text-sm bg-white">
      <option value="staff" <?= ($edit['role'] ?? '') === 'staff' ? 'selected' : '' ?>>Staff</option>
      <option value="stock_manager" <?= ($edit['role'] ?? '') === 'stock_manager' ? 'selected' : '' ?>>Stock Manager</option>
      <option value="admin" <?= ($edit['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Administrator</option>
    </select>
    <input name="password" type="password" <?= $edit ? '' : 'required' ?> placeholder="<?= $edit ? 'New password (leave blank to keep)' : 'Password' ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
    <button class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-lg py-2 text-sm font-medium"><i class="fa-solid fa-user-plus mr-1"></i> <?= $edit ? 'Update' : 'Create User' ?></button>
  </form>
  <div class="lg:col-span-2 bg-white rounded-2xl shadow p-5 overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b"><th class="py-2">Name</th><th>Username</th><th>Role</th><th class="text-right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr class="border-b hover:bg-gray-50">
          <td class="py-2 font-medium"><?= e($u['name']) ?></td>
          <td><?= e($u['username']) ?></td>
          <td><span class="px-2 py-0.5 rounded-full text-xs <?= $u['role']==='admin' ? 'bg-purple-100 text-purple-700' : ($u['role']==='stock_manager' ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-600') ?>"><?= e($u['role']) ?></span></td>
          <td class="text-right space-x-2">
            <a href="users.php?edit=<?= $u['id'] ?>" class="text-blue-600"><i class="fa-solid fa-pen"></i></a>
            <?php if ($u['id'] != $_SESSION['user_id']): ?>
            <a href="users.php?delete=<?= $u['id'] ?>" onclick="return confirm('Delete this user?')" class="text-red-600"><i class="fa-solid fa-trash"></i></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer(); ?>
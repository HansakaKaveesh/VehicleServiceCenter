<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_admin();

$ROLE_LABELS = ['admin' => 'Administrator', 'stock_manager' => 'Stock Manager', 'staff' => 'Staff'];

/* ---------- Soft-delete with guardrails (kept as POST via form for safety) ---------- */
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id === (int)$_SESSION['user_id']) {
        flash('You cannot delete your own account.', 'error');
    } else {
        // protect the last remaining admin
        if ($pdo->query("SELECT COUNT(*) c FROM users WHERE role='admin'")->fetch()['c'] <= 1) {
            $st = $pdo->prepare("SELECT role FROM users WHERE id=?"); $st->execute([$id]);
            if ($st->fetch()['role'] === 'admin') {
                flash('Cannot delete the last administrator.', 'error');
                redirect('users.php');
            }
        }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        flash('User deleted.');
    }
    redirect('users.php');
}

/* ---------- Create / update with validation ---------- */
$errors = [];
$old = ['name' => '', 'username' => '', 'role' => 'staff'];   // repopulate form on failure

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id       = (int)($_POST['id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $role     = in_array($_POST['role'] ?? '', array_keys($ROLE_LABELS), true) ? $_POST['role'] : 'staff';
    $password = $_POST['password'] ?? '';

    $old = ['name' => $name, 'username' => $username, 'role' => $role];

    if ($name === '')                                   $errors[] = 'Full name is required.';
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) $errors[] = 'Username must be 3–32 characters (letters, numbers, _ . -).';
    if (!$id && $password === '')                       $errors[] = 'Password is required for new users.';
    if ($password !== '' && strlen($password) < 6)      $errors[] = 'Password must be at least 6 characters.';
    if ($password !== '' && !preg_match('/[a-zA-Z]/', $password)) $errors[] = 'Password must contain at least one letter.';

    // protect the last admin from being demoted
    if ($id && $id === (int)$_SESSION['user_id'] && $role !== 'admin') {
        $errors[] = 'You cannot remove your own admin role.';
    }
    if (!$errors && $id) {
        $st = $pdo->prepare('SELECT role FROM users WHERE id=?'); $st->execute([$id]);
        $cur = $st->fetch();
        if ($cur && $cur['role'] === 'admin' && $role !== 'admin'
            && $pdo->query("SELECT COUNT(*) c FROM users WHERE role='admin'")->fetch()['c'] <= 1) {
            $errors[] = 'Cannot demote the last administrator.';
        }
    }

    if (!$errors) {
        try {
            if ($id) {
                if ($password !== '') {
                    $pdo->prepare('UPDATE users SET name=?, username=?, role=?, password=? WHERE id=?')
                        ->execute([$name, $username, $role, password_hash($password, PASSWORD_DEFAULT), $id]);
                } else {
                    $pdo->prepare('UPDATE users SET name=?, username=?, role=? WHERE id=?')
                        ->execute([$name, $username, $role, $id]);
                }
                flash('User updated.');
            } else {
                $pdo->prepare('INSERT INTO users (name, username, password, role) VALUES (?,?,?,?)')
                    ->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT), $role]);
                flash('User created.');
            }
            redirect('users.php');
        } catch (PDOException $ex) {
            $errors[] = 'That username is already taken — try another.';
        }
    }
    // if we get here, fall through and render the form with $errors + $old
}

$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT id, name, username, role FROM users WHERE id=?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch();
    if ($edit) $old = ['name' => $edit['name'], 'username' => $edit['username'], 'role' => $edit['role']];
}
$users = $pdo->query('SELECT id, name, username, role, created_at FROM users ORDER BY id')->fetchAll();
page_header('User Management');

function roleBadge($role) {
    $map = [
        'admin'         => 'bg-purple-100 text-purple-700',
        'stock_manager' => 'bg-amber-100 text-amber-700',
        'staff'         => 'bg-gray-100 text-gray-600',
    ];
    $labels = ['admin' => 'Administrator', 'stock_manager' => 'Stock Manager', 'staff' => 'Staff'];
    $cls = $map[$role] ?? 'bg-gray-100 text-gray-600';
    $lbl = $labels[$role] ?? e($role);
    return '<span class="px-2 py-0.5 rounded-full text-xs font-medium ' . $cls . '">' . $lbl . '</span>';
}
?>
<style>
  #userForm input:focus, #userForm select:focus {
    border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.15); outline: none;
  }
  #userForm input.err { border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,.12); }
  #userTable tbody tr { transition: background .15s; }
  @keyframes fadeIn { from { opacity: 0; transform: translateY(3px); } to { opacity: 1; transform: none; } }
  #userTable tbody tr { animation: fadeIn .2s ease; }
</style>

<div class="grid lg:grid-cols-3 gap-6">
  <!-- ---------- form ---------- -->
  <form method="post" id="userForm" class="bg-white rounded-2xl shadow p-5 space-y-3 h-fit" novalidate>
    <div class="flex items-center justify-between">
      <h2 class="font-bold text-gray-700"><?= $edit ? 'Edit User' : 'Add User' ?></h2>
      <?php if ($edit): ?>
        <a href="users.php" class="text-xs text-blue-600 hover:underline"><i class="fa-solid fa-rotate-left mr-1"></i>Cancel edit</a>
      <?php endif; ?>
    </div>

    <?php if ($errors): ?>
      <div class="text-xs bg-red-50 text-red-700 rounded-lg px-3 py-2 space-y-1">
        <?php foreach ($errors as $er): ?>
          <p><i class="fa-solid fa-circle-exclamation mr-1"></i><?= e($er) ?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">

    <div>
      <label class="block text-xs font-medium text-gray-500 mb-1">Full name</label>
      <input name="name" required placeholder="e.g. Nimal Perera" value="<?= e($old['name']) ?>"
             class="w-full border rounded-lg px-3 py-2 text-sm">
    </div>

    <div>
      <label class="block text-xs font-medium text-gray-500 mb-1">Username</label>
      <input name="username" required placeholder="3–32 chars: letters, numbers, _ . -" value="<?= e($old['username']) ?>"
             class="w-full border rounded-lg px-3 py-2 text-sm" autocomplete="off">
    </div>

    <div>
      <label class="block text-xs font-medium text-gray-500 mb-1">Role</label>
      <select name="role" class="w-full border rounded-lg px-3 py-2 text-sm bg-white">
        <?php foreach ($ROLE_LABELS as $val => $lbl): ?>
          <option value="<?= $val ?>" <?= $old['role'] === $val ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
      <p class="text-[11px] text-gray-400 mt-1" id="roleHint"></p>
    </div>

    <div>
      <label class="block text-xs font-medium text-gray-500 mb-1">
        <?= $edit ? 'New password <span class="text-gray-400">(leave blank to keep current)</span>' : 'Password' ?>
      </label>
      <div class="relative">
        <input name="password" id="pwField" type="password" <?= $edit ? '' : 'required' ?>
               placeholder="<?= $edit ? 'Min 6 characters' : 'Min 6 characters' ?>"
               class="w-full border rounded-lg px-3 py-2 text-sm pr-10" autocomplete="new-password">
        <button type="button" onclick="togglePw()" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
          <i class="fa-solid fa-eye" id="pwEye"></i>
        </button>
      </div>
      <div class="flex gap-1 mt-2" id="pwMeter"></div>
      <p class="text-[11px] mt-1" id="pwMsg"></p>
    </div>

    <button id="saveBtn"
            class="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg py-2 text-sm font-medium transition">
      <i class="fa-solid <?= $edit ? 'fa-user-pen' : 'fa-user-plus' ?> mr-1"></i> <?= $edit ? 'Update User' : 'Create User' ?>
    </button>
  </form>

  <!-- ---------- table ---------- -->
  <div class="lg:col-span-2 bg-white rounded-2xl shadow p-5 overflow-x-auto">
    <div class="flex flex-col sm:flex-row sm:items-center gap-3 mb-3">
      <h2 class="font-bold text-gray-700">Users <span class="text-xs font-normal text-gray-400">(<?= count($users) ?>)</span></h2>
      <input id="userSearch" type="text" placeholder="🔍 Search name, username or role…"
             class="sm:ml-auto border rounded-lg px-3 py-1.5 text-sm w-full sm:w-64 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none"
             oninput="filterUsers(this.value)">
    </div>

    <table class="w-full text-sm" id="userTable">
      <thead><tr class="text-left text-gray-500 border-b">
        <th class="py-2 cursor-pointer select-none" onclick="sortTable(0)">Name <i class="fa-solid fa-sort text-[10px]"></i></th>
        <th class="cursor-pointer select-none" onclick="sortTable(1)">Username <i class="fa-solid fa-sort text-[10px]"></i></th>
        <th class="cursor-pointer select-none" onclick="sortTable(2)">Role <i class="fa-solid fa-sort text-[10px]"></i></th>
        <th>Added</th>
        <th class="text-right">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr class="border-b hover:bg-gray-50" data-search="<?= strtolower(e($u['name']) . '|' . e($u['username']) . '|' . e($u['role'])) ?>">
          <td class="py-2 font-medium"><?= e($u['name']) ?>
            <?php if ($u['id'] == $_SESSION['user_id']): ?>
              <span class="ml-1 text-[10px] bg-blue-50 text-blue-600 px-1.5 py-0.5 rounded">you</span>
            <?php endif; ?>
          </td>
          <td class="text-gray-600"><?= e($u['username']) ?></td>
          <td><?= roleBadge($u['role']) ?></td>
          <td class="text-gray-400 text-xs"><?= e(date('d M Y', strtotime($u['created_at']))) ?></td>
          <td class="text-right space-x-3 whitespace-nowrap">
            <a href="users.php?edit=<?= $u['id'] ?>" class="text-blue-600 hover:text-blue-800" title="Edit"><i class="fa-solid fa-pen"></i></a>
            <?php if ($u['id'] != $_SESSION['user_id']): ?>
            <a href="users.php?delete=<?= $u['id'] ?>"
               onclick="return confirmDelete(this, '<?= e($u['name']) ?>');"
               class="text-red-600 hover:text-red-800" title="Delete"><i class="fa-solid fa-trash"></i></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p id="noResults" class="hidden text-center text-sm text-gray-400 py-6">No users match your search.</p>
  </div>
</div>

<script>
/* ---------- show/hide password ---------- */
function togglePw() {
  const f = document.getElementById('pwField');
  const eye = document.getElementById('pwEye');
  const show = f.type === 'password';
  f.type = show ? 'text' : 'password';
  eye.className = 'fa-solid ' + (show ? 'fa-eye-slash' : 'fa-eye');
}

/* ---------- live password strength meter ---------- */
const pwField = document.getElementById('pwField');
pwField.addEventListener('input', function () {
  const v = this.value, meter = document.getElementById('pwMeter'), msg = document.getElementById('pwMsg');
  if (!v) { meter.innerHTML = ''; msg.textContent = ''; return; }
  let score = 0;
  if (v.length >= 6) score++;
  if (v.length >= 10) score++;
  if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
  if (/\d/.test(v)) score++;
  if (/[^a-zA-Z0-9]/.test(v)) score++;
  const colors = ['#ef4444', '#f97316', '#eab308', '#84cc16', '#22c55e'];
  const labels = ['Weak', 'Weak', 'Fair', 'Good', 'Strong', 'Very strong'];
  meter.innerHTML = Array.from({length: 5}, (_, i) =>
    `<span class="h-1.5 flex-1 rounded-full" style="background:${i < score ? colors[score-1] : '#e5e7eb'}"></span>`).join('');
  msg.textContent = labels[score];
  msg.style.color = colors[score-1];
});

/* ---------- role explanation hint ---------- */
const hints = {
  admin: 'Full access: settings, users, invoices, stock, reports.',
  stock_manager: 'Manage parts, suppliers, purchases and stock levels.',
  staff: 'Daily work: customers, vehicles, services and invoices.'
};
const roleSel = document.querySelector('#userForm select[name="role"]');
function updateRoleHint() { document.getElementById('roleHint').textContent = hints[roleSel.value] || ''; }
roleSel.addEventListener('change', updateRoleHint);
updateRoleHint();

/* ---------- friendly delete confirm ---------- */
function confirmDelete(link, name) {
  if (confirm(`Delete user "${name}"?\n\nThis cannot be undone.`)) {
    link.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i>';
    return true;
  }
  return false;
}

/* ---------- live search ---------- */
function filterUsers(q) {
  q = q.toLowerCase().trim();
  let visible = 0;
  document.querySelectorAll('#userTable tbody tr').forEach(tr => {
    const show = !q || tr.dataset.search.includes(q);
    tr.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  document.getElementById('noResults').classList.toggle('hidden', visible > 0);
}

/* ---------- sortable columns ---------- */
let sortDir = 1;
function sortTable(col) {
  const tbody = document.querySelector('#userTable tbody');
  const rows = [...tbody.rows];
  rows.sort((a, b) => {
    const x = a.cells[col].textContent.trim().toLowerCase();
    const y = b.cells[col].textContent.trim().toLowerCase();
    return (x < y ? -1 : x > y ? 1 : 0) * sortDir;
  });
  sortDir *= -1;
  rows.forEach(r => tbody.appendChild(r));
}

/* ---------- client-side validation + submit UX ---------- */
document.getElementById('userForm').addEventListener('submit', function (e) {
  let bad = false;
  const name = this.name, user = this.username, pw = this.password;
  [name, user, pw].forEach(f => f.classList.remove('err'));

  if (!name.value.trim()) { name.classList.add('err'); bad = true; }
  if (!/^[a-zA-Z0-9_.-]{3,32}$/.test(user.value.trim())) { user.classList.add('err'); bad = true; }
  if (pw.required && pw.value.length < 6) { pw.classList.add('err'); bad = true; }
  if (!pw.required && pw.value !== '' && pw.value.length < 6) { pw.classList.add('err'); bad = true; }

  if (bad) {
    e.preventDefault();
    (document.querySelector('#userForm .err') || name).focus();
    return;
  }
  const btn = document.getElementById('saveBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Saving…';
});
</script>
<?php page_footer(); ?>
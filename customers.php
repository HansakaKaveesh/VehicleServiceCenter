<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

/*
|--------------------------------------------------------------------------
| DELETE CUSTOMER
|--------------------------------------------------------------------------
*/
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    try {
        $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
        flash('Customer deleted.');
    } catch (PDOException $ex) {
        flash('Unable to delete customer. Check linked records.', 'error');
    }
    redirect('customers.php');
}

/*
|--------------------------------------------------------------------------
| ADD / UPDATE CUSTOMER
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id = (int) ($_POST['id'] ?? 0);

    $data = [
        trim($_POST['name'] ?? ''),
        trim($_POST['phone'] ?? ''),
        trim($_POST['email'] ?? ''),
        trim($_POST['address'] ?? '')
    ];

    if ($data[0] === '') {
        flash('Customer name is required.', 'error');
        redirect('customers.php');
    }

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE customers SET name = ?, phone = ?, email = ?, address = ? WHERE id = ?'
            );
            $stmt->execute([...$data, $id]);
            flash('Customer updated successfully.');
            redirect('customers.php');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO customers (name, phone, email, address) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute($data);
            $newCustomerId = (int) $pdo->lastInsertId();
            flash('Customer created successfully. Register their vehicle now.');
            redirect('vehicles.php?customer_id=' . $newCustomerId);
        }
    } catch (PDOException $ex) {
        flash('Error saving customer. Please try again.', 'error');
        redirect('customers.php');
    }
}

/*
|--------------------------------------------------------------------------
| EDIT CUSTOMER
|--------------------------------------------------------------------------
*/
$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch();
    if (!$edit) {
        flash('Customer not found.', 'error');
        redirect('customers.php');
    }
}

/*
|--------------------------------------------------------------------------
| SEARCH / PAGINATION / CSV EXPORT
|--------------------------------------------------------------------------
*/
$search = trim($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;

$where  = '';
$params = [];
if ($search !== '') {
    $where = 'WHERE c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.address LIKE ?';
    $kw = "%$search%";
    $params = [$kw, $kw, $kw, $kw];
}

// CSV export (respects search)
if (($_GET['export'] ?? '') === 'csv') {
    $stmt = $pdo->prepare("
        SELECT c.name, c.phone, c.email, c.address,
               (SELECT COUNT(*) FROM vehicles v WHERE v.customer_id = c.id) AS vehicles
        FROM customers c $where ORDER BY c.name ASC
    ");
    $stmt->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="customers_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Phone', 'Email', 'Address', 'Vehicles']);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) fputcsv($out, $row);
    fclose($out);
    exit;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers c $where");
$countStmt->execute($params);
$totalCustomers = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCustomers / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM vehicles v WHERE v.customer_id = c.id) AS vehicles
    FROM customers c
    $where
    ORDER BY c.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$customers = $stmt->fetchAll();

$totalVehicles = (int)$pdo->query('SELECT COUNT(*) FROM vehicles')->fetchColumn();

page_header('Customer Management');
?>

<style>
/* ---------- Modals ---------- */
.modal-backdrop {
    position: fixed; inset: 0; background: rgba(15,23,42,.55);
    backdrop-filter: blur(2px); z-index: 50;
    display: flex; align-items: center; justify-content: center;
    padding: 1rem; opacity: 0; pointer-events: none; transition: opacity .18s ease;
}
.modal-backdrop.open { opacity: 1; pointer-events: auto; }
.modal-card {
    background: #fff; border-radius: 1rem; width: 100%; max-width: 30rem;
    max-height: 92vh; overflow-y: auto; padding: 1.5rem;
    transform: translateY(14px) scale(.98); transition: transform .18s ease;
    box-shadow: 0 20px 50px rgba(0,0,0,.25);
}
.modal-backdrop.open .modal-card { transform: none; }

/* ---------- Mobile: table -> cards ---------- */
@media (max-width: 767px) {
    .responsive-table thead { display: none; }
    .responsive-table, .responsive-table tbody, .responsive-table tr, .responsive-table td { display: block; width: 100%; }
    .responsive-table tr {
        border: 1px solid #e5e7eb; border-radius: .75rem;
        margin-bottom: .75rem; padding: .75rem 1rem; background: #fff;
    }
    .responsive-table td {
        border: none; padding: .35rem 0;
        display: flex; justify-content: space-between; align-items: center; gap: 1rem;
    }
    .responsive-table td::before {
        content: attr(data-label); font-size: .7rem; font-weight: 600;
        text-transform: uppercase; letter-spacing: .04em; color: #9ca3af;
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

// ---------- Delete confirmation ----------
let deleteTarget = '', deleteName = '';
function confirmDelete(id, name) {
    deleteTarget = id;
    deleteName = name;
    document.getElementById('delName').textContent = name;
    openModal('deleteModal');
}
function doDelete() {
    window.location.href = 'customers.php?delete=' + deleteTarget;
}

// ---------- Auto-open edit modal on ?edit= ----------
<?php if ($edit): ?>
document.addEventListener('DOMContentLoaded', () => openModal('customerModal'));
<?php endif; ?>
</script>

<!-- =====================================================
     PAGE HEADER / TOOLBAR
===================================================== -->

<div class="flex flex-wrap items-center gap-3 mb-6">

    <div>
        <h1 class="text-xl font-extrabold text-gray-800">
            <i class="fa-solid fa-users mr-2 text-blue-600"></i>Customer Management
        </h1>
        <p class="text-sm text-gray-400">Manage client profiles and linked vehicles</p>
    </div>

    <div class="ml-auto flex flex-wrap gap-2">
        <a href="vehicles.php"
           class="px-4 py-2 rounded-xl text-sm font-semibold bg-white border border-gray-200 text-gray-600 hover:bg-gray-50 transition">
            <i class="fa-solid fa-car-side mr-1"></i> All Vehicles
        </a>
        <a href="customers.php?export=csv&search=<?= urlencode($search) ?>"
           class="px-4 py-2 rounded-xl text-sm font-semibold bg-white border border-gray-200 text-gray-600 hover:text-blue-600 transition">
            <i class="fa-solid fa-file-csv mr-1"></i> Export CSV
        </a>
        <button onclick="openModal('customerModal')"
                class="px-4 py-2 rounded-xl text-sm font-semibold bg-blue-600 hover:bg-blue-700 text-white shadow-sm shadow-blue-500/20 transition">
            <i class="fa-solid fa-user-plus mr-1"></i> Add Customer
        </button>
    </div>

</div>

<!-- =====================================================
     SUMMARY CARDS
===================================================== -->

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">

    <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex items-center gap-4">
        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 text-white shadow-lg shadow-blue-500/25 flex items-center justify-center shrink-0">
            <i class="fa-solid fa-users text-lg"></i>
        </div>
        <div>
            <div class="text-xl font-extrabold text-gray-800"><?= number_format($totalCustomers) ?></div>
            <div class="text-xs font-medium text-gray-400">
                <?= $search ? 'Matching Customers' : 'Total Customers' ?>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex items-center gap-4">
        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-600 text-white shadow-lg shadow-indigo-500/25 flex items-center justify-center shrink-0">
            <i class="fa-solid fa-car-side text-lg"></i>
        </div>
        <div>
            <div class="text-xl font-extrabold text-gray-800"><?= number_format($totalVehicles) ?></div>
            <div class="text-xs font-medium text-gray-400">Linked Vehicles</div>
        </div>
    </div>

    <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex items-center gap-4">
        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-600 text-white shadow-lg shadow-emerald-500/25 flex items-center justify-center shrink-0">
            <i class="fa-solid fa-user-plus text-lg"></i>
        </div>
        <div>
            <div class="text-xl font-extrabold text-gray-800"><?= number_format($totalPages) ?></div>
            <div class="text-xs font-medium text-gray-400">Directory Page<?= $totalPages === 1 ? '' : 's' ?></div>
        </div>
    </div>

</div>

<!-- =====================================================
     SEARCH TOOLBAR
===================================================== -->

<form method="get" action="customers.php" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 mb-6">

    <div class="relative">
        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-gray-400 pointer-events-none">
            <i class="fa-solid fa-magnifying-glass text-sm"></i>
        </span>
        <input type="text" name="search"
               value="<?= e($search) ?>"
               oninput="liveSearch(this.form)"
               placeholder="Search name, phone, email, address... (auto-searches)"
               class="w-full pl-10 pr-3 py-2.5 bg-gray-50/50 border border-gray-200 rounded-xl text-sm text-gray-800 placeholder-gray-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition">
    </div>

    <div class="flex justify-between items-center mt-3 text-xs text-gray-500">
        <p><?= $totalCustomers ?> customer(s) found<?= $search ? ' for &ldquo;' . e($search) . '&rdquo;' : '' ?></p>
        <?php if ($search !== ''): ?>
            <a href="customers.php" class="text-indigo-600 hover:underline">
                <i class="fa-solid fa-rotate-left mr-1"></i> Reset search
            </a>
        <?php endif; ?>
    </div>

</form>

<!-- =====================================================
     CUSTOMER DIRECTORY
===================================================== -->

<div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">

    <div class="overflow-x-auto">

        <table class="responsive-table w-full text-sm" id="customerTable">

            <thead>
                <tr class="bg-gray-50/70 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 border-b border-gray-100">
                    <th class="py-3.5 px-6">Customer</th>
                    <th class="py-3.5 px-4">Contact Info</th>
                    <th class="py-3.5 px-4 text-center">Vehicles</th>
                    <th class="py-3.5 px-6 text-right">Actions</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-gray-100">

            <?php foreach ($customers as $c):

                $words = explode(' ', trim($c['name']));
                $initials = strtoupper(
                    substr($words[0] ?? 'C', 0, 1) .
                    (isset($words[1]) ? substr($words[1], 0, 1) : '')
                );
                $is_editing = ($edit && (int)$edit['id'] === (int)$c['id']);

                // Data attributes for the edit modal (JS-driven)
                $cJson = htmlspecialchars(json_encode([
                    'id'      => (int)$c['id'],
                    'name'    => $c['name'],
                    'phone'   => $c['phone'],
                    'email'   => $c['email'],
                    'address' => $c['address'],
                ]), ENT_QUOTES, 'UTF-8');
            ?>

                <tr class="hover:bg-gray-50/80 transition <?= $is_editing ? 'bg-amber-50/40' : '' ?>">

                    <!-- Customer -->
                    <td class="py-3.5 px-6" data-label="Customer">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-white font-bold text-xs flex items-center justify-center shrink-0 shadow-sm">
                                <?= e($initials) ?>
                            </div>
                            <div class="min-w-0">
                                <div class="font-semibold text-gray-800 truncate flex items-center gap-2">
                                    <?= e($c['name']) ?>
                                    <?php if ($is_editing): ?>
                                        <span class="text-[10px] font-semibold bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded">Editing</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($c['address'])): ?>
                                    <div class="text-xs text-gray-400 truncate max-w-xs" title="<?= e($c['address']) ?>">
                                        <i class="fa-solid fa-location-dot text-[10px] mr-1 opacity-75"></i><?= e($c['address']) ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-xs text-gray-300 italic">No address provided</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>

                    <!-- Contact -->
                    <td class="py-3.5 px-4" data-label="Contact">
                        <div class="space-y-1">
                            <?php if (!empty($c['phone'])): ?>
                                <a href="tel:<?= e($c['phone']) ?>"
                                   class="flex items-center gap-1.5 text-xs font-medium text-gray-700 hover:text-blue-600 transition">
                                    <i class="fa-solid fa-phone text-[10px] text-gray-400"></i>
                                    <span><?= e($c['phone']) ?></span>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($c['email'])): ?>
                                <a href="mailto:<?= e($c['email']) ?>"
                                   class="flex items-center gap-1.5 text-xs text-gray-500 hover:text-indigo-600 transition truncate max-w-[200px]">
                                    <i class="fa-regular fa-envelope text-[10px] text-gray-400"></i>
                                    <span class="truncate"><?= e($c['email']) ?></span>
                                </a>
                            <?php endif; ?>
                            <?php if (empty($c['phone']) && empty($c['email'])): ?>
                                <span class="text-xs text-gray-300 italic">No contact info</span>
                            <?php endif; ?>
                        </div>
                    </td>

                    <!-- Vehicles -->
                    <td class="py-3.5 px-4 text-center" data-label="Vehicles">
                        <a href="vehicles.php?customer_id=<?= (int)$c['id'] ?>"
                           class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold <?= (int)$c['vehicles'] > 0 ? 'bg-indigo-50 text-indigo-600 hover:bg-indigo-100 border border-indigo-100' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' ?> transition"
                           title="Manage customer vehicles">
                            <i class="fa-solid fa-car text-[10px]"></i>
                            <span><?= (int)$c['vehicles'] ?></span>
                        </a>
                    </td>

                    <!-- Actions -->
                    <td class="py-3.5 px-6 text-right" data-label="Actions">
                        <div class="inline-flex items-center justify-end gap-1">

                            <button onclick='openEdit(<?= $cJson ?>)'
                                    class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-blue-600 hover:bg-blue-50 transition"
                                    title="Edit customer">
                                <i class="fa-solid fa-pen text-xs"></i>
                            </button>

                            <a href="vehicles.php?customer_id=<?= (int)$c['id'] ?>"
                               class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-emerald-600 hover:bg-emerald-50 transition"
                               title="View vehicles">
                                <i class="fa-solid fa-car text-xs"></i>
                            </a>

                            <a href="vehicles.php?customer_id=<?= (int)$c['id'] ?>&add=1"
                               class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 transition"
                               title="Add vehicle for this customer">
                                <i class="fa-solid fa-plus text-xs"></i>
                            </a>

                            <button onclick='confirmDelete(<?= (int)$c['id'] ?>, <?= json_encode($c['name']) ?>)'
                                    class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-red-600 hover:bg-red-50 transition"
                                    title="Delete customer">
                                <i class="fa-solid fa-trash text-xs"></i>
                            </button>

                        </div>
                    </td>

                </tr>

            <?php endforeach; ?>

            <?php if (!$customers): ?>
                <tr>
                    <td colspan="4" class="py-12 text-center">
                        <div class="w-12 h-12 rounded-full bg-gray-50 text-gray-400 flex items-center justify-center mx-auto mb-3">
                            <i class="fa-solid fa-users-slash text-xl"></i>
                        </div>
                        <p class="text-sm font-medium text-gray-700">
                            <?= $search ? 'No customers match your search.' : 'No customers found' ?>
                        </p>
                        <p class="text-xs text-gray-400 mt-1">
                            <?= $search ? 'Try a different keyword.' : 'Click "Add Customer" to create your first client profile.' ?>
                        </p>
                    </td>
                </tr>
            <?php endif; ?>

            </tbody>

        </table>

    </div>

    <!-- PAGINATION -->
    <?php if ($totalPages > 1): ?>
    <div class="flex items-center justify-between px-6 py-3 border-t text-sm">

        <p class="text-gray-500 text-xs">Page <?= $page ?> of <?= $totalPages ?></p>

        <div class="flex gap-1">
            <?php
            $qs = fn($p) => 'customers.php?search=' . urlencode($search) . '&page=' . $p;
            $win = range(max(1, $page - 2), min($totalPages, $page + 2));
            if ($page > 1): ?>
                <a href="<?= $qs($page - 1) ?>" class="px-3 py-1.5 rounded-lg border hover:bg-gray-50">
                    <i class="fa-solid fa-chevron-left"></i>
                </a>
            <?php endif;
            foreach ($win as $pn): ?>
                <a href="<?= $qs($pn) ?>"
                   class="px-3 py-1.5 rounded-lg border <?= $pn === $page ? 'bg-blue-600 text-white border-blue-600' : 'hover:bg-gray-50' ?>">
                    <?= $pn ?>
                </a>
            <?php endforeach;
            if ($page < $totalPages): ?>
                <a href="<?= $qs($page + 1) ?>" class="px-3 py-1.5 rounded-lg border hover:bg-gray-50">
                    <i class="fa-solid fa-chevron-right"></i>
                </a>
            <?php endif; ?>
        </div>

    </div>
    <?php endif; ?>

</div>

<!-- ============ ADD / EDIT CUSTOMER MODAL ============ -->
<div id="customerModal" class="modal-backdrop">
    <form method="post" id="customerForm" class="modal-card space-y-4" autocomplete="off">

        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div id="cmIcon" class="w-9 h-9 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center">
                    <i class="fa-solid fa-user-plus"></i>
                </div>
                <div>
                    <h2 id="cmTitle" class="font-bold text-gray-800 text-sm">Add New Customer</h2>
                    <p id="cmSub" class="text-xs text-gray-400">Register a new client profile</p>
                </div>
            </div>
            <button type="button" onclick="closeModal('customerModal')"
                    class="w-8 h-8 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <input type="hidden" name="id" id="c_id">

        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1.5">
                Full Name <span class="text-red-500">*</span>
            </label>
            <div class="relative">
                <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-gray-400 pointer-events-none">
                    <i class="fa-regular fa-user text-xs"></i>
                </span>
                <input name="name" id="c_name" required autofocus
                       placeholder="e.g. John Doe"
                       class="w-full pl-9 pr-3.5 py-2.5 bg-gray-50/50 border border-gray-200 rounded-xl text-sm text-gray-800 placeholder-gray-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition">
            </div>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1.5">Phone Number</label>
            <div class="relative">
                <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-gray-400 pointer-events-none">
                    <i class="fa-solid fa-phone text-xs"></i>
                </span>
                <input name="phone" id="c_phone" type="tel"
                       placeholder="e.g. +94 71 234 5678"
                       class="w-full pl-9 pr-3.5 py-2.5 bg-gray-50/50 border border-gray-200 rounded-xl text-sm text-gray-800 placeholder-gray-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition">
            </div>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1.5">Email Address</label>
            <div class="relative">
                <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-gray-400 pointer-events-none">
                    <i class="fa-regular fa-envelope text-xs"></i>
                </span>
                <input name="email" id="c_email" type="email"
                       placeholder="e.g. john@example.com"
                       class="w-full pl-9 pr-3.5 py-2.5 bg-gray-50/50 border border-gray-200 rounded-xl text-sm text-gray-800 placeholder-gray-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition">
            </div>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1.5">Address</label>
            <div class="relative">
                <span class="absolute top-3 left-0 pl-3.5 flex items-center text-gray-400 pointer-events-none">
                    <i class="fa-solid fa-location-dot text-xs"></i>
                </span>
                <textarea name="address" id="c_address" rows="3"
                          placeholder="Street address, city..."
                          class="w-full pl-9 pr-3.5 py-2.5 bg-gray-50/50 border border-gray-200 rounded-xl text-sm text-gray-800 placeholder-gray-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition"></textarea>
            </div>
        </div>

        <div id="cmNote" class="bg-blue-50 border border-blue-100 rounded-xl p-3">
            <p class="text-xs text-blue-700 flex items-start gap-2">
                <i class="fa-solid fa-circle-info mt-0.5"></i>
                <span>After saving a new customer, you will automatically proceed to register their vehicle.</span>
            </p>
        </div>

        <div class="flex items-center gap-2.5 pt-1">
            <button type="submit" id="cmSubmit"
                    class="flex-1 bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-2.5 px-4 text-sm font-semibold shadow-sm shadow-blue-500/20 transition flex items-center justify-center gap-2 cursor-pointer">
                <i class="fa-solid fa-floppy-disk"></i>
                <span id="cmSubmitLabel">Save Customer</span>
            </button>
            <button type="button" onclick="closeModal('customerModal')"
                    class="px-4 py-2.5 rounded-xl border border-gray-200 hover:bg-gray-50 text-gray-600 text-sm font-semibold transition">
                Cancel
            </button>
        </div>

    </form>
</div>

<!-- ============ DELETE CONFIRM MODAL ============ -->
<div id="deleteModal" class="modal-backdrop">
    <div class="modal-card !max-w-sm text-center space-y-4">

        <div class="w-14 h-14 mx-auto rounded-full bg-red-100 text-red-600 flex items-center justify-center text-2xl">
            <i class="fa-solid fa-trash"></i>
        </div>

        <div>
            <h3 class="font-bold text-gray-800 text-lg">Delete Customer?</h3>
            <p class="text-sm text-gray-500 mt-1">
                <span id="delName" class="font-medium text-gray-700"></span>
                will be permanently removed. Linked vehicles and service records may prevent deletion.
            </p>
        </div>

        <div class="flex gap-2">
            <button onclick="closeModal('deleteModal')"
                    class="flex-1 px-4 py-2.5 rounded-lg border text-sm text-gray-600 hover:bg-gray-50 transition">
                Cancel
            </button>
            <button onclick="doDelete()"
                    class="flex-1 px-4 py-2.5 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-medium transition">
                <i class="fa-solid fa-trash mr-1"></i> Delete
            </button>
        </div>

    </div>
</div>

<script>
// ---------- Populate edit modal from row data (no page reload for edit) ----------
function openEdit(c) {
    const f = document.getElementById('customerForm');
    f.reset();

    document.getElementById('c_id').value      = c.id || '';
    document.getElementById('c_name').value    = c.name || '';
    document.getElementById('c_phone').value   = c.phone || '';
    document.getElementById('c_email').value   = c.email || '';
    document.getElementById('c_address').value = c.address || '';

    // Switch styling to "edit mode"
    document.getElementById('cmTitle').textContent  = c.id ? 'Edit Customer' : 'Add New Customer';
    document.getElementById('cmSub').textContent    = c.id ? 'Update customer details below' : 'Register a new client profile';
    document.getElementById('cmSubmitLabel').textContent = c.id ? 'Update Customer' : 'Save Customer';
    document.getElementById('cmNote').classList.toggle('hidden', !!c.id);
    document.getElementById('cmIcon').className =
        'w-9 h-9 rounded-lg flex items-center justify-center ' +
        (c.id ? 'bg-amber-100 text-amber-600' : 'bg-blue-50 text-blue-600');
    document.querySelector('#cmIcon i').className = c.id ? 'fa-solid fa-user-pen' : 'fa-solid fa-user-plus';
    document.getElementById('cmSubmit').className =
        'flex-1 text-white rounded-xl py-2.5 px-4 text-sm font-semibold shadow-sm transition flex items-center justify-center gap-2 cursor-pointer ' +
        (c.id ? 'bg-amber-500 hover:bg-amber-600 shadow-amber-500/20' : 'bg-blue-600 hover:bg-blue-700 shadow-blue-500/20');

    openModal('customerModal');
}
</script>

<?php page_footer(); ?>
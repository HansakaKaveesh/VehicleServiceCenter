<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

// --------------------------------------------------
// STOCK MOVEMENT
// --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'movement') {
    $part_id = (int)($_POST['part_id'] ?? 0);
    $type = $_POST['movement_type'] ?? '';
    $qty = (int)($_POST['quantity'] ?? 0);
    $price = (float)($_POST['unit_price'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($part_id <= 0 || $qty <= 0 ||
        !in_array($type, ['Purchase', 'Usage', 'Adjustment'], true)) {
        flash('Invalid stock movement details.', 'error');
        redirect('parts.php');
    }

    $pdo->beginTransaction();

    try {
        $check = $pdo->prepare('SELECT stock_qty FROM parts WHERE id = ? FOR UPDATE');
        $check->execute([$part_id]);
        $part = $check->fetch();

        if (!$part) {
            throw new Exception('Part not found.');
        }

        if ($type === 'Usage' && $qty > (int)$part['stock_qty']) {
            throw new Exception('Insufficient stock quantity.');
        }

        $pdo->prepare(
            'INSERT INTO stock_movements
            (part_id, movement_type, quantity, unit_price, reference, notes)
            VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $part_id, $type, $qty, $price,
            trim($_POST['reference'] ?? ''), $notes
        ]);

        if ($type === 'Purchase') {
            $pdo->prepare(
                'UPDATE parts SET stock_qty = stock_qty + ?,
                     unit_price = IF(? > 0, ?, unit_price) WHERE id = ?'
            )->execute([$qty, $price, $price, $part_id]);
        } elseif ($type === 'Usage') {
            $pdo->prepare('UPDATE parts SET stock_qty = stock_qty - ? WHERE id = ?')
                ->execute([$qty, $part_id]);
        } else {
            $pdo->prepare('UPDATE parts SET stock_qty = ? WHERE id = ?')
                ->execute([$qty, $part_id]);
        }

        $pdo->commit();
        flash("Stock movement recorded ($type).");

    } catch (Exception $ex) {
        $pdo->rollBack();
        flash($ex->getMessage(), 'error');
    }

    redirect('parts.php');
}

// --------------------------------------------------
// ADD / EDIT PART
// --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {

    $id = (int)($_POST['id'] ?? 0);

    $brand = trim($_POST['brand_sel'] ?? '');
    if ($brand === '__other__') $brand = trim($_POST['brand_new'] ?? '');

    $category = trim($_POST['category_sel'] ?? '');
    if ($category === '__other__') $category = trim($_POST['category_new'] ?? '');

    $data = [
        trim($_POST['name'] ?? ''),
        trim($_POST['part_no'] ?? ''),
        $category,
        $brand,
        max(0, (float)($_POST['unit_price'] ?? 0)),
        max(0, (int)($_POST['stock_qty'] ?? 0)),
        max(0, (int)($_POST['min_stock'] ?? 5)),
        !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null
    ];

    if ($data[0] === '') {
        flash('Part name is required.', 'error');
        redirect('parts.php');
    }

    if ($id) {
        $pdo->prepare(
            'UPDATE parts SET name=?, part_no=?, category=?, brand=?,
                 unit_price=?, stock_qty=?, min_stock=?, supplier_id=? WHERE id=?'
        )->execute([...$data, $id]);
        flash('Part updated successfully.');
    } else {
        $pdo->prepare(
            'INSERT INTO parts (name, part_no, category, brand, unit_price,
             stock_qty, min_stock, supplier_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute($data);
        flash('Part added successfully.');
    }

    redirect('parts.php');
}

// --------------------------------------------------
// DELETE PART
// --------------------------------------------------
if (isset($_GET['delete'])) {
    try {
        $pdo->prepare('DELETE FROM parts WHERE id = ?')
            ->execute([(int)$_GET['delete']]);
        flash('Part deleted successfully.');
    } catch (PDOException $ex) {
        flash('Cannot delete this part because it has related records.', 'error');
    }
    redirect('parts.php');
}

// --------------------------------------------------
// SHARED DATA
// --------------------------------------------------
$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll();

$brands = $pdo->query(
    "SELECT DISTINCT brand FROM parts WHERE brand IS NOT NULL AND brand <> '' ORDER BY brand"
)->fetchAll(PDO::FETCH_COLUMN);

$categories = $pdo->query(
    "SELECT DISTINCT category FROM parts WHERE category IS NOT NULL AND category <> '' ORDER BY category"
)->fetchAll(PDO::FETCH_COLUMN);

$view = $_GET['view'] ?? 'parts';
$search = trim($_GET['search'] ?? '');
$sort = $_GET['sort'] ?? 'newest';

$allowedSorts = [
    'newest' => 'p.id DESC', 'oldest' => 'p.id ASC',
    'name_asc' => 'p.name ASC', 'name_desc' => 'p.name DESC',
    'price_asc' => 'p.unit_price ASC', 'price_desc' => 'p.unit_price DESC',
    'stock_asc' => 'p.stock_qty ASC', 'stock_desc' => 'p.stock_qty DESC'
];
if (!isset($allowedSorts[$sort])) $sort = 'newest';

// --------------------------------------------------
// CSV EXPORT (parts list, respects search)
// --------------------------------------------------
if ($view === 'parts' && ($_GET['export'] ?? '') === 'csv') {
    $stmt = $pdo->prepare(
        "SELECT p.name, p.part_no, p.brand, p.category, p.unit_price,
                p.stock_qty, p.min_stock, s.name AS supplier
         FROM parts p LEFT JOIN suppliers s ON s.id = p.supplier_id
         WHERE (p.name LIKE ? OR p.part_no LIKE ? OR p.brand LIKE ?
                OR p.category LIKE ? OR s.name LIKE ?)
         ORDER BY " . $allowedSorts[$sort]
    );
    $kw = "%$search%";
    $stmt->execute([$kw, $kw, $kw, $kw, $kw]);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="parts_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Part No', 'Brand', 'Category', 'Unit Price', 'Stock', 'Min Stock', 'Supplier']);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) fputcsv($out, $row);
    fclose($out);
    exit;
}

// --------------------------------------------------
// EDIT DATA
// --------------------------------------------------
$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM parts WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch();

    if ($edit) {
        if ($edit['brand'] && !in_array($edit['brand'], $brands)) $brands[] = $edit['brand'];
        if ($edit['category'] && !in_array($edit['category'], $categories)) $categories[] = $edit['category'];
        sort($brands);
        sort($categories);
    }
}

page_header('Spare Parts Inventory');

// --------------------------------------------------
// PARTS VIEW DATA + PAGINATION
// --------------------------------------------------
$allParts = [];
$parts = [];
$totalCount = 0;
$totalStock = 0;
$lowStockCount = 0;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$totalPages = 1;

if ($view === 'parts') {
    // All parts for the movement picker (no longer limited to search results)
    $allParts = $pdo->query('SELECT id, name, stock_qty FROM parts ORDER BY name')->fetchAll();

    $kw = "%$search%";
    $where = "(p.name LIKE ? OR p.part_no LIKE ? OR p.brand LIKE ? OR p.category LIKE ? OR s.name LIKE ?)";

    $c = $pdo->prepare(
        "SELECT COUNT(*) FROM parts p LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE $where"
    );
    $c->execute([$kw, $kw, $kw, $kw, $kw]);
    $totalCount = (int)$c->fetchColumn();
    $totalPages = max(1, (int)ceil($totalCount / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT p.*, s.name AS supplier FROM parts p
         LEFT JOIN suppliers s ON s.id = p.supplier_id
         WHERE $where ORDER BY " . $allowedSorts[$sort] . " LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute([$kw, $kw, $kw, $kw, $kw]);
    $parts = $stmt->fetchAll();

    $stats = $pdo->query(
        'SELECT COALESCE(SUM(stock_qty),0) AS total_stock,
                SUM(stock_qty <= min_stock) AS low_count FROM parts'
    )->fetch();
    $totalStock = (int)$stats['total_stock'];
    $lowStockCount = (int)$stats['low_count'];
} elseif ($view === 'movements') {
    $rows = $pdo->query(
        'SELECT m.*, p.name AS part, p.part_no, p.brand
         FROM stock_movements m JOIN parts p ON p.id = m.part_id
         ORDER BY m.id DESC LIMIT 200'
    )->fetchAll();
} else {
    $lowRows = $pdo->query(
        'SELECT p.*, s.name AS supplier FROM parts p
         LEFT JOIN suppliers s ON s.id = p.supplier_id
         WHERE p.stock_qty <= p.min_stock ORDER BY p.stock_qty ASC'
    )->fetchAll();
}

$partsJson = json_encode(array_map(
    fn($p) => ['id' => (int)$p['id'], 'label' => $p['name'] . ' (' . (int)$p['stock_qty'] . ')'],
    $allParts
));
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
    background: #fff; border-radius: 1rem; width: 100%; max-width: 34rem;
    max-height: 92vh; overflow-y: auto; padding: 1.5rem;
    transform: translateY(14px) scale(.98); transition: transform .18s ease;
    box-shadow: 0 20px 50px rgba(0,0,0,.25);
}
.modal-backdrop.open .modal-card { transform: none; }

/* ---------- Table ---------- */
.sticky-thead th { position: sticky; top: 0; background: #fff; z-index: 1; }
tbody tr { transition: background .12s ease; }

/* ---------- Mobile: turn table into cards ---------- */
@media (max-width: 767px) {
    .responsive-table thead { display: none; }
    .responsive-table, .responsive-table tbody, .responsive-table tr, .responsive-table td { display: block; width: 100%; }
    .responsive-table tr {
        border: 1px solid #e5e7eb; border-radius: .75rem;
        margin-bottom: .75rem; padding: .5rem .75rem; background: #fff;
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

// ---------- "Other" dropdown toggle ----------
function toggleOther(field) {
    const sel = document.getElementById(field + '_sel');
    const inp = document.getElementById(field + '_new');
    inp.classList.toggle('hidden', sel.value !== '__other__');
    if (sel.value === '__other__') inp.focus();
}

// ---------- Debounced live search ----------
let searchTimer;
function liveSearch(form) {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => form.submit(), 450);
}

// ---------- Stock movement modal ----------
const PARTS = <?= $partsJson ?: '[]' ?>;

function openMovement(partId = '', type = 'Purchase') {
    const f = document.getElementById('movementForm');
    f.reset();
    if (partId) {
        const p = PARTS.find(x => x.id === +partId);
        document.getElementById('mv_part').value = p ? p.label : '';
    }
    document.getElementById('mv_type').value = type;
    mvHint();
    openModal('movementModal');
}

function mvPartId() {
    const label = document.getElementById('mv_part').value.trim();
    const p = PARTS.find(x => x.label === label);
    document.getElementById('mv_part_id').value = p ? p.id : '';
}

function mvHint() {
    const t = document.getElementById('mv_type').value;
    const hints = {
        Purchase: 'Adds stock. Unit price (optional) updates the part\'s price.',
        Usage: 'Removes stock. Quantity cannot exceed current stock.',
        Adjustment: 'Sets the absolute stock count to the quantity entered.'
    };
    document.getElementById('mv_hint').textContent = hints[t] || '';
}

function submitMovement(e) {
    mvPartId();
    if (!document.getElementById('mv_part_id').value) {
        e.preventDefault();
        document.getElementById('mv_part').focus();
        document.getElementById('mv_part').classList.add('ring-2','ring-red-400');
        return false;
    }
}

// ---------- Delete confirmation ----------
let deleteTarget = '';
function confirmDelete(id, name) {
    deleteTarget = id;
    document.getElementById('delName').textContent = name;
    openModal('deleteModal');
}
function doDelete() {
    window.location.href = 'parts.php?delete=' + deleteTarget;
}

// ---------- Auto-open edit modal on ?edit= ----------
<?php if ($edit): ?>
document.addEventListener('DOMContentLoaded', () => {
    openModal('partModal');
    ['brand', 'category'].forEach(f => {
        const sel = document.getElementById(f + '_sel');
        const inp = document.getElementById(f + '_new');
        if (sel && sel.value === '__other__') {
            inp.value = <?= json_encode($edit['brand'] && !in_array($edit['brand'], array_diff($brands, [$edit['brand']])) ? '' : '') ?>;
        }
    });
});
<?php endif; ?>
</script>

<!-- NAVIGATION -->
<div class="flex flex-wrap items-center gap-2 mb-5">

    <a href="parts.php"
       class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $view === 'parts' ? 'bg-blue-600 text-white shadow' : 'bg-white shadow text-gray-600 hover:text-blue-600' ?>">
        <i class="fa-solid fa-gears mr-1"></i> Parts
    </a>

    <a href="parts.php?view=movements"
       class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $view === 'movements' ? 'bg-blue-600 text-white shadow' : 'bg-white shadow text-gray-600 hover:text-blue-600' ?>">
        <i class="fa-solid fa-arrow-right-arrow-left mr-1"></i> Stock Movements
    </a>

    <a href="parts.php?view=lowstock"
       class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $view === 'lowstock' ? 'bg-blue-600 text-white shadow' : 'bg-white shadow text-gray-600 hover:text-blue-600' ?>">
        <i class="fa-solid fa-triangle-exclamation mr-1"></i> Low Stock
    </a>

    <?php if ($view === 'parts'): ?>
        <div class="ml-auto flex gap-2">
            <button onclick="openModal('partModal')"
                    class="px-4 py-2 rounded-lg text-sm font-medium bg-emerald-600 hover:bg-emerald-700 text-white shadow transition">
                <i class="fa-solid fa-plus mr-1"></i> Add Part
            </button>
            <a href="parts.php?export=csv&search=<?= urlencode($search) ?>&sort=<?= urlencode($sort) ?>"
               class="px-4 py-2 rounded-lg text-sm font-medium bg-white shadow text-gray-600 hover:text-blue-600 transition">
                <i class="fa-solid fa-file-csv mr-1"></i> Export CSV
            </a>
        </div>
    <?php endif; ?>

</div>

<?php if ($view === 'parts'): ?>

<!-- STAT CARDS -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">

    <div class="bg-white rounded-xl shadow p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center text-lg">
            <i class="fa-solid fa-gears"></i>
        </div>
        <div>
            <p class="text-sm text-gray-500">Total Parts</p>
            <h3 class="text-2xl font-bold text-blue-600"><?= $totalCount ?></h3>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-green-100 text-green-600 flex items-center justify-center text-lg">
            <i class="fa-solid fa-cubes"></i>
        </div>
        <div>
            <p class="text-sm text-gray-500">Stock Units</p>
            <h3 class="text-2xl font-bold text-green-600"><?= $totalStock ?></h3>
        </div>
    </div>

    <a href="parts.php?view=lowstock" class="block bg-white rounded-xl shadow p-4 flex items-center gap-3 hover:ring-2 hover:ring-red-200 transition">
        <div class="w-11 h-11 rounded-xl bg-red-100 text-red-600 flex items-center justify-center text-lg">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <div>
            <p class="text-sm text-gray-500">Low Stock Items</p>
            <h3 class="text-2xl font-bold <?= $lowStockCount ? 'text-red-600' : 'text-green-600' ?>"><?= $lowStockCount ?></h3>
        </div>
    </a>

</div>

<!-- SEARCH / SORT TOOLBAR -->
<form method="get" action="parts.php" id="searchForm"
      class="bg-white rounded-2xl shadow p-4 mb-4">

    <div class="grid md:grid-cols-3 gap-3">

        <div class="md:col-span-2 relative">
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-3 text-gray-400"></i>
            <input type="text" name="search"
                   value="<?= e($search) ?>"
                   oninput="liveSearch(this.form)"
                   placeholder="Search name, part no, brand, category, supplier... (auto-searches)"
                   class="w-full border rounded-lg pl-9 pr-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
        </div>

        <select name="sort" onchange="this.form.submit()"
                class="border rounded-lg px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-blue-500 outline-none">
            <?php
            $sortLabels = [
                'newest' => 'Newest First', 'oldest' => 'Oldest First',
                'name_asc' => 'Name (A-Z)', 'name_desc' => 'Name (Z-A)',
                'price_asc' => 'Price: Low to High', 'price_desc' => 'Price: High to Low',
                'stock_asc' => 'Stock: Low to High', 'stock_desc' => 'Stock: High to Low'
            ];
            foreach ($sortLabels as $k => $lbl): ?>
                <option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>

    </div>

    <div class="flex justify-between items-center mt-3 text-xs text-gray-500">
        <p><?= $totalCount ?> part(s) found<?= $search ? ' for &ldquo;' . e($search) . '&rdquo;' : '' ?></p>
        <?php if ($search || $sort !== 'newest'): ?>
            <a href="parts.php" class="text-blue-600 hover:underline">
                <i class="fa-solid fa-rotate-left mr-1"></i> Reset filters
            </a>
        <?php endif; ?>
    </div>

</form>

<!-- PARTS TABLE -->
<div class="bg-white rounded-2xl shadow overflow-hidden">

    <div class="overflow-x-auto max-h-[32rem] overflow-y-auto">
        <table class="responsive-table w-full text-sm">

            <thead>
                <tr class="text-left text-gray-500 border-b sticky-thead">
                    <th class="py-3 px-4">Part</th>
                    <th class="px-2">Part No</th>
                    <th class="px-2">Brand</th>
                    <th class="px-2">Category</th>
                    <th class="px-2">Price</th>
                    <th class="px-2">Stock</th>
                    <th class="px-2">Supplier</th>
                    <th class="px-4 text-right">Actions</th>
                </tr>
            </thead>

            <tbody>

            <?php foreach ($parts as $p):
                $low = (int)$p['stock_qty'] <= (int)$p['min_stock'];
            ?>
                <tr class="border-b hover:bg-blue-50/60">

                    <td class="py-3 px-4 font-medium" data-label="Part"><?= e($p['name']) ?></td>
                    <td class="px-2 text-gray-500" data-label="Part No"><?= e($p['part_no']) ?></td>
                    <td class="px-2" data-label="Brand"><?= e($p['brand']) ?></td>
                    <td class="px-2" data-label="Category">
                        <?php if ($p['category']): ?>
                            <span class="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600"><?= e($p['category']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-2" data-label="Price"><?= money($p['unit_price']) ?></td>
                    <td class="px-2" data-label="Stock">
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $low ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700' ?>">
                            <?= (int)$p['stock_qty'] ?><?= $low ? ' low' : '' ?>
                        </span>
                    </td>
                    <td class="px-2" data-label="Supplier"><?= e($p['supplier']) ?></td>

                    <td class="px-4 text-right whitespace-nowrap" data-label="Actions">
                        <div class="flex justify-end gap-1">
                            <?php if ($low): ?>
                                <button onclick="openMovement(<?= (int)$p['id'] ?>, 'Purchase')"
                                        title="Quick restock"
                                        class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 hover:bg-emerald-100 transition">
                                    <i class="fa-solid fa-cart-plus"></i>
                                </button>
                            <?php endif; ?>
                            <button onclick="openMovement(<?= (int)$p['id'] ?>, 'Usage')"
                                    title="Record usage"
                                    class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100 transition">
                                <i class="fa-solid fa-arrow-right-arrow-left"></i>
                            </button>
                            <a href="parts.php?edit=<?= (int)$p['id'] ?>"
                               title="Edit"
                               class="w-8 h-8 inline-flex items-center justify-center rounded-lg bg-amber-50 text-amber-600 hover:bg-amber-100 transition">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <button onclick="confirmDelete(<?= (int)$p['id'] ?>, <?= json_encode($p['name']) ?>)"
                                    title="Delete"
                                    class="w-8 h-8 rounded-lg bg-red-50 text-red-600 hover:bg-red-100 transition">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </td>

                </tr>
            <?php endforeach; ?>

            <?php if (!$parts): ?>
                <tr>
                    <td colspan="8" class="py-10 text-center text-gray-400">
                        <i class="fa-solid fa-box-open text-3xl mb-2 block"></i>
                        No parts found. <?= $search ? 'Try a different search.' : 'Click "Add Part" to create one.' ?>
                    </td>
                </tr>
            <?php endif; ?>

            </tbody>

        </table>
    </div>

    <!-- PAGINATION -->
    <?php if ($totalPages > 1): ?>
    <div class="flex items-center justify-between px-4 py-3 border-t text-sm">

        <p class="text-gray-500">
            Page <?= $page ?> of <?= $totalPages ?>
        </p>

        <div class="flex gap-1">
            <?php
            $qs = fn($p) => 'parts.php?search=' . urlencode($search) . '&sort=' . urlencode($sort) . '&page=' . $p;
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

<!-- ============ ADD / EDIT PART MODAL ============ -->
<div id="partModal" class="modal-backdrop">
    <form method="post" class="modal-card space-y-3" autocomplete="off">

        <div class="flex items-center justify-between mb-1">
            <h2 class="font-bold text-gray-700 text-lg">
                <i class="fa-solid fa-gear mr-1 text-blue-600"></i>
                <?= $edit ? 'Edit Part' : 'Add New Part' ?>
            </h2>
            <button type="button" onclick="closeModal('partModal')"
                    class="w-8 h-8 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">

        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">PART NAME *</label>
            <input name="name" required autofocus
                   placeholder="e.g. Brake Pad Set"
                   value="<?= e($edit['name'] ?? '') ?>"
                   class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
        </div>

        <div class="grid grid-cols-2 gap-2">
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">PART NO</label>
                <input name="part_no" placeholder="e.g. BP-2041"
                       value="<?= e($edit['part_no'] ?? '') ?>"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">BRAND</label>
                <select name="brand_sel" id="brand_sel" onchange="toggleOther('brand')"
                        class="w-full border rounded-lg px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-blue-500 outline-none">
                    <option value="">-- Select --</option>
                    <?php foreach ($brands as $b): ?>
                        <option value="<?= e($b) ?>" <?= ($edit['brand'] ?? '') === $b ? 'selected' : '' ?>><?= e($b) ?></option>
                    <?php endforeach; ?>
                    <option value="__other__">+ Add new brand...</option>
                </select>
            </div>
            <input name="brand_new" id="brand_new" placeholder="Type new brand"
                   class="hidden col-span-2 border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">CATEGORY</label>
            <select name="category_sel" id="category_sel" onchange="toggleOther('category')"
                    class="w-full border rounded-lg px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-blue-500 outline-none">
                <option value="">-- Select --</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= e($c) ?>" <?= ($edit['category'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
                <option value="__other__">+ Add new category...</option>
            </select>
            <input name="category_new" id="category_new" placeholder="Type new category"
                   class="hidden mt-2 w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
        </div>

        <div class="grid grid-cols-2 gap-2">
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">UNIT PRICE</label>
                <input name="unit_price" type="number" step="0.01" min="0" placeholder="0.00"
                       value="<?= e($edit['unit_price'] ?? '') ?>"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">STOCK QTY</label>
                <input name="stock_qty" type="number" min="0" placeholder="0"
                       value="<?= e($edit['stock_qty'] ?? '0') ?>"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
            </div>
        </div>

        <div class="grid grid-cols-2 gap-2">
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">MIN STOCK LEVEL</label>
                <input name="min_stock" type="number" min="0" placeholder="5"
                       value="<?= e($edit['min_stock'] ?? '5') ?>"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">SUPPLIER</label>
                <select name="supplier_id"
                        class="w-full border rounded-lg px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-blue-500 outline-none">
                    <option value="">-- Select --</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"
                            <?= ($edit['supplier_id'] ?? '') == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="flex gap-2 pt-2">
            <button type="submit"
                    class="flex-1 bg-blue-600 hover:bg-blue-700 text-white rounded-lg py-2.5 text-sm font-medium transition">
                <i class="fa-solid fa-floppy-disk mr-1"></i>
                <?= $edit ? 'Update Part' : 'Add Part' ?>
            </button>
            <?php if ($edit): ?>
                <a href="parts.php"
                   class="px-4 py-2.5 rounded-lg border text-sm text-gray-600 hover:bg-gray-50 transition">
                    Cancel
                </a>
            <?php endif; ?>
        </div>

    </form>
</div>

<!-- ============ STOCK MOVEMENT MODAL ============ -->
<div id="movementModal" class="modal-backdrop">
    <form method="post" id="movementForm" class="modal-card space-y-3"
          onsubmit="return submitMovement(event)" autocomplete="off">

        <div class="flex items-center justify-between mb-1">
            <h2 class="font-bold text-gray-700 text-lg">
                <i class="fa-solid fa-arrow-right-arrow-left mr-1 text-emerald-600"></i>
                Record Stock Movement
            </h2>
            <button type="button" onclick="closeModal('movementModal')"
                    class="w-8 h-8 rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <input type="hidden" name="action" value="movement">
        <input type="hidden" name="part_id" id="mv_part_id">

        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">PART *</label>
            <input id="mv_part" list="partsList" required autofocus
                   placeholder="Type to search parts..."
                   class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500 outline-none">
            <datalist id="partsList">
                <?php foreach ($allParts as $p): ?>
                    <option value="<?= e($p['name'] . ' (' . (int)$p['stock_qty'] . ')') ?>">
                <?php endforeach; ?>
            </datalist>
        </div>

        <div class="grid grid-cols-2 gap-2">
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">TYPE</label>
                <select name="movement_type" id="mv_type" onchange="mvHint()"
                        class="w-full border rounded-lg px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-emerald-500 outline-none">
                    <option>Purchase</option>
                    <option>Usage</option>
                    <option>Adjustment</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">QUANTITY *</label>
                <input name="quantity" type="number" min="1" required placeholder="0"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500 outline-none">
            </div>
        </div>

        <p id="mv_hint" class="text-xs text-gray-500 bg-gray-50 rounded-lg px-3 py-2"></p>

        <div class="grid grid-cols-2 gap-2">
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">UNIT PRICE</label>
                <input name="unit_price" type="number" step="0.01" min="0" placeholder="0.00"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500 outline-none">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">REFERENCE / PO NO</label>
                <input name="reference" placeholder="e.g. PO-1024"
                       class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500 outline-none">
            </div>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">NOTES</label>
            <textarea name="notes" rows="2" placeholder="Optional notes..."
                      class="w-full border rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500 outline-none"></textarea>
        </div>

        <button type="submit"
                class="w-full bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg py-2.5 text-sm font-medium transition">
            <i class="fa-solid fa-check mr-1"></i> Record Movement
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
            <h3 class="font-bold text-gray-800 text-lg">Delete Part?</h3>
            <p class="text-sm text-gray-500 mt-1">
                <span id="delName" class="font-medium text-gray-700"></span>
                will be permanently removed. This cannot be undone.
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

<?php elseif ($view === 'movements'): ?>

<div class="bg-white rounded-2xl shadow overflow-hidden">
    <div class="overflow-x-auto">
        <table class="responsive-table w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 border-b">
                    <th class="py-3 px-4">Date</th>
                    <th class="px-2">Part</th>
                    <th class="px-2">Type</th>
                    <th class="px-2">Qty</th>
                    <th class="px-2">Unit Price</th>
                    <th class="px-2">Reference</th>
                    <th class="px-4">Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $m):
                $c = [
                    'Purchase' => 'text-emerald-600 bg-emerald-50',
                    'Usage' => 'text-red-600 bg-red-50',
                    'Adjustment' => 'text-amber-600 bg-amber-50'
                ][$m['movement_type']] ?? 'text-gray-600 bg-gray-50';
            ?>
                <tr class="border-b hover:bg-gray-50">
                    <td class="py-3 px-4 text-gray-500 whitespace-nowrap" data-label="Date"><?= e($m['created_at']) ?></td>
                    <td class="px-2 font-medium" data-label="Part">
                        <?= e($m['part']) ?>
                        <span class="text-gray-400 text-xs">(<?= e($m['brand']) ?> <?= e($m['part_no']) ?>)</span>
                    </td>
                    <td class="px-2" data-label="Type">
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $c ?>"><?= e($m['movement_type']) ?></span>
                    </td>
                    <td class="px-2" data-label="Qty"><?= (int)$m['quantity'] ?></td>
                    <td class="px-2" data-label="Price"><?= money($m['unit_price']) ?></td>
                    <td class="px-2" data-label="Reference"><?= e($m['reference']) ?></td>
                    <td class="px-4 text-gray-500" data-label="Notes"><?= e($m['notes']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr>
                    <td colspan="7" class="py-10 text-center text-gray-400">
                        <i class="fa-solid fa-clock-rotate-left text-3xl mb-2 block"></i>
                        No movements recorded yet.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>

<div class="bg-white rounded-2xl shadow overflow-hidden">

    <div class="px-5 pt-5 pb-3 border-b flex items-center justify-between">
        <h2 class="font-bold text-gray-700">
            <i class="fa-solid fa-triangle-exclamation text-amber-500 mr-2"></i>
            Low Stock Report
            <span class="text-sm font-normal text-gray-400">(stock &le; minimum level)</span>
        </h2>
        <span class="px-3 py-1 rounded-full text-xs font-bold bg-red-100 text-red-700"><?= count($lowRows) ?> item(s)</span>
    </div>

    <div class="overflow-x-auto">
        <table class="responsive-table w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 border-b">
                    <th class="py-3 px-4">Part</th>
                    <th class="px-2">Part No</th>
                    <th class="px-2">Brand</th>
                    <th class="px-2">Current Stock</th>
                    <th class="px-2">Min Level</th>
                    <th class="px-2">Shortage</th>
                    <th class="px-2">Supplier</th>
                    <th class="px-4 text-right">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lowRows as $r): ?>
                <tr class="border-b hover:bg-red-50/50">
                    <td class="py-3 px-4 font-medium" data-label="Part"><?= e($r['name']) ?></td>
                    <td class="px-2" data-label="Part No"><?= e($r['part_no']) ?></td>
                    <td class="px-2" data-label="Brand"><?= e($r['brand']) ?></td>
                    <td class="px-2" data-label="Stock">
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-700"><?= (int)$r['stock_qty'] ?></span>
                    </td>
                    <td class="px-2" data-label="Min Level"><?= (int)$r['min_stock'] ?></td>
                    <td class="px-2 font-semibold text-red-600" data-label="Shortage"><?= max(0, (int)$r['min_stock'] - (int)$r['stock_qty']) ?></td>
                    <td class="px-2" data-label="Supplier"><?= e($r['supplier']) ?></td>
                    <td class="px-4 text-right" data-label="Action">
                        <button onclick="window.location.href='parts.php'"
                                class="px-3 py-1.5 rounded-lg text-xs font-medium bg-emerald-600 text-white hover:bg-emerald-700 transition">
                            <i class="fa-solid fa-cart-plus mr-1"></i> Restock
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$lowRows): ?>
                <tr>
                    <td colspan="8" class="py-10 text-center text-gray-400">
                        <i class="fa-solid fa-circle-check text-3xl mb-2 block text-green-500"></i>
                        No low-stock items. Inventory is healthy.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php page_footer(); ?>
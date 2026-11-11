
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

    // Basic validation
    if ($data[0] === '') {
        flash('Customer name is required.', 'error');
        redirect('customers.php');
    }

    try {

        if ($id > 0) {

            // Update existing customer
            $stmt = $pdo->prepare(
                'UPDATE customers
                 SET name = ?, phone = ?, email = ?, address = ?
                 WHERE id = ?'
            );

            $stmt->execute([...$data, $id]);

            flash('Customer updated successfully.');

            // Editing returns to customer management
            redirect('customers.php');

        } else {

            // Insert new customer
            $stmt = $pdo->prepare(
                'INSERT INTO customers (name, phone, email, address)
                 VALUES (?, ?, ?, ?)'
            );

            $stmt->execute($data);

            // Get newly created customer ID
            $newCustomerId = (int) $pdo->lastInsertId();

            flash('Customer created successfully. Register their vehicle now.');

            // Automatically open vehicle registration
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
| FETCH CUSTOMERS
|--------------------------------------------------------------------------
*/
$stmt = $pdo->query("
    SELECT
        c.*,
        (
            SELECT COUNT(*)
            FROM vehicles v
            WHERE v.customer_id = c.id
        ) AS vehicles
    FROM customers c
    ORDER BY c.id DESC
");

$customers = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| SUMMARY METRICS
|--------------------------------------------------------------------------
*/
$total_customers = count($customers);

$total_vehicles = array_sum(
    array_column($customers, 'vehicles')
);

page_header('Customer Management');
?>

<!-- =====================================================
     SUMMARY CARDS
===================================================== -->

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">

    <!-- Total Customers -->
    <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex items-center gap-4">

        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 text-white shadow-lg shadow-blue-500/25 flex items-center justify-center shrink-0">
            <i class="fa-solid fa-users text-lg"></i>
        </div>

        <div>
            <div class="text-xl font-extrabold text-gray-800">
                <?= number_format($total_customers) ?>
            </div>
            <div class="text-xs font-medium text-gray-400">
                Total Customers
            </div>
        </div>

    </div>

    <!-- Total Vehicles -->
    <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex items-center gap-4">

        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-600 text-white shadow-lg shadow-indigo-500/25 flex items-center justify-center shrink-0">
            <i class="fa-solid fa-car-side text-lg"></i>
        </div>

        <div>
            <div class="text-xl font-extrabold text-gray-800">
                <?= number_format($total_vehicles) ?>
            </div>
            <div class="text-xs font-medium text-gray-400">
                Linked Vehicles
            </div>
        </div>

    </div>

    <!-- Quick Action -->
    <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 flex items-center justify-between gap-4">

        <div class="flex items-center gap-4">

            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-600 text-white shadow-lg shadow-emerald-500/25 flex items-center justify-center shrink-0">
                <i class="fa-solid fa-bolt text-lg"></i>
            </div>

            <div>
                <div class="text-sm font-bold text-gray-800">
                    Quick Action
                </div>
                <div class="text-xs text-gray-400">
                    Manage vehicle fleet
                </div>
            </div>

        </div>

        <a href="vehicles.php"
           class="text-xs font-semibold text-indigo-600 hover:text-indigo-700 bg-indigo-50 hover:bg-indigo-100 px-3 py-2 rounded-xl transition">

            All Vehicles
            <i class="fa-solid fa-arrow-right ml-1"></i>

        </a>

    </div>

</div>


<!-- =====================================================
     MAIN CONTENT
===================================================== -->

<div class="grid lg:grid-cols-3 gap-6 items-start">

    <!-- =================================================
         CUSTOMER FORM
    ================================================== -->

    <form method="post"
          class="bg-white rounded-2xl shadow-sm border <?= $edit ? 'border-amber-200 ring-2 ring-amber-500/10' : 'border-gray-100' ?> overflow-hidden lg:sticky lg:top-6">

        <!-- Form Header -->

        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between <?= $edit ? 'bg-amber-50/40' : '' ?>">

            <div class="flex items-center gap-3">

                <div class="w-9 h-9 rounded-lg <?= $edit ? 'bg-amber-100 text-amber-600' : 'bg-blue-50 text-blue-600' ?> flex items-center justify-center">

                    <i class="fa-solid <?= $edit ? 'fa-user-pen' : 'fa-user-plus' ?>"></i>

                </div>

                <div>

                    <h2 class="font-bold text-gray-800 text-sm">
                        <?= $edit ? 'Edit Customer' : 'Add New Customer' ?>
                    </h2>

                    <p class="text-xs text-gray-400">
                        <?= $edit ? 'Update customer details below' : 'Register a new client profile' ?>
                    </p>

                </div>

            </div>

            <?php if ($edit): ?>

                <span class="px-2 py-0.5 rounded text-[11px] font-semibold bg-amber-100 text-amber-700">
                    #<?= (int) $edit['id'] ?>
                </span>

            <?php endif; ?>

        </div>


        <!-- Form Fields -->

        <div class="p-6 space-y-4">

            <input type="hidden"
                   name="id"
                   value="<?= e($edit['id'] ?? '') ?>">


            <!-- Full Name -->

            <div>

                <label class="block text-xs font-semibold text-gray-600 mb-1.5">
                    Full Name <span class="text-red-500">*</span>
                </label>

                <div class="relative">

                    <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-gray-400 pointer-events-none">
                        <i class="fa-regular fa-user text-xs"></i>
                    </span>

                    <input
                        name="name"
                        required
                        placeholder="e.g. John Doe"
                        value="<?= e($edit['name'] ?? '') ?>"
                        class="w-full pl-9 pr-3.5 py-2.5 bg-gray-50/50 border border-gray-200 rounded-xl text-sm text-gray-800 placeholder-gray-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition"
                    >

                </div>

            </div>


            <!-- Phone Number -->

            <div>

                <label class="block text-xs font-semibold text-gray-600 mb-1.5">
                    Phone Number
                </label>

                <div class="relative">

                    <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-gray-400 pointer-events-none">
                        <i class="fa-solid fa-phone text-xs"></i>
                    </span>

                    <input
                        name="phone"
                        type="tel"
                        placeholder="e.g. +94 71 234 5678"
                        value="<?= e($edit['phone'] ?? '') ?>"
                        class="w-full pl-9 pr-3.5 py-2.5 bg-gray-50/50 border border-gray-200 rounded-xl text-sm text-gray-800 placeholder-gray-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition"
                    >

                </div>

            </div>


            <!-- Email -->

            <div>

                <label class="block text-xs font-semibold text-gray-600 mb-1.5">
                    Email Address
                </label>

                <div class="relative">

                    <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-gray-400 pointer-events-none">
                        <i class="fa-regular fa-envelope text-xs"></i>
                    </span>

                    <input
                        name="email"
                        type="email"
                        placeholder="e.g. john@example.com"
                        value="<?= e($edit['email'] ?? '') ?>"
                        class="w-full pl-9 pr-3.5 py-2.5 bg-gray-50/50 border border-gray-200 rounded-xl text-sm text-gray-800 placeholder-gray-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition"
                    >

                </div>

            </div>


            <!-- Address -->

            <div>

                <label class="block text-xs font-semibold text-gray-600 mb-1.5">
                    Address
                </label>

                <div class="relative">

                    <span class="absolute top-3 left-0 pl-3.5 flex items-center text-gray-400 pointer-events-none">
                        <i class="fa-solid fa-location-dot text-xs"></i>
                    </span>

                    <textarea
                        name="address"
                        rows="3"
                        placeholder="Street address, city..."
                        class="w-full pl-9 pr-3.5 py-2.5 bg-gray-50/50 border border-gray-200 rounded-xl text-sm text-gray-800 placeholder-gray-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition"
                    ><?= e($edit['address'] ?? '') ?></textarea>

                </div>

            </div>


            <!-- Buttons -->

            <div class="pt-2 flex items-center gap-2.5">

                <button
                    type="submit"
                    class="flex-1 <?= $edit ? 'bg-amber-500 hover:bg-amber-600 shadow-amber-500/20' : 'bg-blue-600 hover:bg-blue-700 shadow-blue-500/20' ?> text-white rounded-xl py-2.5 px-4 text-sm font-semibold shadow-sm transition flex items-center justify-center gap-2 cursor-pointer">

                    <i class="fa-solid fa-floppy-disk"></i>

                    <span>
                        <?= $edit ? 'Update Customer' : 'Save Customer' ?>
                    </span>

                </button>


                <?php if ($edit): ?>

                    <a href="customers.php"
                       class="px-4 py-2.5 rounded-xl border border-gray-200 hover:bg-gray-50 text-gray-600 text-sm font-semibold transition">

                        Cancel

                    </a>

                <?php endif; ?>

            </div>


            <?php if (!$edit): ?>

                <div class="bg-blue-50 border border-blue-100 rounded-xl p-3">

                    <p class="text-xs text-blue-700 flex items-start gap-2">

                        <i class="fa-solid fa-circle-info mt-0.5"></i>

                        <span>
                            After saving a new customer, you will automatically proceed to register their vehicle.
                        </span>

                    </p>

                </div>

            <?php endif; ?>

        </div>

    </form>


    <!-- =================================================
         CUSTOMER DIRECTORY
    ================================================== -->

    <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">

        <!-- Table Header -->

        <div class="px-6 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">

            <div class="flex items-center gap-3">

                <div class="w-9 h-9 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                    <i class="fa-solid fa-address-book"></i>
                </div>

                <div>

                    <h2 class="font-bold text-gray-800 text-sm">
                        Customer Directory
                    </h2>

                    <p class="text-xs text-gray-400">
                        Manage client profiles and linked vehicles
                    </p>

                </div>

            </div>


            <!-- Search -->

            <div class="relative w-full sm:w-64">

                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400 pointer-events-none">
                    <i class="fa-solid fa-magnifying-glass text-xs"></i>
                </span>

                <input
                    type="text"
                    id="customerSearch"
                    placeholder="Search name, phone, email..."
                    class="w-full pl-8 pr-3 py-1.5 bg-gray-50 border border-gray-200 rounded-xl text-xs text-gray-700 placeholder-gray-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition"
                >

            </div>

        </div>


        <!-- Customer Table -->

        <div class="overflow-x-auto">

            <table class="w-full text-sm" id="customerTable">

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

                    // Generate customer initials
                    $words = explode(' ', trim($c['name']));

                    $initials = strtoupper(
                        substr($words[0] ?? 'C', 0, 1) .
                        (isset($words[1]) ? substr($words[1], 0, 1) : '')
                    );

                    $is_editing = (
                        $edit &&
                        (int) $edit['id'] === (int) $c['id']
                    );

                ?>

                    <tr class="customer-row hover:bg-gray-50/80 transition <?= $is_editing ? 'bg-amber-50/40' : '' ?>">

                        <!-- Customer Name -->

                        <td class="py-3.5 px-6">

                            <div class="flex items-center gap-3">

                                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-white font-bold text-xs flex items-center justify-center shrink-0 shadow-sm">

                                    <?= e($initials) ?>

                                </div>

                                <div class="min-w-0">

                                    <div class="font-semibold text-gray-800 truncate flex items-center gap-2">

                                        <?= e($c['name']) ?>

                                        <?php if ($is_editing): ?>

                                            <span class="text-[10px] font-semibold bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded">
                                                Editing
                                            </span>

                                        <?php endif; ?>

                                    </div>


                                    <?php if (!empty($c['address'])): ?>

                                        <div class="text-xs text-gray-400 truncate max-w-xs"
                                             title="<?= e($c['address']) ?>">

                                            <i class="fa-solid fa-location-dot text-[10px] mr-1 opacity-75"></i>

                                            <?= e($c['address']) ?>

                                        </div>

                                    <?php else: ?>

                                        <div class="text-xs text-gray-300 italic">
                                            No address provided
                                        </div>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </td>


                        <!-- Contact Information -->

                        <td class="py-3.5 px-4">

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

                                    <span class="text-xs text-gray-300 italic">
                                        No contact info
                                    </span>

                                <?php endif; ?>

                            </div>

                        </td>


                        <!-- Vehicles Count -->

                        <td class="py-3.5 px-4 text-center">

                            <a href="vehicles.php?customer_id=<?= (int) $c['id'] ?>"
                               class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold <?= (int) $c['vehicles'] > 0 ? 'bg-indigo-50 text-indigo-600 hover:bg-indigo-100 border border-indigo-100' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' ?> transition"
                               title="Manage customer vehicles">

                                <i class="fa-solid fa-car text-[10px]"></i>

                                <span><?= (int) $c['vehicles'] ?></span>

                            </a>

                        </td>


                        <!-- Actions -->

                        <td class="py-3.5 px-6 text-right">

                            <div class="inline-flex items-center justify-end gap-1">

                                <a href="customers.php?edit=<?= (int) $c['id'] ?>"
                                   class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-blue-600 hover:bg-blue-50 transition"
                                   title="Edit customer">

                                    <i class="fa-solid fa-pen text-xs"></i>

                                </a>


                                <a href="vehicles.php?customer_id=<?= (int) $c['id'] ?>"
                                   class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-emerald-600 hover:bg-emerald-50 transition"
                                   title="View vehicles">

                                    <i class="fa-solid fa-car text-xs"></i>

                                </a>


                                <a href="customers.php?delete=<?= (int) $c['id'] ?>"
                                   onclick="return confirm('Delete this customer and all their vehicles/services?')"
                                   class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-red-600 hover:bg-red-50 transition"
                                   title="Delete customer">

                                    <i class="fa-solid fa-trash text-xs"></i>

                                </a>

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
                                No customers found
                            </p>

                            <p class="text-xs text-gray-400 mt-1">
                                Add your first customer using the form on the left.
                            </p>

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>


<!-- =====================================================
     LIVE CUSTOMER SEARCH
===================================================== -->

<script>
document.getElementById('customerSearch')?.addEventListener('input', function (e) {

    const q = e.target.value.toLowerCase().trim();

    document.querySelectorAll('#customerTable .customer-row').forEach(row => {

        row.style.display = row.textContent.toLowerCase().includes(q)
            ? ''
            : 'none';

    });

});
</script>


<?php page_footer(); ?>

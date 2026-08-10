<?php
require '../../config/db.php';
require '../../includes/notification_helper.php';
require '../../includes/sales_helpers.php';
require_role(['Admin', 'Manager', 'Employee']);

$customers = mysqli_query($conn, 'SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name');
$catalog = mysqli_query($conn, "SELECT id, item_type, product_name, product_code, selling_price, quantity, unit FROM products WHERE is_active = 1 ORDER BY item_type, product_name");
$catalogList = [];
while ($p = mysqli_fetch_assoc($catalog)) { $catalogList[] = $p; }

if (isset($_POST['save'])) {
    $customerId = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT) ?: null;
    $saleDate = $_POST['sale_date'] ?? date('Y-m-d');
    $paymentMethod = $_POST['payment_method'] ?? '';
    $discountAmount = filter_input(INPUT_POST, 'discount_amount', FILTER_VALIDATE_FLOAT);
    $discountAmount = $discountAmount === false || $discountAmount === null ? 0 : $discountAmount;
    $amountPaidInput = filter_input(INPUT_POST, 'amount_paid', FILTER_VALIDATE_FLOAT);
    $amountPaidInput = $amountPaidInput === false || $amountPaidInput === null ? 0 : $amountPaidInput;

    $catalogIds = $_POST['catalog_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];

    $validDate = DateTime::createFromFormat('Y-m-d', $saleDate);
    $lineItems = [];
    $subtotal = 0;
    $lineError = null;

    foreach ($catalogIds as $index => $catalogId) {
        $catalogId = (int) $catalogId;
        $qty = (int) ($quantities[$index] ?? 0);
        if ($catalogId <= 0 || $qty <= 0) { continue; }

        $found = null;
        foreach ($catalogList as $p) { if ((int) $p['id'] === $catalogId) { $found = $p; break; } }
        if (!$found) { continue; }

        if ($found['item_type'] === 'Item' && $qty > (int) $found['quantity']) {
            $lineError = 'Not enough stock for "' . $found['product_name'] . '" (only ' . $found['quantity'] . ' available).';
            break;
        }

        $lineTotal = $qty * (float) $found['selling_price'];
        $subtotal += $lineTotal;
        $lineItems[] = [
            'item_type' => $found['item_type'] === 'Service' ? 'Service' : 'Product',
            'product_id' => $catalogId,
            'service_name' => null,
            'unit' => $found['item_type'] === 'Item' ? $found['unit'] : null,
            'quantity' => $qty,
            'unit_price' => (float) $found['selling_price'],
            'line_total' => $lineTotal,
        ];
    }

    if (!$validDate || $validDate->format('Y-m-d') !== $saleDate || !in_array($paymentMethod, ['Cash', 'Mobile Money', 'Bank Transfer', 'Credit'], true)) {
        $error = 'Please provide a valid sale date and payment method.';
    } elseif (empty($lineItems)) {
        $error = 'Add at least one item or service with a valid quantity.';
    } elseif ($lineError) {
        $error = $lineError;
    } elseif ($discountAmount < 0 || $discountAmount > $subtotal) {
        $error = 'Discount cannot be negative or greater than the subtotal.';
    } else {
        $totalAmount = $subtotal - $discountAmount;
        if ($amountPaidInput < 0 || $amountPaidInput > $totalAmount + 0.01) {
            $error = 'Amount paid cannot be negative or greater than the total.';
        } else {
            $userId = current_user_id();
            $role = current_user_role();

            $needsApproval = $discountAmount > 0 && $role === 'Employee';
            $status = $needsApproval ? 'Pending Discount Approval' : sales_compute_status($amountPaidInput, $totalAmount);
            $discountRequestedBy = $discountAmount > 0 ? $userId : null;
            $discountApprovedBy = ($discountAmount > 0 && !$needsApproval) ? $userId : null;
            $discountApprovedAt = ($discountAmount > 0 && !$needsApproval) ? date('Y-m-d H:i:s') : null;

            mysqli_begin_transaction($conn);

            $saleStatement = mysqli_prepare($conn, 'INSERT INTO sales
                (customer_id, sale_date, subtotal, discount_amount, total_amount, amount_paid, payment_method, status, discount_requested_by, discount_approved_by, discount_approved_at, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($saleStatement, 'isddddssiisi',
                $customerId, $saleDate, $subtotal, $discountAmount, $totalAmount, $amountPaidInput, $paymentMethod, $status,
                $discountRequestedBy, $discountApprovedBy, $discountApprovedAt, $userId);
            mysqli_stmt_execute($saleStatement);
            $saleId = mysqli_insert_id($conn);

            foreach ($lineItems as $item) {
                $itemStatement = mysqli_prepare($conn, 'INSERT INTO sale_items (sale_id, item_type, product_id, service_name, quantity, unit, unit_price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($itemStatement, 'isissidd', $saleId, $item['item_type'], $item['product_id'], $item['service_name'], $item['quantity'], $item['unit'], $item['unit_price'], $item['line_total']);
                mysqli_stmt_execute($itemStatement);
            }

            if (!$needsApproval) {
                sales_finalize($conn, $saleId);
            }

            mysqli_commit($conn);

            header('Location: invoice.php?id=' . $saleId . '&success=' . ($needsApproval ? 'Sale saved. Waiting for manager approval of the discount before invoicing.' : 'Sale recorded successfully.'));
            exit;
        }
    }
}

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>New Sale</h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Sales</a>
</div>

<?php if (isset($error)) { ?>
<div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
    <i class="bi bi-exclamation-circle-fill"></i>
    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
</div>
<?php } ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <form method="POST" id="saleForm">
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Customer</label>
                    <select name="customer_id" class="form-select rm-input">
                        <option value="">Walk-in Customer</option>
                        <?php while ($customer = mysqli_fetch_assoc($customers)) { ?>
                            <option value="<?= (int) $customer['id']; ?>"><?= htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Sale Date</label>
                    <input type="date" name="sale_date" class="form-control rm-input" value="<?= date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Payment Method</label>
                    <select name="payment_method" class="form-select rm-input" required>
                        <option value="Cash">Cash</option>
                        <option value="Mobile Money">Mobile Money</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Credit">Credit</option>
                    </select>
                </div>
            </div>

            <label class="form-label small fw-semibold text-muted">Items</label>
            <table class="table table-bordered bg-white align-middle" id="itemsTable">
                <thead>
                    <tr>
                        <th style="width:110px;">Type</th>
                        <th>Item / Service</th>
                        <th style="width:110px;">Unit</th>
                        <th style="width:90px;">Qty</th>
                        <th style="width:130px;">Unit Price</th>
                        <th style="width:130px;">Line Total</th>
                        <th style="width:40px;"></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            <button type="button" id="addRow" class="rm-btn rm-btn-outline-primary rm-btn-sm mb-4"><i class="bi bi-plus-circle me-1"></i>Add Item</button>

            <div class="row g-3 mb-4 justify-content-end">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Discount (RWF)</label>
                    <input type="number" name="discount_amount" id="discountInput" class="form-control rm-input" min="0" step="0.01" value="0">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Amount Paid (RWF)</label>
                    <input type="number" name="amount_paid" id="amountPaidInput" class="form-control rm-input" min="0" step="0.01" value="0" required>
                </div>
            </div>

            <div class="text-end mb-4" style="font-size:14px;">
                <div>Subtotal: <strong id="subtotalDisplay">RWF 0.00</strong></div>
                <div>Total: <strong id="totalDisplay" style="font-size:18px; color:var(--accent-blue);">RWF 0.00</strong></div>
            </div>

            <div class="d-grid gap-2 d-md-flex justify-content-end">
                <button type="submit" name="save" class="rm-btn rm-btn-primary"><i class="bi bi-check-circle-fill me-2"></i>Record Sale</button>
                <a href="index.php" class="rm-btn rm-btn-secondary">
                    <i class="bi bi-x-circle-fill me-2"></i>Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<script>
const CATALOG = <?= json_encode(array_map(function ($p) {
    return ['id' => (int) $p['id'], 'type' => $p['item_type'], 'name' => $p['product_name'], 'code' => $p['product_code'], 'price' => (float) $p['selling_price'], 'stock' => (int) $p['quantity'], 'unit' => $p['unit']];
}, $catalogList)); ?>;

const itemsBody = document.querySelector('#itemsTable tbody');
const discountInput = document.getElementById('discountInput');
const amountPaidInput = document.getElementById('amountPaidInput');

function catalogOptions(itemType) {
    let html = '<option value="">Select ' + (itemType === 'Service' ? 'service' : 'item') + '</option>';
    CATALOG.filter(function (p) { return p.type === itemType; }).forEach(function (p) {
        const stockLabel = itemType === 'Item' ? (' — ' + p.stock + ' ' + p.unit + ' in stock') : '';
        html += '<option value="' + p.id + '" data-price="' + p.price + '" data-stock="' + p.stock + '" data-unit="' + (p.unit || '') + '">'
            + p.name + ' (' + p.code + ')' + stockLabel + '</option>';
    });
    return html;
}

function buildRow() {
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML =
        '<td>' +
            '<select class="form-select rm-input type-select">' +
                '<option value="Item">Item</option>' +
                '<option value="Service">Service</option>' +
            '</select>' +
        '</td>' +
        '<td class="target-cell">' +
            '<select class="form-select rm-input catalog-select" name="catalog_id[]">' + catalogOptions('Item') + '</select>' +
        '</td>' +
        '<td class="unit-cell"><span class="badge bg-light text-dark border unit-badge">—</span></td>' +
        '<td><input type="number" class="form-control rm-input qty-input" name="quantity[]" min="1" value="1" required></td>' +
        '<td><input type="number" class="form-control rm-input price-input" readonly value="0.00"></td>' +
        '<td><span class="line-total">0.00</span></td>' +
        '<td><button type="button" class="btn btn-outline-danger btn-sm remove-row">&times;</button></td>';
    return tr;
}

function bindRow(row) {
    const typeSelect = row.querySelector('.type-select');
    const targetCell = row.querySelector('.target-cell');
    const unitCell = row.querySelector('.unit-cell');
    const qty = row.querySelector('.qty-input');
    const priceInput = row.querySelector('.price-input');
    const lineTotalEl = row.querySelector('.line-total');
    const removeBtn = row.querySelector('.remove-row');

    function rebuildCatalogSelect() {
        targetCell.innerHTML = '<select class="form-select rm-input catalog-select" name="catalog_id[]">' + catalogOptions(typeSelect.value) + '</select>';
        targetCell.querySelector('.catalog-select').addEventListener('change', updateFromCatalog);
        priceInput.value = '0.00';
        unitCell.innerHTML = typeSelect.value === 'Service'
            ? '<span class="text-muted small">N/A</span>'
            : '<span class="badge bg-light text-dark border unit-badge">—</span>';
        updateTotal();
    }

    function updateFromCatalog() {
        const select = targetCell.querySelector('.catalog-select');
        const opt = select ? select.options[select.selectedIndex] : null;
        const price = opt ? parseFloat(opt.dataset.price || 0) : 0;
        priceInput.value = price.toFixed(2);
        if (typeSelect.value === 'Item') {
            const unit = opt ? (opt.dataset.unit || '—') : '—';
            const badge = unitCell.querySelector('.unit-badge');
            if (badge) { badge.textContent = unit; }
        }
        updateTotal();
    }

    function updateTotal() {
        const price = parseFloat(priceInput.value || 0);
        const q = parseInt(qty.value || 0);
        lineTotalEl.textContent = (price * q).toFixed(2);
        recalcTotals();
    }

    typeSelect.addEventListener('change', rebuildCatalogSelect);
    qty.addEventListener('input', updateTotal);
    targetCell.addEventListener('change', function (e) {
        if (e.target.classList.contains('catalog-select')) { updateFromCatalog(); }
    });
    removeBtn.addEventListener('click', function () {
        if (itemsBody.querySelectorAll('.item-row').length > 1) {
            row.remove();
            recalcTotals();
        }
    });
}

function recalcTotals() {
    let subtotal = 0;
    itemsBody.querySelectorAll('.item-row').forEach(function (row) {
        subtotal += parseFloat(row.querySelector('.line-total').textContent || 0);
    });
    const discount = parseFloat(discountInput.value || 0);
    const total = Math.max(0, subtotal - discount);
    document.getElementById('subtotalDisplay').textContent = 'RWF ' + subtotal.toFixed(2);
    document.getElementById('totalDisplay').textContent = 'RWF ' + total.toFixed(2);
    amountPaidInput.max = total;
}

document.getElementById('addRow').addEventListener('click', function () {
    const row = buildRow();
    itemsBody.appendChild(row);
    bindRow(row);
});

discountInput.addEventListener('input', recalcTotals);

// Start with one row.
document.getElementById('addRow').click();
</script>

<?php include '../../includes/footer.php'; ?>

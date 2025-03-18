<!-- incharge/add-purchase.php -->
<?php
session_start();
$page_title = "Add Material Purchase";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Check if material_id is provided for pre-selection
$material_id = isset($_GET['material_id']) ? $_GET['material_id'] : '';

// Get all raw materials
$materials_query = "SELECT id, name, unit, stock_quantity FROM raw_materials ORDER BY name";
$materials_stmt = $db->prepare($materials_query);
$materials_stmt->execute();
$materials = $materials_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get pre-selected material details if provided
$selected_material = null;
if($material_id) {
    foreach($materials as $material) {
        if($material['id'] == $material_id) {
            $selected_material = $material;
            break;
        }
    }
}
?>

<div class="breadcrumb">
    <a href="purchases.php">Purchases</a> &raquo; Add New Purchase
</div>

<?php if(isset($_GET['error'])): ?>
<div class="alert alert-error">
    <?php echo htmlspecialchars($_GET['error']); ?>
</div>
<?php endif; ?>

<div class="form-container">
    <h2>Add Material Purchase</h2>
    
    <form id="purchaseForm" action="../api/save-purchase.php" method="post">
        <div class="form-section">
            <h3>Purchase Information</h3>
            
            <div class="form-row">
                <div class="form-col">
                    <div class="form-group">
                        <label for="material_id">Material:</label>
                        <select id="material_id" name="material_id" required>
                            <option value="">Select Material</option>
                            <?php foreach($materials as $material): ?>
                            <option value="<?php echo $material['id']; ?>" 
                                    data-unit="<?php echo htmlspecialchars($material['unit']); ?>"
                                    <?php echo ($material_id == $material['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($material['name']); ?> 
                                (<?php echo htmlspecialchars($material['unit']); ?>) - 
                                Current Stock: <?php echo number_format($material['stock_quantity'], 2); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-col">
                    <div class="form-group">
                        <label for="purchase_date">Purchase Date:</label>
                        <input type="date" id="purchase_date" name="purchase_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-col">
                    <div class="form-group">
                        <label for="quantity">Quantity:</label>
                        <div class="input-with-unit">
                            <input type="number" id="quantity" name="quantity" step="0.01" min="0.01" required>
                            <span id="unit-display"><?php echo $selected_material ? htmlspecialchars($selected_material['unit']) : ''; ?></span>
                        </div>
                    </div>
                </div>
                <div class="form-col">
                    <div class="form-group">
                        <label for="unit_price">Unit Price:</label>
                        <div class="input-with-unit">
                            <input type="number" id="unit_price" name="unit_price" step="0.01" min="0.01" required>
                            <span>per unit</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-col">
                    <div class="form-group">
                        <label for="total_amount">Total Amount:</label>
                        <div class="input-with-unit">
                            <input type="number" id="total_amount" name="total_amount" step="0.01" min="0.01" readonly>
                            <span>calculated</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="form-section">
            <h3>Vendor Information</h3>
            
            <div class="form-row">
                <div class="form-col">
                    <div class="form-group">
                        <label for="vendor_name">Vendor Name:</label>
                        <input type="text" id="vendor_name" name="vendor_name" required>
                    </div>
                </div>
                <div class="form-col">
                    <div class="form-group">
                        <label for="vendor_contact">Vendor Contact:</label>
                        <input type="text" id="vendor_contact" name="vendor_contact">
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-col">
                    <div class="form-group">
                        <label for="invoice_number">Invoice Number:</label>
                        <input type="text" id="invoice_number" name="invoice_number">
                    </div>
                </div>
            </div>
        </div>
        
        <div class="form-actions">
            <a href="purchases.php" class="button secondary">Cancel</a>
            <button type="submit" class="button">Save Purchase</button>
        </div>
    </form>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<style>
/* Purchase form specific styles */
.input-with-unit {
    display: flex;
    align-items: center;
}

.input-with-unit input {
    flex: 1;
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
    border-right: none;
}

.input-with-unit span {
    padding: 0.5rem 0.75rem;
    background-color: var(--surface);
    border: 1px solid var(--border);
    border-left: none;
    border-top-right-radius: var(--border-radius-sm);
    border-bottom-right-radius: var(--border-radius-sm);
    color: var(--text-secondary);
    font-size: var(--font-size-sm);
    white-space: nowrap;
}

.alert {
    padding: 1rem;
    border-radius: var(--border-radius-sm);
    margin-bottom: 1.5rem;
}

.alert-error {
    background-color: rgba(234, 67, 53, 0.1);
    color: var(--error);
    border: 1px solid rgba(234, 67, 53, 0.3);
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Elements
    const materialSelect = document.getElementById('material_id');
    const unitDisplay = document.getElementById('unit-display');
    const quantityInput = document.getElementById('quantity');
    const unitPriceInput = document.getElementById('unit_price');
    const totalAmountInput = document.getElementById('total_amount');
    const purchaseForm = document.getElementById('purchaseForm');
    
    // Update unit display when material changes
    materialSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption.value) {
            const unit = selectedOption.getAttribute('data-unit');
            unitDisplay.textContent = unit;
        } else {
            unitDisplay.textContent = '';
        }
        
        // Recalculate total if needed
        calculateTotal();
    });
    
    // Calculate total amount when quantity or unit price changes
    [quantityInput, unitPriceInput].forEach(input => {
        input.addEventListener('input', calculateTotal);
    });
    
    function calculateTotal() {
        const quantity = parseFloat(quantityInput.value) || 0;
        const unitPrice = parseFloat(unitPriceInput.value) || 0;
        const total = quantity * unitPrice;
        
        totalAmountInput.value = total.toFixed(2);
    }
    
    // Form validation
    purchaseForm.addEventListener('submit', function(event) {
        let isValid = true;
        
        // Validate material selection
        if (!materialSelect.value) {
            showInputError(materialSelect, 'Please select a material');
            isValid = false;
        }
        
        // Validate quantity
        const quantity = parseFloat(quantityInput.value);
        if (isNaN(quantity) || quantity <= 0) {
            showInputError(quantityInput, 'Please enter a valid quantity');
            isValid = false;
        }
        
        // Validate unit price
        const unitPrice = parseFloat(unitPriceInput.value);
        if (isNaN(unitPrice) || unitPrice <= 0) {
            showInputError(unitPriceInput, 'Please enter a valid unit price');
            isValid = false;
        }
        
        // Validate vendor name
        const vendorName = document.getElementById('vendor_name').value.trim();
        if (!vendorName) {
            showInputError(document.getElementById('vendor_name'), 'Vendor name is required');
            isValid = false;
        }
        
        if (!isValid) {
            event.preventDefault();
        } else {
            // Log activity
            if (typeof logUserActivity === 'function') {
                const materialName = materialSelect.options[materialSelect.selectedIndex].text.split(' (')[0];
                logUserActivity(
                    'create', 
                    'purchases', 
                    `Purchased ${quantity} ${unitDisplay.textContent} of ${materialName}`
                );
            }
        }
    });
    
    // Helper function for displaying input errors
    function showInputError(inputElement, message) {
        // For select elements with input-with-unit, we need to handle the parent differently
        const isInputWithUnit = inputElement.parentNode.classList.contains('input-with-unit');
        const targetElement = isInputWithUnit ? inputElement.parentNode : inputElement;
        
        if (isInputWithUnit) {
            inputElement.classList.add('invalid-input');
        } else {
            targetElement.classList.add('invalid-input');
        }
        
        // Remove any existing error message
        let parent = targetElement.parentNode;
        let nextElement = parent.querySelector('.error-message');
        if (nextElement) {
            nextElement.remove();
        }
        
        // Create and insert error message
        const errorElement = document.createElement('div');
        errorElement.className = 'error-message';
        errorElement.textContent = message;
        parent.appendChild(errorElement);
    }
    
    // Initialize calculations
    calculateTotal();
});
</script>

<?php include_once '../includes/footer.php'; ?>
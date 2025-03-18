<!-- incharge/raw-materials.php -->
<?php
session_start();
$page_title = "Raw Materials";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get all raw materials
$query = "SELECT id, name, description, unit, stock_quantity, created_at, updated_at 
          FROM raw_materials 
          ORDER BY name";
$stmt = $db->prepare($query);
$stmt->execute();
?>

<div class="page-actions">
    <button id="addMaterialBtn" class="button">Add New Material</button>
</div>

<div class="dashboard-card full-width">
    <div class="card-header">
        <h2>Raw Materials Inventory</h2>
    </div>
    <div class="card-content">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Unit</th>
                    <th>Stock Quantity</th>
                    <th>Last Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                    <td><?php echo htmlspecialchars($row['description']); ?></td>
                    <td><?php echo htmlspecialchars($row['unit']); ?></td>
                    <td class="<?php echo $row['stock_quantity'] < 10 ? 'low-stock' : ''; ?>">
                        <?php echo number_format($row['stock_quantity'], 2); ?>
                    </td>
                    <td><?php echo htmlspecialchars($row['updated_at']); ?></td>
                    <td>
                        <div class="action-buttons">
                            <button class="button small edit-material" data-id="<?php echo $row['id']; ?>">Edit</button>
                            <a href="add-purchase.php?material_id=<?php echo $row['id']; ?>" class="button small">Purchase</a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                
                <?php if($stmt->rowCount() === 0): ?>
                <tr>
                    <td colspan="6" class="no-records">No raw materials found. Click "Add New Material" to add one.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit Material Modal -->
<div id="materialModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h2 id="modalTitle">Add New Material</h2>
        <form id="materialForm" action="../api/save-material.php" method="post">
            <input type="hidden" id="material_id" name="material_id" value="">
            
            <div class="form-group">
                <label for="name">Material Name:</label>
                <input type="text" id="name" name="name" required>
            </div>
            
            <div class="form-group">
                <label for="description">Description:</label>
                <textarea id="description" name="description" rows="3"></textarea>
            </div>
            
            <div class="form-group">
                <label for="unit">Unit:</label>
                <select id="unit" name="unit" required>
                    <option value="meter">Meter</option>
                    <option value="kg">Kilogram</option>
                    <option value="piece">Piece</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="stock_quantity">Initial Stock Quantity:</label>
                <input type="number" id="stock_quantity" name="stock_quantity" step="0.01" min="0" value="0">
            </div>
            
                        <div class="form-actions">
                <button type="button" class="button secondary" id="cancelMaterial">Cancel</button>
                <button type="submit" class="button">Save Material</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Modal elements
    const modal = document.getElementById('materialModal');
    const addBtn = document.getElementById('addMaterialBtn');
    const closeBtn = document.querySelector('.close-modal');
    const cancelBtn = document.getElementById('cancelMaterial');
    const modalTitle = document.getElementById('modalTitle');
    const form = document.getElementById('materialForm');
    
    // Open modal for adding new material
    addBtn.addEventListener('click', function() {
        modalTitle.textContent = 'Add New Material';
        form.reset();
        document.getElementById('material_id').value = '';
        document.getElementById('stock_quantity').disabled = false;
        modal.style.display = 'block';
    });
    
    // Close modal on X click
    closeBtn.addEventListener('click', function() {
        modal.style.display = 'none';
    });
    
    // Close modal on Cancel click
    cancelBtn.addEventListener('click', function() {
        modal.style.display = 'none';
    });
    
    // Close modal on outside click
    window.addEventListener('click', function(event) {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
    
    // Edit material buttons
    const editButtons = document.querySelectorAll('.edit-material');
    editButtons.forEach(button => {
        button.addEventListener('click', function() {
            const materialId = this.getAttribute('data-id');
            modalTitle.textContent = 'Edit Material';
            
            // Fetch material data
            fetch(`../api/get-material.php?id=${materialId}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('material_id').value = data.id;
                    document.getElementById('name').value = data.name;
                    document.getElementById('description').value = data.description;
                    document.getElementById('unit').value = data.unit;
                    document.getElementById('stock_quantity').value = data.stock_quantity;
                    document.getElementById('stock_quantity').disabled = true; // Can't edit stock directly
                    modal.style.display = 'block';
                })
                .catch(error => {
                    console.error('Error fetching material data:', error);
                    alert('Failed to load material data. Please try again.');
                });
        });
    });
    
    // Form submission with validation
    form.addEventListener('submit', function(event) {
        // Client-side validation
        const nameField = document.getElementById('name');
        const unitField = document.getElementById('unit');
        
        if (!nameField.value.trim()) {
            event.preventDefault();
            nameField.classList.add('invalid-input');
            alert('Material name is required.');
            return;
        }
        
        if (!unitField.value) {
            event.preventDefault();
            unitField.classList.add('invalid-input');
            alert('Please select a unit.');
            return;
        }
        
        // Log activity
        if (typeof logUserActivity === 'function') {
            const action = document.getElementById('material_id').value ? 'update' : 'create';
            logUserActivity(action, 'raw-materials', `${action === 'update' ? 'Updated' : 'Created'} material: ${nameField.value}`);
        }
    });
});
</script>

<?php include_once '../includes/footer.php'; ?>
<?php
// Initialize session and error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    session_start();
    $page_title = "View Manufacturing Batch";
    include_once '../config/database.php';
    include_once '../config/auth.php';
    include_once '../includes/header.php';

    // Check if batch ID is provided
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("Invalid batch ID");
    }

    $batch_id = intval($_GET['id']);

    // Initialize database connection
    $database = new Database();
    $db = $database->getConnection();

    // Get batch details
    $batch_query = "SELECT b.*, p.name as product_name, p.sku as product_sku, 
                   u.full_name as created_by_name
                   FROM manufacturing_batches b
                   JOIN products p ON b.product_id = p.id
                   JOIN users u ON b.created_by = u.id
                   WHERE b.id = ?";
    $batch_stmt = $db->prepare($batch_query);
    $batch_stmt->execute([$batch_id]);

    if ($batch_stmt->rowCount() === 0) {
        throw new Exception("Batch not found");
    }

    $batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);

    // Get batch materials
    $materials_query = "SELECT bm.*, rm.name as material_name, rm.unit as material_unit
                       FROM batch_materials bm
                       JOIN raw_materials rm ON bm.material_id = rm.id
                       WHERE bm.batch_id = ?";
    $materials_stmt = $db->prepare($materials_query);
    $materials_stmt->execute([$batch_id]);
    $materials = $materials_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get manufacturing costs
    $costs_query = "SELECT mc.*, u.full_name as recorded_by_name
                   FROM manufacturing_costs mc
                   JOIN users u ON mc.recorded_by = u.id
                   WHERE mc.batch_id = ?
                   ORDER BY mc.recorded_date DESC";
    $costs_stmt = $db->prepare($costs_query);
    $costs_stmt->execute([$batch_id]);
    $costs = $costs_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate total cost
    $total_cost = 0;
    foreach ($costs as $cost) {
        $total_cost += $cost['amount'];
    }
    
    // Calculate progress percentage based on status
    $progress_percentage = 0;
    switch ($batch['status']) {
        case 'pending':
            $progress_percentage = 10;
            break;
        case 'cutting':
            $progress_percentage = 30;
            break;
        case 'stitching':
            $progress_percentage = 50;
            break;
        case 'ironing':
            $progress_percentage = 70;
            break;
        case 'packaging':
            $progress_percentage = 90;
            break;
        case 'completed':
            $progress_percentage = 100;
            break;
    }

} catch (Exception $e) {
    // Log the error
    error_log('View batch error: ' . $e->getMessage());
    
    // Display user-friendly error
    echo '<div class="error-container">';
    echo '<h2>Error</h2>';
    echo '<p>' . $e->getMessage() . '</p>';
    echo '<a href="manufacturing.php" class="button secondary">Back to Manufacturing Batches</a>';
    echo '</div>';
    
    // Exit to prevent further processing
    include_once '../includes/footer.php';
    exit;
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt; 
    <a href="manufacturing.php">Manufacturing Batches</a> &gt; 
    <span>View Batch <?php echo htmlspecialchars($batch['batch_number']); ?></span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Batch <?php echo htmlspecialchars($batch['batch_number']); ?></h2>
        <span class="status-badge status-<?php echo $batch['status']; ?>"><?php echo ucfirst($batch['status']); ?></span>
    </div>
    <div class="page-actions">
        <?php if ($batch['status'] !== 'completed'): ?>
        <a href="update-batch.php?id=<?php echo $batch_id; ?>" class="button primary">
            <i class="fas fa-edit"></i> Update Status
        </a>
        <?php endif; ?>
        <a href="manufacturing.php" class="button secondary">
            <i class="fas fa-arrow-left"></i> Back to Batches
        </a>
    </div>
</div>

<div class="batch-details-container">
    <!-- Progress bar -->
    <div class="batch-progress-container">
        <div class="batch-progress-label">Production Progress</div>
        <div class="batch-progress-bar">
            <div class="batch-progress-fill" style="width: <?php echo $progress_percentage; ?>%"></div>
        </div>
        <div class="batch-progress-steps">
            <div class="progress-step <?php echo in_array($batch['status'], ['pending', 'cutting', 'stitching', 'ironing', 'packaging', 'completed']) ? 'completed' : ''; ?>">
                <div class="step-indicator"></div>
                <div class="step-label">Pending</div>
            </div>
            <div class="progress-step <?php echo in_array($batch['status'], ['cutting', 'stitching', 'ironing', 'packaging', 'completed']) ? 'completed' : ''; ?>">
                <div class="step-indicator"></div>
                <div class="step-label">Cutting</div>
            </div>
            <div class="progress-step <?php echo in_array($batch['status'], ['stitching', 'ironing', 'packaging', 'completed']) ? 'completed' : ''; ?>">
                <div class="step-indicator"></div>
                <div class="step-label">Stitching</div>
            </div>
            <div class="progress-step <?php echo in_array($batch['status'], ['ironing', 'packaging', 'completed']) ? 'completed' : ''; ?>">
                <div class="step-indicator"></div>
                <div class="step-label">Ironing</div>
            </div>
            <div class="progress-step <?php echo in_array($batch['status'], ['packaging', 'completed']) ? 'completed' : ''; ?>">
                <div class="step-indicator"></div>
                <div class="step-label">Packaging</div>
            </div>
            <div class="progress-step <?php echo $batch['status'] === 'completed' ? 'completed' : ''; ?>">
                <div class="step-indicator"></div>
                <div class="step-label">Completed</div>
            </div>
        </div>
    </div>

    <div class="batch-info-grid">
        <!-- Batch Information Card -->
        <div class="info-card">
            <div class="info-card-header">
                <h3>Batch Information</h3>
            </div>
            <div class="info-card-content">
                <div class="info-row">
                    <div class="info-label">Batch Number:</div>
                    <div class="info-value"><?php echo htmlspecialchars($batch['batch_number']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Product:</div>
                    <div class="info-value">
                        <div class="primary-text"><?php echo htmlspecialchars($batch['product_name']); ?></div>
                        <div class="secondary-text">SKU: <?php echo htmlspecialchars($batch['product_sku']); ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Quantity:</div>
                    <div class="info-value"><?php echo number_format($batch['quantity_produced']); ?> units</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Status:</div>
                    <div class="info-value">
                        <span class="status-badge status-<?php echo $batch['status']; ?>"><?php echo ucfirst($batch['status']); ?></span>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Start Date:</div>
                    <div class="info-value"><?php echo htmlspecialchars($batch['start_date']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Expected Completion:</div>
                    <div class="info-value"><?php echo htmlspecialchars($batch['expected_completion_date']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Actual Completion:</div>
                    <div class="info-value"><?php echo $batch['completion_date'] ? htmlspecialchars($batch['completion_date']) : 'Not completed yet'; ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Created By:</div>
                    <div class="info-value"><?php echo htmlspecialchars($batch['created_by_name']); ?></div>
                </div>
                <?php if (!empty($batch['notes'])): ?>
                <div class="info-row">
                    <div class="info-label">Notes:</div>
                    <div class="info-value"><?php echo nl2br(htmlspecialchars($batch['notes'])); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Materials Card -->
        <div class="info-card">
            <div class="info-card-header">
                <h3>Materials Used</h3>
            </div>
            <div class="info-card-content">
                <?php if (count($materials) > 0): ?>
                <div class="materials-list">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Material</th>
                                <th>Quantity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($materials as $material): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($material['material_name']); ?></td>
                                <td><?php echo number_format($material['quantity_required'], 2) . ' ' . htmlspecialchars($material['material_unit']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <p>No materials recorded for this batch.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Manufacturing Costs Card -->
        <div class="info-card">
            <div class="info-card-header">
                <h3>Manufacturing Costs</h3>
                <div class="card-actions">
                    <?php if ($batch['status'] !== 'completed'): ?>
                    <a href="add-cost.php?batch_id=<?php echo $batch_id; ?>" class="button small">
                        <i class="fas fa-plus-circle"></i> Add Cost
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="info-card-content">
                <?php if (count($costs) > 0): ?>
                <div class="costs-summary">
                    <div class="cost-total">
                        <span class="cost-label">Total Cost:</span>
                        <span class="cost-value"><?php echo number_format($total_cost, 2); ?></span>
                    </div>
                    <div class="cost-per-unit">
                        <span class="cost-label">Cost Per Unit:</span>
                        <span class="cost-value">
                            <?php echo $batch['quantity_produced'] > 0 ? 
                                number_format($total_cost / $batch['quantity_produced'], 2) : 
                                'N/A'; ?>
                        </span>
                    </div>
                </div>
                
                <div class="costs-list">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Description</th>
                                <th>Recorded By</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($costs as $cost): ?>
                            <tr>
                                <td><span class="cost-type cost-<?php echo $cost['cost_type']; ?>"><?php echo ucfirst($cost['cost_type']); ?></span></td>
                                <td class="amount-cell"><?php echo number_format($cost['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($cost['description']); ?></td>
                                <td><?php echo htmlspecialchars($cost['recorded_by_name']); ?></td>
                                <td><?php echo date('Y-m-d', strtotime($cost['recorded_date'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <p>No costs recorded for this batch.</p>
                    <?php if ($batch['status'] !== 'completed'): ?>
                    <a href="add-cost.php?batch_id=<?php echo $batch_id; ?>" class="button small">
                        <i class="fas fa-plus-circle"></i> Add First Cost
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
/* View Batch Page Styles */
.breadcrumb {
    margin-bottom: 1rem;
    font-size: 0.9rem;
    color: var(--text-secondary, #6c757d);
}

.breadcrumb a {
    color: var(--primary, #1a73e8);
    text-decoration: none;
}

.breadcrumb a:hover {
    text-decoration: underline;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.page-title {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.page-title h2 {
    margin: 0;
}

.page-actions {
    display: flex;
    gap: 0.5rem;
}

.batch-details-container {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

/* Progress Bar */
.batch-progress-container {
    background-color: white;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.batch-progress-label {
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.batch-progress-bar {
    height: 8px;
    background-color: #e9ecef;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 1.5rem;
}

.batch-progress-fill {
    height: 100%;
    background-color: var(--primary, #1a73e8);
    border-radius: 4px;
    transition: width 0.5s ease;
}

.batch-progress-steps {
    display: flex;
    justify-content: space-between;
}

.progress-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    flex: 1;
}

.step-indicator {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background-color: #e9ecef;
    border: 2px solid #e9ecef;
    margin-bottom: 8px;
    z-index: 1;
}

.progress-step.completed .step-indicator {
    background-color: var(--primary, #1a73e8);
    border-color: var(--primary, #1a73e8);
}

.step-label {
    font-size: 0.8rem;
    color: var(--text-secondary, #6c757d);
    text-align: center;
}

.progress-step.completed .step-label {
    color: var(--text-primary, #212529);
    font-weight: 500;
}

/* Info Grid */
.batch-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 1.5rem;
}

.info-card {
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.info-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    background-color: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
}

.info-card-header h3 {
    margin: 0;
    font-size: 1.1rem;
}

.card-actions {
    display: flex;
    gap: 0.5rem;
}

.info-card-content {
    padding: 1.5rem;
}

.info-row {
    display: flex;
    margin-bottom: 1rem;
}

.info-row:last-child {
    margin-bottom: 0;
}

.info-label {
    width: 40%;
    font-weight: 500;
    color: var(--text-secondary, #6c757d);
}

.info-value {
    width: 60%;
}

.primary-text {
    font-weight: 500;
}

.secondary-text {
    font-size: 0.9rem;
    color: var(--text-secondary, #6c757d);
    margin-top: 0.25rem;
}

/* Data Tables */
.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th, 
.data-table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}

.data-table th {
    font-weight: 600;
    color: var(--text-secondary, #6c757d);
}

.amount-cell {
    font-weight: 500;
}

/* Cost Summary */
.costs-summary {
    display: flex;
    justify-content: space-between;
    padding: 1rem;
    background-color: #f8f9fa;
    border-radius: 4px;
    margin-bottom: 1rem;
}

.cost-label {
    font-weight: 500;
    margin-right: 0.5rem;
}

.cost-value {
    font-weight: 600;
    color: var(--primary, #1a73e8);
}

.cost-total .cost-value {
    font-size: 1.1rem;
}

/* Cost Types */
.cost-type {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 500;
}

.cost-labor { background-color: #e3f2fd; color: #0d47a1; }
.cost-material { background-color: #e8f5e9; color: #1b5e20; }
.cost-packaging { background-color: #fff3e0; color: #e65100; }
.cost-zipper { background-color: #f3e5f5; color: #6a1b9a; }
.cost-sticker { background-color: #e1f5fe; color: #01579b; }
.cost-logo { background-color: #e8eaf6; color: #283593; }
.cost-tag { background-color: #fce4ec; color: #880e4f; }
.cost-misc { background-color: #f5f5f5; color: #424242; }

/* Empty State */
.empty-state {
    text-align: center;
    padding: 2rem;
    color: var(--text-secondary, #6c757d);
}

.empty-state .button {
    margin-top: 1rem;
}

/* Status Badge */
.status-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: 0.8rem;
    font-weight: 500;
    text-transform: capitalize;
}

.status-pending { background-color: #fff3cd; color: #856404; }
.status-cutting { background-color: #cce5ff; color: #004085; }
.status-stitching { background-color: #d1c4e9; color: #4527a0; }
.status-ironing { background-color: #f8d7da; color: #721c24; }
.status-packaging { background-color: #fff3e0; color: #e65100; }
.status-completed { background-color: #d4edda; color: #155724; }

/* Error Container */
.error-container {
    background-color: #f8d7da;
    color: #721c24;
    padding: 2rem;
    border-radius: 8px;
    text-align: center;
    margin: 2rem 0;
}

.error-container h2 {
    margin-top: 0;
}

.error-container .button {
    margin-top: 1rem;
}

/* Responsive Styles */
@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }
    
    .batch-info-grid {
        grid-template-columns: 1fr;
    }
    
    .info-row {
        flex-direction: column;
    }
    
    .info-label, .info-value {
        width: 100%;
    }
    
    .info-label {
        margin-bottom: 0.25rem;
    }
    
    .costs-summary {
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .data-table {
        display: block;
        overflow-x: auto;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Log page view
    if (typeof logUserActivity === 'function') {
        logUserActivity(
            'read', 
            'manufacturing', 
            'Viewed batch details for <?php echo htmlspecialchars($batch['batch_number']); ?>'
        );
    }
});
</script>

<?php include_once '../includes/footer.php'; ?>
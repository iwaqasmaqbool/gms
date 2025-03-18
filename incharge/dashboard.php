<?php
session_start();
$page_title = "Incharge Dashboard";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get raw materials summary
$materials_query = "SELECT 
    COUNT(*) as total_materials,
    SUM(stock_quantity) as total_stock
    FROM raw_materials";
$materials_stmt = $db->prepare($materials_query);
$materials_stmt->execute();
$materials = $materials_stmt->fetch(PDO::FETCH_ASSOC);

// Get manufacturing batches summary
$batches_query = "SELECT 
    COUNT(*) as total_batches,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_batches,
    SUM(quantity_produced) as total_produced
    FROM manufacturing_batches";
$batches_stmt = $db->prepare($batches_query);
$batches_stmt->execute();
$batches = $batches_stmt->fetch(PDO::FETCH_ASSOC);

// Get recent material purchases
$purchases_query = "SELECT p.id, m.name as material_name, p.quantity, p.unit_price, 
                   p.total_amount, p.vendor_name, p.purchase_date 
                   FROM purchases p 
                   JOIN raw_materials m ON p.material_id = m.id 
                   ORDER BY p.purchase_date DESC LIMIT 5";
$purchases_stmt = $db->prepare($purchases_query);
$purchases_stmt->execute();

// Get active manufacturing batches
$active_batches_query = "SELECT b.id, b.batch_number, p.name as product_name, 
                        b.quantity_produced, b.status, b.start_date 
                        FROM manufacturing_batches b 
                        JOIN products p ON b.product_id = p.id 
                        WHERE b.status != 'completed'
                        ORDER BY b.start_date ASC LIMIT 5";
$active_batches_stmt = $db->prepare($active_batches_query);
$active_batches_stmt->execute();

// Get low stock materials
$low_stock_query = "SELECT id, name, unit, stock_quantity 
                   FROM raw_materials 
                   WHERE stock_quantity < 10 
                   ORDER BY stock_quantity ASC LIMIT 5";
$low_stock_stmt = $db->prepare($low_stock_query);
$low_stock_stmt->execute();

// NEW QUERY: Get manufacturing status counts for the progress bar
$status_query = "SELECT status, COUNT(*) as count
                FROM manufacturing_batches
                GROUP BY status";
$status_stmt = $db->prepare($status_query);
$status_stmt->execute();

$status_counts = [];
while($row = $status_stmt->fetch(PDO::FETCH_ASSOC)) {
    $status_counts[$row['status']] = $row['count'];
}

// NEW QUERY: Get all active batches for visualization
$pipeline_batches_query = "SELECT b.id, b.batch_number, p.name as product_name,
                         b.quantity_produced, b.status, b.start_date,
                         b.expected_completion_date, b.completion_date
                         FROM manufacturing_batches b
                         JOIN products p ON b.product_id = p.id
                         WHERE b.status != 'completed' OR b.completion_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                         ORDER BY b.expected_completion_date ASC";
$pipeline_batches_stmt = $db->prepare($pipeline_batches_query);
$pipeline_batches_stmt->execute();
$active_batches_all = $pipeline_batches_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($materials['total_materials']); ?></div>
        <div class="stat-label">Raw Materials</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($materials['total_stock'], 2); ?></div>
        <div class="stat-label">Total Stock</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($batches['total_batches']); ?></div>
        <div class="stat-label">Manufacturing Batches</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($batches['total_produced']); ?></div>
        <div class="stat-label">Total Produced</div>
    </div>
</div>

<div class="dashboard-grid">
    <div class="dashboard-card">
        <div class="card-header">
            <h2>Recent Material Purchases</h2>
            <a href="purchases.php" class="view-all">View All</a>
        </div>
        <div class="card-content">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Material</th>
                        <th>Quantity</th>
                        <th>Unit Price</th>
                        <th>Total</th>
                        <th>Vendor</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($purchase = $purchases_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($purchase['material_name']); ?></td>
                        <td><?php echo htmlspecialchars($purchase['quantity']); ?></td>
                        <td><?php echo number_format($purchase['unit_price'], 2); ?></td>
                        <td><?php echo number_format($purchase['total_amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($purchase['vendor_name']); ?></td>
                        <td><?php echo htmlspecialchars($purchase['purchase_date']); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="dashboard-card">
        <div class="card-header">
            <h2>Low Stock Materials</h2>
            <a href="raw-materials.php" class="view-all">View All</a>
        </div>
        <div class="card-content">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Material</th>
                        <th>Unit</th>
                        <th>Stock Quantity</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($material = $low_stock_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($material['name']); ?></td>
                        <td><?php echo htmlspecialchars($material['unit']); ?></td>
                        <td><?php echo number_format($material['stock_quantity'], 2); ?></td>
                        <td>
                            <a href="add-purchase.php?material_id=<?php echo $material['id']; ?>" class="button small">Purchase</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    

    <!-- NEW SECTION: Manufacturing Pipeline Visualization -->
    <div class="dashboard-card full-width">
        <div class="card-header">
            <h2>Manufacturing Pipeline</h2>
            <a href="manufacturing.php" class="view-all">View All</a>
        </div>
        <div class="card-content">
            <!-- Advanced Batch Progress Visualization -->
            <div class="batch-status-progress">
                <?php 
                // Define statuses array
                $statuses = ['pending', 'cutting', 'stitching', 'ironing', 'packaging', 'completed'];
                
                // Only show progress bar if we have batches
                if (!empty($status_counts)): 
                    $total_batches = array_sum($status_counts);
                    if ($total_batches > 0):
                ?>
                <div class="production-pipeline">
                    <div class="pipeline-container">
                        <div class="pipeline-stages">
                            <?php foreach($statuses as $status): ?>
                            <div class="pipeline-stage" data-status="<?php echo $status; ?>">
                                <div class="stage-header">
                                    <span class="stage-name"><?php echo ucfirst($status); ?></span>
                                    <span class="stage-count"><?php echo $status_counts[$status] ?? 0; ?></span>
                                </div>
                                <div class="stage-content">
                                    <?php
                                    // Group batches by status
                                    $status_batches = array_filter($active_batches_all, function($batch) use ($status) {
                                        return $batch['status'] === $status;
                                    });
                                    
                                    // Sort batches - urgent ones first
                                    usort($status_batches, function($a, $b) {
                                        // Calculate days until expected completion
                                        $a_days = (strtotime($a['expected_completion_date']) - time()) / (60 * 60 * 24);
                                        $b_days = (strtotime($b['expected_completion_date']) - time()) / (60 * 60 * 24);
                                        
                                        // Urgent batches first (less days remaining)
                                        return $a_days <=> $b_days;
                                    });
                                    
                                    // Display batch balloons (limited to 3 per stage for dashboard)
                                    $count = 0;
                                    foreach($status_batches as $index => $batch):
                                        if ($count >= 3) break; // Limit to 3 balloons per stage
                                        $count++;
                                        
                                        // Calculate if batch is urgent
                                        $days_remaining = (strtotime($batch['expected_completion_date']) - time()) / (60 * 60 * 24);
                                        $is_urgent = false;
                                        $urgency_class = '';
                                        
                                        // Define urgency based on status and days remaining
                                        if ($days_remaining < 0) {
                                            // Past due date
                                            $urgency_class = 'batch-overdue';
                                            $is_urgent = true;
                                        } elseif ($days_remaining < 3) {
                                            // Less than 3 days remaining
                                            $status_index = array_search($batch['status'], $statuses);
                                            $stages_remaining = count($statuses) - $status_index - 1;
                                            
                                            // If many stages remaining but little time
                                            if ($stages_remaining > 1 && $days_remaining < 2) {
                                                $urgency_class = 'batch-urgent';
                                                $is_urgent = true;
                                            } elseif ($stages_remaining > 0) {
                                                $urgency_class = 'batch-warning';
                                            }
                                        }
                                        
                                        // Generate a unique color based on batch ID
                                        $color_index = $batch['id'] % 8; // 8 different colors
                                        $color_class = 'batch-color-' . $color_index;
                                    ?>
                                    <div class="batch-balloon <?php echo $color_class . ' ' . $urgency_class; ?>"
                                         tabindex="0"
                                         role="button"
                                         aria-label="Batch <?php echo htmlspecialchars($batch['batch_number']); ?> - <?php echo htmlspecialchars($batch['product_name']); ?>"
                                         data-batch-id="<?php echo $batch['id']; ?>"
                                         data-batch-number="<?php echo htmlspecialchars($batch['batch_number']); ?>"
                                         data-product-name="<?php echo htmlspecialchars($batch['product_name']); ?>"
                                         data-quantity="<?php echo $batch['quantity_produced']; ?>"
                                         data-start-date="<?php echo htmlspecialchars($batch['start_date']); ?>"
                                         data-expected-date="<?php echo htmlspecialchars($batch['expected_completion_date']); ?>"
                                         data-days-remaining="<?php echo round($days_remaining, 1); ?>">
                                        <span class="batch-label"><?php echo htmlspecialchars($batch['batch_number']); ?></span>
                                        <?php if ($is_urgent): ?>
                                        <span class="batch-alert"><i class="fas fa-exclamation-triangle"></i></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                    
                                    <?php if (count($status_batches) > 3): ?>
                                    <div class="more-batches">
                                        <a href="manufacturing.php?status=<?php echo $status; ?>" class="more-link">+<?php echo count($status_batches) - 3; ?> more</a>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (empty($status_batches)): ?>
                                    <div class="empty-stage">
                                        <span class="empty-message">No batches</span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Batch details popup -->
                    <div id="batchDetailPopup" class="batch-detail-popup">
                        <div class="popup-header">
                            <h4 id="popupBatchNumber"></h4>
                            <button type="button" class="close-popup">&times;</button>
                        </div>
                        <div class="popup-content">
                            <div class="detail-row">
                                <span class="detail-label">Product:</span>
                                <span id="popupProductName" class="detail-value"></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Quantity:</span>
                                <span id="popupQuantity" class="detail-value"></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Start Date:</span>
                                <span id="popupStartDate" class="detail-value"></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Expected Completion:</span>
                                <span id="popupExpectedDate" class="detail-value"></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Time Remaining:</span>
                                <span id="popupTimeRemaining" class="detail-value"></span>
                            </div>
                        </div>
                        <div class="popup-actions">
                            <a id="popupViewLink" href="#" class="button small">View Details</a>
                            <a id="popupUpdateLink" href="#" class="button primary small">Update Status</a>
                        </div>
                    </div>
                </div>
                
                <?php else: ?>
                <div class="empty-progress-message">
                    No manufacturing batches available.
                </div>
                <?php endif; else: ?>
                <div class="empty-progress-message">
                    No manufacturing batches available.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    
    <div class="dashboard-card full-width">
        <div class="card-header">
            <h2>Active Manufacturing Batches</h2>
            <a href="manufacturing.php" class="view-all">View All</a>
        </div>
        <div class="card-content">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Batch #</th>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Status</th>
                        <th>Start Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // Reset the statement to fetch results again
                    $active_batches_stmt->execute();
                    while($batch = $active_batches_stmt->fetch(PDO::FETCH_ASSOC)): 
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($batch['batch_number']); ?></td>
                        <td><?php echo htmlspecialchars($batch['product_name']); ?></td>
                        <td><?php echo htmlspecialchars($batch['quantity_produced']); ?></td>
                        <td><span class="status-badge status-<?php echo $batch['status']; ?>"><?php echo ucfirst($batch['status']); ?></span></td>
                        <td><?php echo htmlspecialchars($batch['start_date']); ?></td>
                        <td>
                            <a href="update-batch.php?id=<?php echo $batch['id']; ?>" class="button small">Update</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add necessary CSS -->
<style>
/* Modern Glassy Batch Detail Popup */
.batch-detail-popup {
  position: absolute;
  display: none;
  width: 320px;
  background: rgba(255, 255, 255, 0.85);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px); /* Safari support */
  border-radius: 16px;
  box-shadow: 
    0 4px 20px rgba(0, 0, 0, 0.08),
    0 1px 2px rgba(255, 255, 255, 0.3) inset,
    0 -1px 2px rgba(0, 0, 0, 0.05) inset;
  border: 1px solid rgba(255, 255, 255, 0.18);
  overflow: hidden;
  z-index: 100;
  animation: popup-float-in 0.3s ease-out;
  transform-origin: top center;
}

@keyframes popup-float-in {
  from { 
    opacity: 0; 
    transform: translateY(10px) scale(0.95); 
    box-shadow: 0 0 0 rgba(0, 0, 0, 0);
  }
  to { 
    opacity: 1; 
    transform: translateY(0) scale(1); 
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
  }
}

/* Redesigned header with subtle gradient */
.popup-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  background: linear-gradient(to right, rgba(245, 247, 250, 0.9), rgba(240, 242, 245, 0.9));
  border-bottom: 1px solid rgba(255, 255, 255, 0.2);
}

.popup-header h4 {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 600;
  color: #333;
  text-shadow: 0 1px 0 rgba(255, 255, 255, 0.5);
}

.close-popup {
  background: none;
  border: none;
  color: rgba(80, 80, 80, 0.7);
  font-size: 1.3rem;
  line-height: 1;
  cursor: pointer;
  transition: all 0.2s ease;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
}

.close-popup:hover {
  background: rgba(0, 0, 0, 0.05);
  color: rgba(60, 60, 60, 0.9);
  transform: rotate(90deg);
}

/* Content area with soft padding */
.popup-content {
  padding: 20px;
}

.detail-row {
  display: flex;
  margin-bottom: 12px;
  align-items: baseline;
}

.detail-row:last-child {
  margin-bottom: 0;
}

.detail-label {
  width: 45%;
  font-weight: 500;
  color: rgba(60, 60, 60, 0.75);
  font-size: 0.9rem;
}

.detail-value {
  width: 55%;
  font-size: 0.95rem;
  color: #333;
  font-weight: 400;
}

/* Glassy action buttons */
.popup-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  padding: 16px 20px;
  background: rgba(248, 249, 250, 0.5);
  border-top: 1px solid rgba(255, 255, 255, 0.3);
}

/* Glassy button base style */
.popup-actions .button {
  padding: 8px 16px;
  border-radius: 30px;
  font-weight: 500;
  font-size: 0.9rem;
  transition: all 0.3s ease;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
  border: none;
  cursor: pointer;
}

/* Shimmer animation for buttons */
.popup-actions .button::before {
  content: '';
  position: absolute;
  top: -50%;
  left: -50%;
  width: 200%;
  height: 200%;
  background: linear-gradient(
    to right,
    rgba(255, 255, 255, 0) 0%,
    rgba(255, 255, 255, 0.3) 50%,
    rgba(255, 255, 255, 0) 100%
  );
  transform: rotate(30deg);
  animation: shimmer 3s infinite linear;
  pointer-events: none;
}

@keyframes shimmer {
  from { transform: translateX(-100%) rotate(30deg); }
  to { transform: translateX(100%) rotate(30deg); }
}

/* View button - transparent with subtle border */
.popup-actions .button.small {
  background: rgba(255, 255, 255, 0.25);
  color: rgba(70, 70, 70, 0.9);
  box-shadow: 
    0 1px 3px rgba(0, 0, 0, 0.05),
    0 1px 2px rgba(255, 255, 255, 0.5) inset;
  border: 1px solid rgba(255, 255, 255, 0.6);
  backdrop-filter: blur(5px);
  -webkit-backdrop-filter: blur(5px);
}

.popup-actions .button.small:hover {
  background: rgba(255, 255, 255, 0.4);
  transform: translateY(-1px);
  box-shadow: 
    0 3px 6px rgba(0, 0, 0, 0.08),
    0 1px 2px rgba(255, 255, 255, 0.5) inset;
}

/* Update Status button - more prominent with gradient */
.popup-actions .button.primary.small {
  background: linear-gradient(135deg, rgba(26, 115, 232, 0.85), rgba(66, 133, 244, 0.85));
  color: white;
  box-shadow: 
    0 2px 4px rgba(26, 115, 232, 0.3),
    0 1px 2px rgba(255, 255, 255, 0.2) inset;
  border: 1px solid rgba(26, 115, 232, 0.2);
  text-shadow: 0 1px 1px rgba(0, 0, 0, 0.1);
}

.popup-actions .button.primary.small:hover {
  background: linear-gradient(135deg, rgba(24, 107, 218, 0.9), rgba(58, 125, 236, 0.9));
  transform: translateY(-1px);
  box-shadow: 
    0 4px 8px rgba(26, 115, 232, 0.4),
    0 1px 3px rgba(255, 255, 255, 0.2) inset;
}

/* Popup arrow with glassy effect */
.popup-arrow {
  position: absolute;
  top: -10px;
  width: 20px;
  height: 10px;
  clip-path: polygon(50% 0%, 0% 100%, 100% 100%);
  background: rgba(255, 255, 255, 0.9);
  border-top: 1px solid rgba(255, 255, 255, 0.5);
  border-left: 1px solid rgba(255, 255, 255, 0.3);
  border-right: 1px solid rgba(255, 255, 255, 0.3);
  filter: drop-shadow(0 -1px 2px rgba(0, 0, 0, 0.05));
  backdrop-filter: blur(5px);
  -webkit-backdrop-filter: blur(5px);
}

/* Pipeline Visualization Styles */
.production-pipeline {
    background-color: white;
    border-radius: 8px;
    padding: 0.5rem;
    margin-bottom: 1rem;
}

.pipeline-container {
    position: relative;
    padding: 1rem 0;
}

.pipeline-stages {
    display: flex;
    justify-content: space-between;
    position: relative;
    min-height: 120px;
}

/* Add a connecting line between stages */
.pipeline-stages::before {
    content: '';
    position: absolute;
    top: 30px;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(to right, 
        #fbbc04 calc(100%/6), 
        #4285f4 calc(100%/6), 
        #4285f4 calc(100%/3), 
        #673ab7 calc(100%/3), 
        #673ab7 calc(100%/2), 
        #f06292 calc(100%/2), 
        #f06292 calc(2*100%/3), 
        #ff7043 calc(2*100%/3), 
        #ff7043 calc(5*100%/6), 
        #34a853 calc(5*100%/6), 
        #34a853 100%);
    z-index: 1;
}

.pipeline-stage {
    flex: 1;
    display: flex;
    flex-direction: column;
    position: relative;
    z-index: 2;
    padding: 0 8px;
}

.stage-header {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-bottom: 1rem;
    position: relative;
}

.stage-header::before {
    content: '';
    width: 16px;
    height: 16px;
    border-radius: 50%;
    position: absolute;
    top: -26px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 3;
}

.pipeline-stage[data-status="pending"] .stage-header::before { background-color: #fbbc04; }
.pipeline-stage[data-status="cutting"] .stage-header::before { background-color: #4285f4; }
.pipeline-stage[data-status="stitching"] .stage-header::before { background-color: #673ab7; }
.pipeline-stage[data-status="ironing"] .stage-header::before { background-color: #f06292; }
.pipeline-stage[data-status="packaging"] .stage-header::before { background-color: #ff7043; }
.pipeline-stage[data-status="completed"] .stage-header::before { background-color: #34a853; }

.stage-name {
    font-weight: 500;
    font-size: 0.8rem;
    margin-bottom: 0.25rem;
}

.stage-count {
    font-size: 0.75rem;
    color: var(--text-secondary, #6c757d);
    background-color: #f1f3f4;
    padding: 0.1rem 0.4rem;
    border-radius: 10px;
}

.stage-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    min-height: 60px;
}

/* Batch balloon styles */
.batch-balloon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    border-radius: 50%;
    position: relative;
    cursor: pointer;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
    transition: transform 0.2s, box-shadow 0.2s;
    font-size: 0.7rem;
    font-weight: 500;
    color: white;
    text-align: center;
}

.batch-balloon:hover, .batch-balloon:focus {
    transform: scale(1.1);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    z-index: 10;
    outline: none;
}

/* Batch color variations */
.batch-color-0 { background-color: #4285f4; }
.batch-color-1 { background-color: #34a853; }
.batch-color-2 { background-color: #ea4335; }
.batch-color-3 { background-color: #fbbc04; }
.batch-color-4 { background-color: #673ab7; }
.batch-color-5 { background-color: #ff7043; }
/* Batch color variations (continued) */
.batch-color-6 { background-color: #03a9f4; }
.batch-color-7 { background-color: #8bc34a; }

/* Urgency indicators */
.batch-warning {
    border: 2px solid #fbbc04;
    animation: pulse-warning 2s infinite;
}

.batch-urgent {
    border: 3px solid #ea4335;
    animation: pulse-urgent 1.5s infinite;
    transform: scale(1.1);
}

.batch-urgent:hover {
    transform: scale(1.2);
}

.batch-overdue {
    border: 3px solid #ea4335;
    background-image: repeating-linear-gradient(
        45deg,
        rgba(0, 0, 0, 0),
        rgba(0, 0, 0, 0) 10px,
        rgba(234, 67, 53, 0.2) 10px,
        rgba(234, 67, 53, 0.2) 20px
    );
    animation: pulse-urgent 1.5s infinite;
    transform: scale(1.1);
}

.batch-overdue:hover {
    transform: scale(1.2);
}

@keyframes pulse-warning {
    0% { box-shadow: 0 0 0 0 rgba(251, 188, 4, 0.4); }
    70% { box-shadow: 0 0 0 6px rgba(251, 188, 4, 0); }
    100% { box-shadow: 0 0 0 0 rgba(251, 188, 4, 0); }
}

@keyframes pulse-urgent {
    0% { box-shadow: 0 0 0 0 rgba(234, 67, 53, 0.4); }
    70% { box-shadow: 0 0 0 8px rgba(234, 67, 53, 0); }
    100% { box-shadow: 0 0 0 0 rgba(234, 67, 53, 0); }
}

.batch-label {
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    padding: 0 4px;
    font-size: 0.7rem;
}

.batch-alert {
    position: absolute;
    top: -5px;
    right: -5px;
    background-color: #ea4335;
    color: white;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.6rem;
    border: 1px solid white;
}

/* Empty stage styles */
.empty-stage {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 60px;
    padding: 0.5rem;
}

.empty-message {
    font-size: 0.8rem;
    color: var(--text-secondary, #6c757d);
    font-style: italic;
}

/* More batches link */
.more-batches {
    margin-top: 0.5rem;
}

.more-link {
    font-size: 0.75rem;
    color: var(--primary, #1a73e8);
    text-decoration: none;
    padding: 3px 8px;
    border-radius: 12px;
    background-color: rgba(26, 115, 232, 0.1);
}

.more-link:hover {
    background-color: rgba(26, 115, 232, 0.2);
    text-decoration: underline;
}

/* Empty progress message */
.empty-progress-message {
    text-align: center;
    padding: 1.5rem;
    color: var(--text-secondary, #6c757d);
    background-color: #f8f9fa;
    border-radius: 8px;
    font-style: italic;
}

/* Status Badge Enhancement - Inherit colors from progress bar segments */
.status-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: var(--font-size-sm, 0.875rem);
    font-weight: 500;
    color: white; /* Text color for better contrast */
    text-shadow: 0 1px 1px rgba(0, 0, 0, 0.2); /* Improve text legibility */
}

/* Use the exact same color definitions from the progress bar */
.status-badge.status-pending { background-color: #fbbc04; color: rgba(0, 0, 0, 0.9); text-shadow: none; }
.status-badge.status-cutting { background-color: #4285f4; }
.status-badge.status-stitching { background-color: #673ab7; }
.status-badge.status-ironing { background-color: #f06292; }
.status-badge.status-packaging { background-color: #ff7043; }
.status-badge.status-completed { background-color: #34a853; }

/* Responsive adjustments for pipeline */
@media (max-width: 992px) {
    .pipeline-stages {
        overflow-x: auto;
        padding-bottom: 0.5rem;
        justify-content: flex-start;
        min-height: 150px;
        scroll-padding: 0 20px;
        -webkit-overflow-scrolling: touch; /* Smooth scrolling on iOS */
    }
    
    .pipeline-stage {
        min-width: 90px;
        flex-shrink: 0;
    }
}

/* Respect user preference for reduced motion */
@media (prefers-reduced-motion: reduce) {
    .batch-balloon, 
    .batch-warning, 
    .batch-urgent, 
    .batch-overdue,
    .batch-detail-popup,
    .popup-actions .button::before,
    .close-popup:hover {
        animation: none !important;
        transition: none !important;
        transform: none !important;
    }
    
    .batch-balloon:hover,
    .batch-urgent:hover, 
    .batch-overdue:hover {
        transform: scale(1.1) !important;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize batch balloons
    initBatchBalloons();
    
    // Function to initialize interactive batch balloons
    function initBatchBalloons() {
        const batchBalloons = document.querySelectorAll('.batch-balloon');
        const popup = document.getElementById('batchDetailPopup');
        
        if (!popup || batchBalloons.length === 0) {
            console.log("Batch visualization: No balloons or popup found");
            return;
        }
        
        // Add glassmorphism class to the popup
        popup.classList.add('glassmorphism');
        
        // Initialize popup elements
        const popupBatchNumber = document.getElementById('popupBatchNumber');
        const popupProductName = document.getElementById('popupProductName');
        const popupQuantity = document.getElementById('popupQuantity');
        const popupStartDate = document.getElementById('popupStartDate');
        const popupExpectedDate = document.getElementById('popupExpectedDate');
        const popupTimeRemaining = document.getElementById('popupTimeRemaining');
        const popupViewLink = document.getElementById('popupViewLink');
        const popupUpdateLink = document.getElementById('popupUpdateLink');
        const closePopupBtn = document.querySelector('.close-popup');
        
        // Add click event to each batch balloon
        batchBalloons.forEach(balloon => {
            balloon.addEventListener('click', function(e) {
                e.stopPropagation();
                
                // Get batch data from data attributes
                const batchId = this.getAttribute('data-batch-id');
                const batchNumber = this.getAttribute('data-batch-number');
                const productName = this.getAttribute('data-product-name');
                const quantity = this.getAttribute('data-quantity');
                const startDate = this.getAttribute('data-start-date');
                const expectedDate = this.getAttribute('data-expected-date');
                const daysRemaining = parseFloat(this.getAttribute('data-days-remaining'));
                
                // Format time remaining text with enhanced styling
                let timeRemainingText;
                if (daysRemaining < 0) {
                    timeRemainingText = `<span style="color: #ea4335">${Math.abs(daysRemaining).toFixed(1)} days overdue</span>`;
                } else if (daysRemaining < 1) {
                    timeRemainingText = `<span style="color: #ea4335">${(daysRemaining * 24).toFixed(1)} hours remaining</span>`;
                } else if (daysRemaining < 3) {
                    timeRemainingText = `<span style="color: #fbbc04">${daysRemaining.toFixed(1)} days remaining</span>`;
                } else {
                    timeRemainingText = `<span>${daysRemaining.toFixed(1)} days remaining</span>`;
                }
                
                // Update popup content
                popupBatchNumber.textContent = batchNumber;
                popupProductName.textContent = productName;
                popupQuantity.textContent = quantity;
                popupStartDate.textContent = formatDate(startDate);
                popupExpectedDate.textContent = formatDate(expectedDate);
                popupTimeRemaining.innerHTML = timeRemainingText;
                
                // Update action links
                popupViewLink.href = `view-batch.php?id=${batchId}`;
                popupUpdateLink.href = `update-batch.php?id=${batchId}`;
                
                // Position and show popup with enhanced positioning
                const balloonRect = this.getBoundingClientRect();
                const scrollTop = window.scrollY || document.documentElement.scrollTop;
                
                // Adjust top position to account for popup height
                const popupHeight = 280; // Approximate height
                let topPosition = balloonRect.bottom + scrollTop + 15;
                
                // Check if popup would go off the bottom of the viewport
                if (topPosition + popupHeight > window.innerHeight + scrollTop) {
                    // Position above the balloon instead
                    topPosition = balloonRect.top + scrollTop - popupHeight - 15;
                }
                
                popup.style.top = topPosition + 'px';
                
                // Center popup horizontally relative to the balloon
                const popupWidth = 320; // Match this to the CSS width
                const leftPosition = balloonRect.left + (balloonRect.width / 2) - (popupWidth / 2);
                
                // Ensure popup stays within viewport
                const viewportWidth = window.innerWidth;
                let finalLeft = leftPosition;
                
                if (finalLeft < 20) finalLeft = 20;
                if (finalLeft + popupWidth > viewportWidth - 20) finalLeft = viewportWidth - popupWidth - 20;
                
                popup.style.left = finalLeft + 'px';
                
                // Add a subtle entrance animation
                popup.style.animation = 'none';
                popup.offsetHeight; // Force reflow
                popup.style.animation = 'popup-float-in 0.3s ease-out';
                
                popup.style.display = 'block';
                
                // Add arrow pointing to the balloon
                const arrowOffset = balloonRect.left + (balloonRect.width / 2) - finalLeft;
                
                // Remove any existing arrow
                const existingArrow = popup.querySelector('.popup-arrow');
                if (existingArrow) existingArrow.remove();
                
                // Create and add new arrow
                const arrow = document.createElement('div');
                arrow.className = 'popup-arrow';
                arrow.style.left = `${arrowOffset}px`;
                arrow.style.transform = 'translateX(-50%)';
                popup.appendChild(arrow);
            });
            
            // Add keyboard support for accessibility
            balloon.addEventListener('keydown', function(e) {
                // Open popup on Enter or Space
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    // Trigger the click event
                    this.click();
                }
            });
        });
        
        // Close popup when clicking outside
        document.addEventListener('click', function(e) {
            if (popup.style.display === 'block' && !popup.contains(e.target)) {
                closePopupWithAnimation();
            }
        });
        
        // Close popup with close button
        if (closePopupBtn) {
            closePopupBtn.addEventListener('click', closePopupWithAnimation);
        }
        
        // Add escape key support
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && popup.style.display === 'block') {
                closePopupWithAnimation();
            }
        });
        
        // Close popup with animation
        function closePopupWithAnimation() {
            popup.style.animation = 'popup-float-out 0.2s ease-in forwards';
            
            setTimeout(() => {
                popup.style.display = 'none';
            }, 200);
        }
        
        // Add this keyframe for closing animation
        if (!document.querySelector('style#popup-animations')) {
            const style = document.createElement('style');
            style.id = 'popup-animations';
            style.textContent = `
                @keyframes popup-float-out {
                    to { 
                        opacity: 0; 
                        transform: translateY(10px) scale(0.95); 
                    }
                }
            `;
            document.head.appendChild(style);
        }
        
        // Helper function to format dates
        function formatDate(dateString) {
            const options = { year: 'numeric', month: 'short', day: 'numeric' };
            return new Date(dateString).toLocaleDateString(undefined, options);
        }
    }
});
</script>

<?php include_once '../includes/footer.php'; ?>
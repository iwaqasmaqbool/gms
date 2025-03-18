<?php
// At the top of the file
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    session_start();
    $page_title = "Manufacturing Batches";
    include_once '../config/database.php';
    include_once '../config/auth.php';
    include_once '../includes/header.php';

    // This ensures $status_counts always exists with a default value
    $status_counts = [];

    // Initialize database connection
    $database = new Database();
    $db = $database->getConnection();

    // Set up filters
    $status_filter = isset($_GET['status']) ? $_GET['status'] : '';
    $search = isset($_GET['search']) ? $_GET['search'] : '';

    // Set up pagination
    $records_per_page = 10;
    $page = isset($_GET['page']) ? $_GET['page'] : 1;
    $offset = ($page - 1) * $records_per_page;

    // Build query with filters
    $where_clause = "";
    $params = array();

    if(!empty($status_filter)) {
        $where_clause .= " AND b.status = :status";
        $params[':status'] = $status_filter;
    }

    if(!empty($search)) {
        $where_clause .= " AND (b.batch_number LIKE :search OR p.name LIKE :search OR p.sku LIKE :search)";
        $params[':search'] = "%{$search}%";
    }

    // Get total records for pagination - with error handling
    $total_records = 0;
    $total_pages = 1;
    
    try {
        $count_query = "SELECT COUNT(*) as total 
                       FROM manufacturing_batches b
                       JOIN products p ON b.product_id = p.id
                       WHERE 1=1" . $where_clause;
        $count_stmt = $db->prepare($count_query);
        foreach($params as $param => $value) {
            $count_stmt->bindValue($param, $value);
        }
        $count_stmt->execute();
        $total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_records / $records_per_page);
    } catch (PDOException $e) {
        error_log("Count query error: " . $e->getMessage());
        // Continue with defaults
    }

    // Get batches with pagination and filters - with error handling
    $batches = [];
    
    try {
        $batches_query = "SELECT b.id, b.batch_number, p.name as product_name, p.sku, 
                         b.quantity_produced, b.status, b.start_date, b.expected_completion_date, 
                         b.completion_date, u.full_name as created_by_name
                         FROM manufacturing_batches b 
                         JOIN products p ON b.product_id = p.id 
                         JOIN users u ON b.created_by = u.id
                         WHERE 1=1" . $where_clause . "
                         ORDER BY 
                            CASE b.status 
                                WHEN 'pending' THEN 1
                                WHEN 'cutting' THEN 2
                                WHEN 'stitching' THEN 3
                                WHEN 'ironing' THEN 4
                                WHEN 'packaging' THEN 5
                                WHEN 'completed' THEN 6
                            END,
                            b.start_date DESC
                         LIMIT :offset, :records_per_page";
        $batches_stmt = $db->prepare($batches_query);
        foreach($params as $param => $value) {
            $batches_stmt->bindValue($param, $value);
        }
        $batches_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $batches_stmt->bindValue(':records_per_page', $records_per_page, PDO::PARAM_INT);
        $batches_stmt->execute();
    } catch (PDOException $e) {
        error_log("Batches query error: " . $e->getMessage());
        // We'll handle empty results in the view
    }

    // Get manufacturing status counts - with error handling
    try {
        $status_query = "SELECT status, COUNT(*) as count
                        FROM manufacturing_batches
                        GROUP BY status";
        $status_stmt = $db->prepare($status_query);
        $status_stmt->execute();
        
        while($row = $status_stmt->fetch(PDO::FETCH_ASSOC)) {
            $status_counts[$row['status']] = $row['count'];
        }
    } catch (PDOException $e) {
        error_log("Status count query error: " . $e->getMessage());
        // Continue with empty status counts
    }

    // Get products for batch creation - with error handling
    $products = [];
    
    try {
        $products_query = "SELECT id, name, sku FROM products ORDER BY name";
        $products_stmt = $db->prepare($products_query);
        $products_stmt->execute();
        $products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Products query error: " . $e->getMessage());
        // We'll show a message for empty products
    }

    // Get materials for batch creation - with error handling
    $materials = [];
    
    try {
        $materials_query = "SELECT id, name, unit, stock_quantity FROM raw_materials ORDER BY stock_quantity > 0 DESC, name ASC";
        $materials_stmt = $db->prepare($materials_query);
        $materials_stmt->execute();
        $materials = $materials_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Debug info
        error_log("Found " . count($materials) . " materials in database");
    } catch (PDOException $e) {
        error_log("Materials query error: " . $e->getMessage());
        // We'll show a message for empty materials
    }

} catch (Exception $e) {
    // Log the error
    error_log('Manufacturing page error: ' . $e->getMessage());
    
    // Display user-friendly error
    echo '<div style="padding: 20px; background-color: #f8d7da; color: #721c24; border-radius: 5px; margin: 20px;">';
    echo '<h2>We\'re experiencing technical difficulties</h2>';
    echo '<p>Our team has been notified and is working to fix the issue. Please try again later.</p>';
    echo '<p><a href="dashboard.php" style="color: #721c24; text-decoration: underline;">Return to Dashboard</a></p>';
    echo '</div>';
    
    // Exit to prevent further processing
    include_once '../includes/footer.php';
    exit;
}
?>
<!-- Advanced Batch Progress Visualization -->

    <div class="page-actions">
       <button id="newBatchBtn" class="button primary">
           <i class="fas fa-plus-circle"></i> Create New Batch
       </button>
    </div>
 <!-- Advanced Batch Progress Visualization -->
<div class="batch-status-progress">
    <?php
    // Define statuses array
    $statuses = ['pending', 'cutting', 'stitching', 'ironing', 'packaging', 'completed'];
    
    // Fetch all active batches for visualization (not just counts)
    $active_batches = [];
    try {
        $active_batches_query = "SELECT b.id, b.batch_number, p.name as product_name,
                               b.quantity_produced, b.status, b.start_date,
                               b.expected_completion_date, b.completion_date
                               FROM manufacturing_batches b
                               JOIN products p ON b.product_id = p.id
                               WHERE b.status != 'completed' OR b.completion_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                               ORDER BY b.expected_completion_date ASC";
        $active_batches_stmt = $db->prepare($active_batches_query);
        $active_batches_stmt->execute();
        $active_batches = $active_batches_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Debug info
        error_log("Found " . count($active_batches) . " active batches for visualization");
    } catch (PDOException $e) {
        error_log("Active batches query error: " . $e->getMessage());
    }
    
    // Only show progress bar if we have batches
    if (!empty($status_counts)): 
        $total_batches = array_sum($status_counts);
        if ($total_batches > 0):
    ?>
    <div class="production-pipeline">
        <h3 class="pipeline-title">Manufacturing Pipeline</h3>
        
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
                        $status_batches = array_filter($active_batches, function($batch) use ($status) {
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
                        
                        // Display batch balloons
                        foreach($status_batches as $index => $batch):
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
                        <!-- Add tabindex and ARIA attributes for accessibility -->
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
        No manufacturing batches available. Create your first batch to see production status.
    </div>
    <?php endif; else: ?>
    <div class="empty-progress-message">
        No manufacturing batches available. Create your first batch to see production status.
    </div>
    <?php endif; ?>
    
    <div class="status-legend">
        <div class="legend-section">
            <h4 class="legend-title">Manufacturing Stages</h4>
            <div class="legend-items">
                <?php foreach($statuses as $status): ?>
                <div class="legend-item">
                    <span class="color-box status-<?php echo $status; ?>"></span>
                    <span class="legend-label"><?php echo ucfirst($status); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="legend-section">
            <h4 class="legend-title">Batch Status</h4>
            <div class="legend-items">
                <div class="legend-item">
                    <span class="batch-indicator batch-normal"></span>
                    <span class="legend-label">Normal</span>
                </div>
                <div class="legend-item">
                    <span class="batch-indicator batch-warning"></span>
                    <span class="legend-label">Approaching Deadline</span>
                </div>
                <div class="legend-item">
                    <span class="batch-indicator batch-urgent"></span>
                    <span class="legend-label">Urgent</span>
                </div>
                <div class="legend-item">
                    <span class="batch-indicator batch-overdue"></span>
                    <span class="legend-label">Overdue</span>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="filter-container">
    <form id="filterForm" method="get" class="filter-form">
        <div class="filter-row">
            <div class="filter-group">
                <label for="status">Status:</label>
                <select id="status" name="status" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending (<?php echo $status_counts['pending'] ?? 0; ?>)</option>
                    <option value="cutting" <?php echo $status_filter === 'cutting' ? 'selected' : ''; ?>>Cutting (<?php echo $status_counts['cutting'] ?? 0; ?>)</option>
                    <option value="stitching" <?php echo $status_filter === 'stitching' ? 'selected' : ''; ?>>Stitching (<?php echo $status_counts['stitching'] ?? 0; ?>)</option>
                    <option value="ironing" <?php echo $status_filter === 'ironing' ? 'selected' : ''; ?>>Ironing (<?php echo $status_counts['ironing'] ?? 0; ?>)</option>
                    <option value="packaging" <?php echo $status_filter === 'packaging' ? 'selected' : ''; ?>>Packaging (<?php echo $status_counts['packaging'] ?? 0; ?>)</option>
                    <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed (<?php echo $status_counts['completed'] ?? 0; ?>)</option>
                </select>
            </div>
            
            <div class="filter-group search-group">
                <label for="search">Search:</label>
                <div class="search-input-container">
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Batch #, Product name, SKU">
                    <button type="submit" class="search-button">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div class="filter-actions">
                <a href="manufacturing.php" class="button secondary">Reset Filters</a>
            </div>
        </div>
    </form>
</div>



<div class="dashboard-card full-width">
    <div class="card-header">
        <h3>Manufacturing Batches</h3>
        <div class="pagination-info">
            <?php if ($total_records > 0): ?>
            Showing <?php echo min(($page - 1) * $records_per_page + 1, $total_records); ?> to 
            <?php echo min($page * $records_per_page, $total_records); ?> of 
            <?php echo $total_records; ?> batches
            <?php else: ?>
            No batches found
            <?php endif; ?>
        </div>
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
                    <th>Expected Completion</th>
                    <th>Actual Completion</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (isset($batches_stmt) && $batches_stmt->rowCount() > 0): ?>
                    <?php while($batch = $batches_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($batch['batch_number']); ?></td>
                        <td>
                            <div class="product-cell">
                                <div class="product-name"><?php echo htmlspecialchars($batch['product_name']); ?></div>
                                <div class="product-sku"><?php echo htmlspecialchars($batch['sku']); ?></div>
                            </div>
                        </td>
                        <td><?php echo number_format($batch['quantity_produced']); ?></td>
                        <td><span class="status-badge status-<?php echo $batch['status']; ?>"><?php echo ucfirst($batch['status']); ?></span></td>
                        <td><?php echo htmlspecialchars($batch['start_date']); ?></td>
                        <td><?php echo htmlspecialchars($batch['expected_completion_date']); ?></td>
                        <td><?php echo $batch['completion_date'] ? htmlspecialchars($batch['completion_date']) : '-'; ?></td>
                        <td>
                            <div class="action-buttons">
                                <a href="view-batch.php?id=<?php echo $batch['id']; ?>" class="button small">View</a>
                                <?php if($batch['status'] !== 'completed'): ?>
                                <a href="update-batch.php?id=<?php echo $batch['id']; ?>" class="button small">Update</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                <tr>
                    <td colspan="8" class="no-records">No manufacturing batches found matching your criteria.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Pagination -->
        <?php if($total_pages > 1): ?>
        <div class="pagination">
            <?php 
            // Build pagination query string with filters
            $pagination_query = '';
            if(!empty($status_filter)) $pagination_query .= '&status=' . urlencode($status_filter);
            if(!empty($search)) $pagination_query .= '&search=' . urlencode($search);
            ?>
            
            <?php if($page > 1): ?>
                <a href="?page=1<?php echo $pagination_query; ?>" class="pagination-link">&laquo; First</a>
                <a href="?page=<?php echo $page - 1; ?><?php echo $pagination_query; ?>" class="pagination-link">&laquo; Previous</a>
            <?php endif; ?>
            
            <?php
            // Determine the range of page numbers to display
            $range = 2; // Number of pages to show on either side of the current page
            $start_page = max(1, $page - $range);
            $end_page = min($total_pages, $page + $range);
            
            // Always show first page button
            if($start_page > 1) {
                echo '<a href="?page=1' . $pagination_query . '" class="pagination-link">1</a>';
                if($start_page > 2) {
                    echo '<span class="pagination-ellipsis">...</span>';
                }
            }
            
            // Display the range of pages
            for($i = $start_page; $i <= $end_page; $i++) {
                if($i == $page) {
                    echo '<span class="pagination-link current">' . $i . '</span>';
                } else {
                    echo '<a href="?page=' . $i . $pagination_query . '" class="pagination-link">' . $i . '</a>';
                }
            }
            
            // Always show last page button
            if($end_page < $total_pages) {
                if($end_page < $total_pages - 1) {
                    echo '<span class="pagination-ellipsis">...</span>';
                }
                echo '<a href="?page=' . $total_pages . $pagination_query . '" class="pagination-link">' . $total_pages . '</a>';
            }
            ?>
            
            <?php if($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?><?php echo $pagination_query; ?>" class="pagination-link">Next &raquo;</a>
                <a href="?page=<?php echo $total_pages; ?><?php echo $pagination_query; ?>" class="pagination-link">Last &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Create New Batch Modal -->
<div id="batchModal" class="modal">
    <div class="modal-content wide-modal">
        <span class="close-modal" id="closeBatchModal">&times;</span>
        <h2>Create New Manufacturing Batch</h2>
        
        <div id="quickProductSection" style="display: none;">
            <h3>Quick Add Product</h3>
            <form id="quickProductForm" class="embedded-form">
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="quick_product_name">Product Name:</label>
                            <input type="text" id="quick_product_name" name="name" required>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label for="quick_product_sku">SKU:</label>
                            <input type="text" id="quick_product_sku" name="sku" required>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="button secondary" id="cancelQuickProduct">Cancel</button>
                    <button type="submit" class="button primary">Add Product</button>
                </div>
            </form>
            <hr class="section-divider">
        </div>
        
        <form id="batchForm" action="../api/save-batch.php" method="post">
            <div class="form-section">
                <h3>Batch Information</h3>
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="product_id">Product:</label>
                            <select id="product_id" name="product_id" required>
                                <option value="">Select Product</option>
                                <?php if (!empty($products)): ?>
                                    <?php foreach($products as $product): ?>
                                    <option value="<?php echo $product['id']; ?>">
                                        <?php echo htmlspecialchars($product['name']); ?> (<?php echo htmlspecialchars($product['sku']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <?php if (empty($products)): ?>
                                <div class="field-note">No products available. Use the button below to add one.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label for="quantity_produced">Quantity to Produce:</label>
                            <input type="number" id="quantity_produced" name="quantity_produced" min="1" required>
                        </div>
                    </div>
                </div>
                
                <div class="quick-add-product">
                    <button type="button" id="showQuickAddProduct" class="button small">
                        <i class="fas fa-plus-circle"></i> Add New Product
                    </button>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="start_date">Start Date:</label>
                            <input type="date" id="start_date" name="start_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label for="expected_completion_date">Expected Completion Date:</label>
                            <input type="date" id="expected_completion_date" name="expected_completion_date" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="notes">Notes:</label>
                            <textarea id="notes" name="notes" rows="3"></textarea>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-section">
                <h3>Material Requirements</h3>
                <div class="materials-container">
                    <table class="materials-table" id="materialsTable">
                        <thead>
                            <tr>
                                <th>Material</th>
                                <th>Quantity</th>
                                <th>Available Stock</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr id="noMaterialsRow">
                                <td colspan="4" class="no-records">No materials added yet. Use the form below to add materials.</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div class="add-material-form">
                        <div class="form-row">
                            <div class="form-col">
                                <div class="form-group">
                                    <label for="material_id">Material:</label>
                                    <select id="material_id">
                                        <option value="">Select Material</option>
                                        <?php if (!empty($materials)): ?>
                                            <?php foreach($materials as $material): ?>
                                            <option value="<?php echo $material['id']; ?>" 
                                                    data-name="<?php echo htmlspecialchars($material['name']); ?>"
                                                    data-unit="<?php echo htmlspecialchars($material['unit']); ?>"
                                                    data-stock="<?php echo $material['stock_quantity']; ?>"
                                                    <?php echo $material['stock_quantity'] <= 0 ? 'disabled' : ''; ?>>
                                                <?php echo htmlspecialchars($material['name']); ?> 
                                                (<?php echo htmlspecialchars($material['unit']); ?>) - 
                                                Stock: <?php echo number_format($material['stock_quantity'], 2); ?>
                                                <?php echo $material['stock_quantity'] <= 0 ? ' - OUT OF STOCK' : ''; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                    <?php if (empty($materials)): ?>
                                        <div class="field-note">No materials available. Please add materials in the Raw Materials section first.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="form-col">
                                <div class="form-group">
                                    <label for="material_quantity">Quantity:</label>
                                    <div class="input-with-unit">
                                        <input type="number" id="material_quantity" step="0.01" min="0.01">
                                        <span id="material_unit"></span>
                                    </div>
                                </div>
                            </div>
                            <div class="form-col form-col-auto">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <button type="button" id="addMaterialBtn" class="button">Add Material</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Hidden container for materials data -->
                <div id="materialsDataContainer"></div>
            </div>
            <div class="debug-info" style="margin: 20px 0; padding: 10px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px;">
    <h4>Debug Info (Remove in Production)</h4>
    <div id="materialDebugInfo">No materials added yet</div>
</div>
            <div class="form-actions">
                <button type="button" class="button secondary" id="cancelBatch">Cancel</button>
                <button type="submit" class="button primary">Create Batch</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<style>
/* Advanced Batch Progress Visualization Styles */
.production-pipeline {
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    padding: 1rem;
    margin-bottom: 1.5rem;
}

.pipeline-title {
    font-size: 1.1rem;
    margin: 0 0 1rem 0;
    color: var(--text-primary, #333);
}

.pipeline-container {
    position: relative;
    padding: 1rem 0;
}

.pipeline-stages {
    display: flex;
    justify-content: space-between;
    position: relative;
    min-height: 150px;
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
    margin-bottom: 1.5rem;
    position: relative;
}

.stage-header::before {
    content: '';
    width: 20px;
    height: 20px;
    border-radius: 50%;
    position: absolute;
    top: -32px;
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
    font-size: 0.9rem;
    margin-bottom: 0.25rem;
}

.stage-count {
    font-size: 0.8rem;
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
    min-height: 80px;
}

/* Batch balloon styles */
.batch-balloon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    position: relative;
    cursor: pointer;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15);
    transition: transform 0.2s, box-shadow 0.2s;
    font-size: 0.75rem;
    font-weight: 500;
    color: white;
    text-align: center;
}

.batch-balloon:hover {
    transform: scale(1.1);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    z-index: 10;
}

/* Batch color variations */
.batch-color-0 { background-color: #4285f4; }
.batch-color-1 { background-color: #34a853; }
.batch-color-2 { background-color: #ea4335; }
.batch-color-3 { background-color: #fbbc04; }
.batch-color-4 { background-color: #673ab7; }
.batch-color-5 { background-color: #ff7043; }
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
    transform: scale(1.15);
}

.batch-urgent:hover {
    transform: scale(1.25);
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
    transform: scale(1.15);
}

.batch-overdue:hover {
    transform: scale(1.25);
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
}

.batch-alert {
    position: absolute;
    top: -5px;
    right: -5px;
    background-color: #ea4335;
    color: white;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    border: 1px solid white;
}

/* Empty stage styles */
.empty-stage {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 80px;
    padding: 1rem;
}

.empty-message {
    font-size: 0.8rem;
    color: var(--text-secondary, #6c757d);
    font-style: italic;
}

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

/* Time remaining highlight styling */
#popupTimeRemaining [style*="color"] {
  font-weight: 600;
  display: inline-block;
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 0.85rem;
}

/* Red warning (overdue or urgent) */
#popupTimeRemaining [style*="color: #ea4335"] {
  background: rgba(234, 67, 53, 0.12);
  box-shadow: 0 0 0 1px rgba(234, 67, 53, 0.2) inset;
}

/* Yellow warning (approaching deadline) */
#popupTimeRemaining [style*="color: #fbbc04"] {
  background: rgba(251, 188, 4, 0.12);
  box-shadow: 0 0 0 1px rgba(251, 188, 4, 0.2) inset;
}

/* Responsive adjustments */
@media (max-width: 768px) {
  .batch-detail-popup {
    width: calc(100% - 40px);
    max-width: 320px;
  }
}

/* Accessibility enhancements */
@media (prefers-reduced-motion: reduce) {
  .batch-detail-popup,
  .popup-actions .button::before,
  .close-popup:hover {
    animation: none;
    transition: none;
    transform: none;
  }
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
  .batch-detail-popup {
    background: rgba(30, 30, 30, 0.85);
    border-color: rgba(255, 255, 255, 0.08);
  }
  
  .popup-header {
    background: linear-gradient(to right, rgba(40, 40, 40, 0.9), rgba(35, 35, 35, 0.9));
    border-bottom-color: rgba(255, 255, 255, 0.05);
  }
  
  .popup-header h4 {
    color: rgba(255, 255, 255, 0.9);
    text-shadow: 0 1px 0 rgba(0, 0, 0, 0.5);
  }
  
  .close-popup {
    color: rgba(255, 255, 255, 0.7);
  }
  
  .close-popup:hover {
    background: rgba(255, 255, 255, 0.1);
    color: rgba(255, 255, 255, 0.9);
  }
  
  .detail-label {
    color: rgba(200, 200, 200, 0.75);
  }
  
  .detail-value {
    color: rgba(255, 255, 255, 0.85);
  }
  
  .popup-actions {
    background: rgba(35, 35, 35, 0.5);
    border-top-color: rgba(255, 255, 255, 0.05);
  }
  
  .popup-actions .button.small {
    background: rgba(60, 60, 60, 0.4);
    color: rgba(255, 255, 255, 0.85);
    border-color: rgba(255, 255, 255, 0.1);
    box-shadow: 
      0 1px 3px rgba(0, 0, 0, 0.2),
      0 1px 2px rgba(255, 255, 255, 0.05) inset;
  }
  
  .popup-actions .button.small:hover {
    background: rgba(70, 70, 70, 0.5);
  }
  
  .popup-arrow {
    background: rgba(40, 40, 40, 0.9);
    border-color: rgba(255, 255, 255, 0.05);
  }
}

/* Enhanced legend styles */
.status-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 2rem;
    padding: 1rem;
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.legend-section {
    flex: 1;
    min-width: 200px;
}

.legend-title {
    font-size: 0.9rem;
    margin: 0 0 0.75rem 0;
    color: var(--text-primary, #333);
}

.legend-items {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem 1.5rem;
}

.legend-item {
    display: flex;
    align-items: center;
    font-size: 0.85rem;
}

.batch-indicator {
    width: 16px;
    height: 16px;
    margin-right: 6px;
    border-radius: 50%;
    background-color: #4285f4;
}

.batch-indicator.batch-normal {
    background-color: #4285f4;
}

.batch-indicator.batch-warning {
    background-color: #4285f4;
    border: 2px solid #fbbc04;
}

.batch-indicator.batch-urgent {
    background-color: #4285f4;
    border: 2px solid #ea4335;
}

.batch-indicator.batch-overdue {
    background-color: #4285f4;
    border: 2px solid #ea4335;
    background-image: repeating-linear-gradient(
        45deg,
        rgba(0, 0, 0, 0),
        rgba(0, 0, 0, 0) 3px,
        rgba(234, 67, 53, 0.2) 3px,
        rgba(234, 67, 53, 0.2) 6px
    );
}

/* Responsive adjustments */
@media (max-width: 992px) {
    .pipeline-stages {
        overflow-x: auto;
        padding-bottom: 1rem;
        justify-content: flex-start;
        min-height: 180px;
        scroll-padding: 0 20px;
        -webkit-overflow-scrolling: touch; /* Smooth scrolling on iOS */
    }
    
    .pipeline-stage {
        min-width: 120px;
        flex-shrink: 0;
    }
    
    /* Add visual indicator that content is scrollable */
    .pipeline-container::after {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        width: 30px;
        height: 100%;
        background: linear-gradient(to right, rgba(255,255,255,0), rgba(255,255,255,0.8));
        pointer-events: none;
    }
}

@media (max-width: 768px) {
    .status-legend {
        flex-direction: column;
        gap: 1rem;
    }
}
</style><script>
    
document.addEventListener('DOMContentLoaded', function() {
    
    // Load FontAwesome if not present
    if (!document.querySelector('link[href*="font-awesome"]')) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css';
        document.head.appendChild(link);
    }
    
    // DOM Elements - Batch Modal
    const newBatchBtn = document.getElementById('newBatchBtn');
    const batchModal = document.getElementById('batchModal');
    const closeBatchModalBtn = document.getElementById('closeBatchModal');
    const cancelBatchBtn = document.getElementById('cancelBatch');
    
    // DOM Elements - Quick Product
    const quickProductSection = document.getElementById('quickProductSection');
    const showQuickAddProduct = document.getElementById('showQuickAddProduct');
    const cancelQuickProduct = document.getElementById('cancelQuickProduct');
    const quickProductForm = document.getElementById('quickProductForm');
    const productSelect = document.getElementById('product_id');
    
    // DOM Elements - Materials
    const materialSelect = document.getElementById('material_id');
    const materialQuantity = document.getElementById('material_quantity');
    const materialUnit = document.getElementById('material_unit');
    const addMaterialBtn = document.getElementById('addMaterialBtn');
    const materialsTable = document.getElementById('materialsTable');
    const noMaterialsRow = document.getElementById('noMaterialsRow');
    const materialsDataContainer = document.getElementById('materialsDataContainer');
    const batchForm = document.getElementById('batchForm');
    
    // Material tracking
    let materialItems = [];
    let materialCounter = 0;
    
    // Debug element existence
    console.log("Button exists:", !!newBatchBtn);
    console.log("Batch modal exists:", !!batchModal);
    console.log("Product count:", productSelect ? productSelect.options.length - 1 : 0);
    console.log("Material count:", materialSelect ? materialSelect.options.length - 1 : 0);
    
   
    // Modal open/close functions
    function openBatchModal() {
        console.log("Opening batch modal");
        if (batchModal) {
            batchModal.style.display = 'block';
            batchModal.style.zIndex = '2000';
            document.body.style.overflow = 'hidden';
            
            // Reset quick add product section
            if (quickProductSection) {
                quickProductSection.style.display = 'none';
            }
            
            // Reset materials
            materialItems = [];
            if (materialsDataContainer) {
                materialsDataContainer.innerHTML = '';
            }
            
            // Reset material rows
            if (materialsTable) {
                const tbody = materialsTable.querySelector('tbody');
                if (tbody) {
                    const rows = tbody.querySelectorAll('tr:not(#noMaterialsRow)');
                    rows.forEach(row => row.remove());
                }
            }
            
            // Show no materials row
            if (noMaterialsRow) {
                noMaterialsRow.style.display = '';
            }
        }
    }
    
    function closeBatchModal() {
        console.log("Closing batch modal");
        if (batchModal) {
            batchModal.style.display = 'none';
            document.body.style.overflow = '';
            
            // Reset the form
            if (batchForm) {
                batchForm.reset();
            }
        }
    }
    
    // Main batch modal functionality
    if (newBatchBtn && batchModal) {
        // Open batch modal - Fixed event listener
        newBatchBtn.addEventListener('click', function() {
            console.log("New Batch button clicked");
            openBatchModal();
        });
        
        // Direct click handler as fallback
        newBatchBtn.onclick = function() {
            console.log("New Batch button onclick triggered");
            openBatchModal();
            return false; // Prevent default
        };
        
        // Close batch modal - Fixed event listeners
        if (closeBatchModalBtn) {
            closeBatchModalBtn.addEventListener('click', function() {
                closeBatchModal();
            });
        }
        
        if (cancelBatchBtn) {
            cancelBatchBtn.addEventListener('click', function() {
                closeBatchModal();
            });
        }
        
        // Close when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === batchModal) {
                closeBatchModal();
            }
        });
    }
    
    // Quick Product functionality
    if (showQuickAddProduct && quickProductSection) {
        showQuickAddProduct.addEventListener('click', function() {
            console.log("Showing quick add product section");
            quickProductSection.style.display = 'block';
            
            // Scroll to the quick add section
            quickProductSection.scrollIntoView({behavior: 'smooth', block: 'start'});
        });
        
        if (cancelQuickProduct) {
            cancelQuickProduct.addEventListener('click', function() {
                quickProductSection.style.display = 'none';
            });
        }
        
        if (quickProductForm) {
            quickProductForm.addEventListener('submit', function(event) {
                event.preventDefault();
                
                // Show loading state
                const submitButton = this.querySelector('button[type="submit"]');
                const originalText = submitButton.textContent;
                submitButton.disabled = true;
                submitButton.innerHTML = '<span class="spinner"></span> Adding...';
                
                // Get form data
                const formData = new FormData(this);
                
                // Add user ID if needed
                const userId = document.getElementById('current-user-id')?.value;
                if (userId) {
                    formData.append('created_by', userId);
                }
                
                fetch('../api/quick-add-product.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    // Reset button state
                    submitButton.disabled = false;
                    submitButton.textContent = originalText;
                    
                    if (data.success) {
                        // Add the new product to the dropdown
                        const newOption = document.createElement('option');
                        newOption.value = data.product_id;
                        newOption.textContent = `${data.name} (${data.sku})`;
                        productSelect.appendChild(newOption);
                        
                        // Select the new product
                        productSelect.value = data.product_id;
                        
                        // Hide the quick add section
                        quickProductSection.style.display = 'none';
                        
                        // Reset the form
                        quickProductForm.reset();
                        
                        // Show success message
                        showToast('Product added successfully', 'success');
                    } else {
                        showToast(data.message || 'Failed to add product', 'error');
                    }
                })
                .catch(error => {
                    // Reset button state
                    submitButton.disabled = false;
                    submitButton.textContent = originalText;
                    
                    console.error('Error:', error);
                    showToast('An error occurred while adding the product', 'error');
                });
            });
        }
    }
    
    // Material handling
    if (materialSelect) {
        materialSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption && selectedOption.value) {
                const unit = selectedOption.getAttribute('data-unit');
                if (materialUnit) {
                    materialUnit.textContent = unit || '';
                }
            } else if (materialUnit) {
                materialUnit.textContent = '';
            }
        });
    }
    
    // Add material to the list
    if (addMaterialBtn) {
        addMaterialBtn.addEventListener('click', function() {
            // Validate inputs
            if (!materialSelect || !materialSelect.value) {
                showToast('Please select a material', 'error');
                return;
            }
            
            if (!materialQuantity) {
                showToast('Material quantity input not found', 'error');
                return;
            }
            
            const quantity = parseFloat(materialQuantity.value);
            if (isNaN(quantity) || quantity <= 0) {
                showToast('Please enter a valid quantity', 'error');
                return;
            }
            
            // Get selected material details
            const selectedOption = materialSelect.options[materialSelect.selectedIndex];
            if (!selectedOption) {
                showToast('Error retrieving selected material', 'error');
                return;
            }
            
            const materialId = materialSelect.value;
            const materialName = selectedOption.getAttribute('data-name');
            const materialUnitText = selectedOption.getAttribute('data-unit');
            const availableStock = parseFloat(selectedOption.getAttribute('data-stock'));
            
            // Check if quantity exceeds available stock
            if (quantity > availableStock) {
                showToast(`Not enough stock available. Maximum available: ${availableStock} ${materialUnitText}`, 'error');
                return;
            }
            
            // Check if material already exists in the list
            const existingMaterialIndex = materialItems.findIndex(item => item.material_id === materialId);
            
            if (existingMaterialIndex !== -1) {
                // Update existing material
                const existingItem = materialItems[existingMaterialIndex];
                const newQuantity = existingItem.quantity + quantity;
                
                if (newQuantity > availableStock) {
                    showToast(`Cannot add more. Total would exceed available stock (${availableStock} ${materialUnitText})`, 'error');
                    return;
                }
                
                existingItem.quantity = newQuantity;
                
                // Update the table row
                const rowId = `material_row_${existingItem.id}`;
                const row = document.getElementById(rowId);
                
                if (row) {
                    const cells = row.getElementsByTagName('td');
                    if (cells.length > 1) {
                        cells[1].textContent = `${newQuantity} ${materialUnitText}`;
                    }
                }
            } else {
                // Add new material
                const newItem = {
                    id: materialCounter++,
                    material_id: materialId,
                    name: materialName,
                    quantity: quantity,
                    unit: materialUnitText,
                    available_stock: availableStock
                };
                
                materialItems.push(newItem);
                
                // Hide "no materials" row if visible
                if (noMaterialsRow) {
                    noMaterialsRow.style.display = 'none';
                }
                
                // Add row to table
                if (materialsTable) {
                    const tbody = materialsTable.querySelector('tbody');
                    if (tbody) {
                        const newRow = document.createElement('tr');
                        newRow.id = `material_row_${newItem.id}`;
                        
                        newRow.innerHTML = `
                            <td>${escapeHtml(newItem.name)}</td>
                            <td>${newItem.quantity} ${newItem.unit}</td>
                            <td>${newItem.available_stock} ${newItem.unit}</td>
                            <td>
                                <button type="button" class="button small remove-material" data-id="${newItem.id}">Remove</button>
                            </td>
                        `;
                        
                        tbody.appendChild(newRow);
                        
                        // Add event listener to remove button
                        const removeBtn = newRow.querySelector('.remove-material');
                        if (removeBtn) {
                            removeBtn.addEventListener('click', function() {
                                removeMaterial(this.getAttribute('data-id'));
                            });
                        }
                    }
                }
            }
            
            // Reset material selection fields
            if (materialSelect) materialSelect.value = '';
            if (materialQuantity) materialQuantity.value = '';
            if (materialUnit) materialUnit.textContent = '';
            
            // Update hidden inputs
            updateMaterialsData();
        });
    }
    
    // Remove material from the list
    function removeMaterial(id) {
        const index = materialItems.findIndex(item => item.id == id);
        
        if (index !== -1) {
            materialItems.splice(index, 1);
            
            // Remove row from table
            const row = document.getElementById(`material_row_${id}`);
            if (row) {
                row.remove();
            }
            
            // Show "no materials" row if no materials left
            if (materialItems.length === 0 && noMaterialsRow) {
                noMaterialsRow.style.display = '';
            }
            
            // Update hidden inputs
            updateMaterialsData();
        }
    }
    
    // Update hidden inputs for materials
function updateMaterialsData() {
    if (!materialsDataContainer) return;
    
    // Clear existing hidden inputs
    materialsDataContainer.innerHTML = '';
    
    // Create hidden inputs for each material
    materialItems.forEach((item, index) => {
        materialsDataContainer.innerHTML += `
            <input type="hidden" name="materials[${index}][material_id]" value="${item.material_id}">
            <input type="hidden" name="materials[${index}][quantity]" value="${item.quantity}">
        `;
    });
    
    // Update debug info
    const debugInfo = document.getElementById('materialDebugInfo');
    if (debugInfo) {
        if (materialItems.length === 0) {
            debugInfo.textContent = 'No materials added yet';
        } else {
            debugInfo.innerHTML = `
                <p>Material count: ${materialItems.length}</p>
                <pre>${JSON.stringify(materialItems, null, 2)}</pre>
            `;
        }
    }
}
    
    // Form validation
// Replace the form submission code with this AJAX implementation
// Replace the form submission code with this version
if (batchForm) {
    batchForm.addEventListener('submit', function(event) {
        event.preventDefault();
        
        // Validation code (keep your existing validation)
        let isValid = true;
        // ... your validation logic ...
        
        if (!isValid) {
            return;
        }
        
        // Show loading state on submit button
        const submitButton = this.querySelector('button[type="submit"]');
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner"></span> Creating...';
        }
        
        // Get form data
        const formData = new FormData(this);
        
        // Get the form's action URL instead of hardcoding it
        const formAction = this.getAttribute('action');
        console.log("Submitting form to:", formAction);
        
        // Add user ID if needed
        const userId = document.getElementById('current-user-id')?.value;
        if (userId) {
            formData.append('user_id', userId);
        }
        
        // Log what we're submitting (for debugging)
        console.log("Submitting batch with product ID:", formData.get('product_id'));
        console.log("Quantity:", formData.get('quantity_produced'));
        console.log("Materials count:", formData.getAll('materials[0][material_id]').length);
        
        // Submit via AJAX using the form's action URL
        fetch(formAction, {
            method: 'POST',
            body: formData
        })
        .then(async response => {
            // Enhanced error handling
            if (!response.ok) {
                const contentType = response.headers.get('content-type');
                if (contentType && contentType.includes('application/json')) {
                    const errorData = await response.json();
                    throw new Error(errorData.message || `Server responded with status: ${response.status}`);
                } else {
                    const errorText = await response.text();
                    console.error('Server response:', errorText);
                    throw new Error(`Server responded with status: ${response.status}`);
                }
            }
            return response.json();
        })
.then(data => {
    // Reset button state
    if (submitButton) {
        submitButton.disabled = false;
        submitButton.innerHTML = 'Create Batch';
    }
    
    if (data.success) {
        // Enhanced success message with batch number
        const successMessage = data.batch_number ? 
            `Batch ${data.batch_number} created successfully!` : 
            'Batch created successfully!';
        
        // Show toast notification
        showToast(successMessage, 'success');
        
        // Add visible success alert to page
        const successAlert = document.createElement('div');
        successAlert.className = 'success-alert';
        successAlert.innerHTML = `
            <div class="success-icon"><i class="fas fa-check-circle"></i></div>
            <div class="success-content">
                <h3>${successMessage}</h3>
                <p>The new batch has been added to your manufacturing queue.</p>
            </div>
            <button type="button" class="close-alert">&times;</button>
        `;
        
        // Insert after page header
        const pageHeader = document.querySelector('.page-header');
        if (pageHeader && pageHeader.nextSibling) {
            pageHeader.parentNode.insertBefore(successAlert, pageHeader.nextSibling);
        } else {
            document.querySelector('.container').prepend(successAlert);
        }
        
        // Add close functionality
        successAlert.querySelector('.close-alert').addEventListener('click', function() {
            successAlert.remove();
        });
        
        // Auto-remove after 10 seconds
        setTimeout(() => {
            if (document.body.contains(successAlert)) {
                successAlert.classList.add('fade-out');
                setTimeout(() => successAlert.remove(), 500);
            }
        }, 10000);
        
        // Close the modal
        closeBatchModal();
        
        // Reload the page to show the new batch
        setTimeout(() => {
            window.location.reload();
        }, 1500);
        
        // Log activity
        if (typeof logUserActivity === 'function' && productSelect) {
            try {
                const productName = productSelect.options[productSelect.selectedIndex].text;
                const quantity = quantityInput ? quantityInput.value : 'unknown';
                logUserActivity(
                    'create', 
                    'manufacturing', 
                    `Created batch ${data.batch_number} for ${quantity} units of ${productName}`
                );
            } catch (e) {
                console.error('Error logging activity:', e);
            }
        }
    } else {
        // Show error message from server
        showToast(data.message || 'Failed to create batch', 'error');
    }
})
.catch(error => {
            console.error('Error submitting batch:', error);
            
            // Reset button state
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.innerHTML = 'Create Batch';
            }
            
            // Show user-friendly error
            showToast('An error occurred while creating the batch. Please try again.', 'error');
        });
    });
}    // Helper function: Show toast notifications
    function showToast(message, type = 'info') {
        // Check if toast container exists, create if not
        let toastContainer = document.getElementById('toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toast-container';
            document.body.appendChild(toastContainer);
        }
        
        // Create toast element
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        
        // Add to container
        toastContainer.appendChild(toast);
        
        // Remove after timeout
        setTimeout(() => {
            toast.classList.add('toast-hide');
            setTimeout(() => {
                toast.remove();
            }, 300);
        }, 3000);
    }
    
    // Helper function: Escape HTML to prevent XSS
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Activity logging fallback
    if (typeof logUserActivity !== 'function') {
        window.logUserActivity = function(action, module, description) {
            console.log(`Activity logged: ${action} - ${module} - ${description}`);
            
            // Optional: Send to server if you want to track activities
            const userId = document.getElementById('current-user-id')?.value;
            if (userId) {
                fetch('../api/log-activity.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        user_id: userId,
                        action_type: action,
                        module: module,
                        description: description
                    })
                }).catch(error => console.error('Error logging activity:', error));
            }
        };
    }
    
    // Log page view
    if (typeof logUserActivity === 'function') {
        logUserActivity('read', 'manufacturing', 'Viewed manufacturing batches');
    }
    
    // Force modal to be visible when debugging is needed
    // Uncomment the line below to test modal visibility
    // setTimeout(() => openBatchModal(), 1000);
    
    // Move this to the end of the function
    // Initialize batch balloons
    initBatchBalloons();
    
    // Function to initialize interactive batch balloons
// Enhanced popup initialization
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
      
      // Add ripple effect to buttons
      document.querySelectorAll('.popup-actions .button').forEach(button => {
        button.addEventListener('mousedown', createRipple);
      });
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
  
  // Ripple effect for buttons
  function createRipple(event) {
    const button = event.currentTarget;
    
    const circle = document.createElement('span');
    const diameter = Math.max(button.clientWidth, button.clientHeight);
    const radius = diameter / 2;
    
    const rect = button.getBoundingClientRect();
    
    circle.style.width = circle.style.height = `${diameter}px`;
    circle.style.left = `${event.clientX - rect.left - radius}px`;
    circle.style.top = `${event.clientY - rect.top - radius}px`;
    circle.classList.add('ripple');
    
    const ripple = button.querySelector('.ripple');
    if (ripple) {
      ripple.remove();
    }
    
    button.appendChild(circle);
  }
  
  // Add ripple styles
  if (!document.querySelector('style#ripple-style')) {
    const rippleStyle = document.createElement('style');
    rippleStyle.id = 'ripple-style';
    rippleStyle.textContent = `
      .button {
        position: relative;
        overflow: hidden;
      }
      
      .ripple {
        position: absolute;
        border-radius: 50%;
        transform: scale(0);
        animation: ripple 0.6s linear;
        background-color: rgba(255, 255, 255, 0.4);
      }
      
      @keyframes ripple {
        to {
          transform: scale(4);
          opacity: 0;
        }
      }
    `;
    document.head.appendChild(rippleStyle);
  }
  
  // Helper function to format dates
  function formatDate(dateString) {
    const options = { year: 'numeric', month: 'short', day: 'numeric' };
    return new Date(dateString).toLocaleDateString(undefined, options);
  }
}
});

</script>



<style>
/* Toast notification styles */
#toast-container {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.toast {
    min-width: 250px;
    max-width: 350px;
    padding: 12px 16px;
    border-radius: var(--border-radius-sm);
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
    color: white;
    font-size: var(--font-size-sm);
    animation: toast-in 0.3s ease-out;
    transition: transform 0.3s ease, opacity 0.3s ease;
}

.toast-info {
    background-color: var(--primary);
}

.toast-error {
    background-color: var(--error);
}

.toast-success {
    background-color: #34a853;
}

.toast-warning {
    background-color: #fbbc04;
    color: #333;
}

.toast-hide {
    transform: translateX(100%);
    opacity: 0;
}

@keyframes toast-in {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* Modal styles */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    overflow-y: auto;
    padding: 20px;
}

.modal-content {
    background-color: white;
    margin: 50px auto;
    padding: 2rem;
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-lg);
    max-width: 600px;
    width: 100%;
    position: relative;
    animation: modalFadeIn 0.3s ease-out;
}

@keyframes modalFadeIn {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

.close-modal {
    position: absolute;
    top: 1rem;
    right: 1.5rem;
    font-size: 1.5rem;
    color: var(--text-secondary);
    cursor: pointer;
    transition: color 0.2s;
}

.close-modal:hover {
    color: var(--text-primary);
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
.status-badge.status-pending { background-color: #fbbc04; }
.status-badge.status-cutting { background-color: #4285f4; }
.status-badge.status-stitching { background-color: #673ab7; }
.status-badge.status-ironing { background-color: #f06292; }
.status-badge.status-packaging { background-color: #ff7043; }
.status-badge.status-completed { background-color: #34a853; }

/* Add subtle enhancement for better visual hierarchy */
.status-badge {
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.status-badge:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15);
}

/* Ensure accessibility by maintaining contrast ratios */
.status-badge.status-pending { 
    background-color: #fbbc04; 
    color: rgba(0, 0, 0, 0.9); /* Darker text for yellow background */
    text-shadow: none;
}

/* Responsive adjustments for small screens */
@media (max-width: 576px) {
    .status-badge {
        padding: 0.2rem 0.4rem;
        font-size: 0.75rem;
    }
}

/* Support for users who prefer reduced motion */
@media (prefers-reduced-motion: reduce) {
    .status-badge {
        transition: none;
    }
    
    .status-badge:hover {
        transform: none;
    }
}

/* Respect user preference for reduced motion */
@media (prefers-reduced-motion: reduce) {
    .batch-balloon, 
    .batch-warning, 
    .batch-urgent, 
    .batch-overdue,
    .batch-detail-popup {
        animation: none !important;
        transition: none !important;
    }
    
    .batch-urgent, 
    .batch-overdue {
        transform: none !important;
    }
    
    .batch-urgent:hover, 
    .batch-overdue:hover {
        transform: scale(1.1) !important;
    }
}

/* Focus styles for better keyboard navigation */
button:focus, 
a:focus, 
input:focus, 
select:focus, 
textarea:focus {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}

/* Screen reader only class */
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border-width: 0;
}
</style>

<?php include_once '../includes/footer.php'; ?>
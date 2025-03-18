<?php
session_start();
$page_title = "Inventory Management";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get query parameters for filtering
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$location_filter = isset($_GET['location']) ? $_GET['location'] : 'manufacturing'; // Default to manufacturing

// Set up pagination
$records_per_page = 10;
$page = isset($_GET['page']) ? $_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Build where clause for filtering
$where_clause = "WHERE i.location = :location";
$params = [':location' => $location_filter];

if(!empty($search)) {
    $where_clause .= " AND (p.name LIKE :search OR p.sku LIKE :search)";
    $params[':search'] = "%{$search}%";
}

// Get total records for pagination
try {
    $count_query = "SELECT COUNT(*) as total 
                   FROM inventory i
                   JOIN products p ON i.product_id = p.id
                   $where_clause";
    $count_stmt = $db->prepare($count_query);
    foreach($params as $param => $value) {
        $count_stmt->bindValue($param, $value);
    }
    $count_stmt->execute();
    $total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_records / $records_per_page);
} catch (PDOException $e) {
    error_log("Count query error: " . $e->getMessage());
    $total_records = 0;
    $total_pages = 1;
}

// Get inventory items with pagination and filters
try {
    $inventory_query = "SELECT i.id, p.id as product_id, p.name as product_name, p.sku, i.quantity, i.location, 
                       i.updated_at, COALESCE(mb.batch_number, 'N/A') as batch_number,
                       COALESCE(mb.id, 0) as batch_id
                       FROM inventory i 
                       JOIN products p ON i.product_id = p.id
                       LEFT JOIN manufacturing_batches mb ON p.id = mb.product_id AND mb.status = 'completed'
                       $where_clause
                       GROUP BY i.id
                       ORDER BY i.updated_at DESC
                       LIMIT :offset, :records_per_page";
    $inventory_stmt = $db->prepare($inventory_query);
    foreach($params as $param => $value) {
        $inventory_stmt->bindValue($param, $value);
    }
    $inventory_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $inventory_stmt->bindValue(':records_per_page', $records_per_page, PDO::PARAM_INT);
    $inventory_stmt->execute();
} catch (PDOException $e) {
    error_log("Inventory query error: " . $e->getMessage());
}

// Get locations for dropdown
$locations = ['manufacturing', 'wholesale', 'transit'];

// Get inventory summary by location
try {
    $summary_query = "SELECT location, SUM(quantity) as total_quantity, COUNT(DISTINCT product_id) as product_count
                     FROM inventory
                     GROUP BY location";
    $summary_stmt = $db->prepare($summary_query);
    $summary_stmt->execute();
    $inventory_summary = [];
    while($row = $summary_stmt->fetch(PDO::FETCH_ASSOC)) {
        $inventory_summary[$row['location']] = $row;
    }
} catch (PDOException $e) {
    error_log("Summary query error: " . $e->getMessage());
}

// Get inventory timeline data for the chart
try {
    $timeline_query = "SELECT DATE(t.transfer_date) as date, t.from_location, t.to_location, SUM(t.quantity) as quantity
                      FROM inventory_transfers t
                      WHERE t.transfer_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                      GROUP BY DATE(t.transfer_date), t.from_location, t.to_location
                      ORDER BY t.transfer_date";
    $timeline_stmt = $db->prepare($timeline_query);
    $timeline_stmt->execute();
    $timeline_data = $timeline_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Timeline query error: " . $e->getMessage());
    $timeline_data = [];
}

// Get recent inventory transfers
$transfers_query = "SELECT t.id, p.name as product_name, t.quantity, t.from_location, 
                   t.to_location, t.transfer_date, t.status, u.username as initiated_by_user
                   FROM inventory_transfers t
                   JOIN products p ON t.product_id = p.id
                   JOIN users u ON t.initiated_by = u.id
                   ORDER BY t.transfer_date DESC
                   LIMIT 5";
$transfers_stmt = $db->prepare($transfers_query);
$transfers_stmt->execute();

// Check for transfer success message from redirect
$transfer_success = isset($_GET['transfer']) && $_GET['transfer'] === 'success';
?>

<div class="page-header">
    <h2>Inventory Management</h2>
</div>

<?php if ($transfer_success): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i>
    <span>Inventory transfer initiated successfully</span>
    <button type="button" class="close-alert">&times;</button>
</div>
<?php endif; ?>

<!-- Inventory Summary Cards -->
<div class="inventory-summary">
    <?php foreach ($locations as $location): ?>
        <?php 
        $locationName = ucfirst($location);
        $isActive = $location === $location_filter;
        $totalQuantity = $inventory_summary[$location]['total_quantity'] ?? 0;
        $productCount = $inventory_summary[$location]['product_count'] ?? 0;
        ?>
        <a href="?location=<?php echo $location; ?>" class="summary-card <?php echo $isActive ? 'active' : ''; ?>">
            <div class="summary-icon">
                <?php if ($location === 'manufacturing'): ?>
                    <i class="fas fa-industry"></i>
                <?php elseif ($location === 'transit'): ?>
                    <i class="fas fa-truck"></i>
                <?php elseif ($location === 'wholesale'): ?>
                    <i class="fas fa-warehouse"></i>
                <?php endif; ?>
            </div>
            <div class="summary-details">
                <h3><?php echo $locationName; ?></h3>
                <div class="summary-stats">
                    <div class="stat">
                        <span class="stat-value"><?php echo number_format($totalQuantity); ?></span>
                        <span class="stat-label">Items</span>
                    </div>
                    <div class="stat">
                        <span class="stat-value"><?php echo number_format($productCount); ?></span>
                        <span class="stat-label">Products</span>
                    </div>
                </div>
            </div>
            <?php if ($isActive): ?>
                <div class="active-indicator"></div>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Inventory Timeline Chart -->
<div class="dashboard-card full-width">
    <div class="card-header">
        <h3>Inventory Movement (Last 30 Days)</h3>
    </div>
    <div class="card-content">
        <div class="inventory-timeline">
            <canvas id="inventoryTimelineChart" height="300"></canvas>
        </div>
        <div class="timeline-legend">
            <div class="legend-item">
                <span class="color-box manufacturing-color"></span>
                <span>Manufacturing</span>
            </div>
            <div class="legend-item">
                <span class="color-box transit-color"></span>
                <span>Transit</span>
            </div>
            <div class="legend-item">
                <span class="color-box wholesale-color"></span>
                <span>Wholesale</span>
            </div>
        </div>
    </div>
</div>

<!-- Filter and Search -->
<div class="filter-container">
    <form id="filterForm" method="get" class="filter-form">
        <input type="hidden" name="location" value="<?php echo $location_filter; ?>">
        <div class="filter-row">
            <div class="filter-group search-group">
                <label for="search">Search:</label>
                <div class="search-input-container">
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Product name, SKU">
                    <button type="submit" class="search-button">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div class="filter-actions">
                <a href="inventory.php?location=<?php echo $location_filter; ?>" class="button secondary">Reset Filters</a>
            </div>
        </div>
    </form>
</div>

<!-- Inventory Table -->
<div class="dashboard-card full-width">
    <div class="card-header">
        <h3><?php echo ucfirst($location_filter); ?> Inventory</h3>
        <div class="pagination-info">
            <?php if ($total_records > 0): ?>
            Showing <?php echo min(($page - 1) * $records_per_page + 1, $total_records); ?> to 
            <?php echo min($page * $records_per_page, $total_records); ?> of 
            <?php echo $total_records; ?> items
            <?php else: ?>
            No inventory items found
            <?php endif; ?>
        </div>
    </div>
    <div class="card-content">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Batch</th>
                    <th>Quantity</th>
                    <th>Last Updated</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($inventory_stmt && $inventory_stmt->rowCount() > 0): ?>
                    <?php while($item = $inventory_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                        <td><?php echo htmlspecialchars($item['sku']); ?></td>
                        <td>
                            <?php if ($item['batch_id'] > 0): ?>
                                <a href="view-batch.php?id=<?php echo $item['batch_id']; ?>" class="batch-link">
                                    <?php echo htmlspecialchars($item['batch_number']); ?>
                                </a>
                            <?php else: ?>
                                <?php echo htmlspecialchars($item['batch_number']); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo number_format($item['quantity']); ?></td>
                        <td><?php echo date('Y-m-d', strtotime($item['updated_at'])); ?></td>
                        <td><span class="location-badge location-<?php echo $item['location']; ?>"><?php echo ucfirst($item['location']); ?></span></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                <tr>
                    <td colspan="6" class="no-records">No inventory items found</td>
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
            if(!empty($search)) $pagination_query .= '&search=' . urlencode($search);
            $pagination_query .= '&location=' . urlencode($location_filter);
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

<!-- Recent Transfers Table -->
<div class="dashboard-card full-width">
    <div class="card-header">
        <h3>Recent Inventory Transfers</h3>
    </div>
    <div class="card-content">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Initiated By</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($transfers_stmt && $transfers_stmt->rowCount() > 0): ?>
                    <?php while($transfer = $transfers_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($transfer['product_name']); ?></td>
                        <td><?php echo number_format($transfer['quantity']); ?></td>
                        <td><span class="location-badge location-<?php echo $transfer['from_location']; ?>"><?php echo ucfirst($transfer['from_location']); ?></span></td>
                        <td><span class="location-badge location-<?php echo $transfer['to_location']; ?>"><?php echo ucfirst($transfer['to_location']); ?></span></td>
                        <td><?php echo htmlspecialchars($transfer['transfer_date']); ?></td>
                        <td><span class="status-badge status-<?php echo $transfer['status']; ?>"><?php echo ucfirst($transfer['status']); ?></span></td>
                        <td><?php echo htmlspecialchars($transfer['initiated_by_user']); ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                <tr>
                    <td colspan="7" class="no-records">No recent transfers found</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<style>

/* Dashboard Card Component
 * 
 * A versatile card component for dashboard interfaces with:
 * - Responsive behavior across all device sizes
 * - Accessible contrast ratios and focus states
 * - Consistent spacing and visual hierarchy
 * - Support for various content types
 * - Performance optimizations
 */

.dashboard-card {
  --card-border-radius: 8px;
  --card-padding: 1.5rem;
  --card-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
  --card-shadow-hover: 0 4px 12px rgba(0, 0, 0, 0.12);
  --card-bg: #ffffff;
  --card-header-border: 1px solid rgba(0, 0, 0, 0.08);
  --card-animation-duration: 0.2s;
  
  display: flex;
  flex-direction: column;
  background-color: var(--card-bg);
  border-radius: var(--card-border-radius);
  box-shadow: var(--card-shadow);
  margin-bottom: 1.5rem;
  transition: box-shadow var(--card-animation-duration) ease-in-out, 
              transform var(--card-animation-duration) ease-in-out;
  overflow: hidden; /* Ensures content respects border radius */
}

/* Card hover effect for interactive cards */
.dashboard-card.interactive:hover {
  box-shadow: var(--card-shadow-hover);
  transform: translateY(-2px);
}

/* Full width modifier */
.dashboard-card.full-width {
  width: 100%;
}

/* Card header with flexible layout */
.dashboard-card .card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: var(--card-padding);
  border-bottom: var(--card-header-border);
  flex-wrap: wrap;
  gap: 1rem;
}

/* Card header title */
.dashboard-card .card-header h2,
.dashboard-card .card-header h3 {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 600;
  color: #333;
  line-height: 1.3;
}

/* Card content with proper padding */
.dashboard-card .card-content {
  padding: var(--card-padding);
  flex: 1;
}

/* Card footer for actions or additional info */
.dashboard-card .card-footer {
  padding: var(--card-padding);
  border-top: var(--card-header-border);
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 1rem;
}

/* Pagination info in card header */
.dashboard-card .pagination-info {
  font-size: 0.875rem;
  color: #6c757d;
}

/* Inventory specific styles */
.inventory-summary {
    display: flex;
    gap: 1rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}

.summary-card {
    flex: 1;
    min-width: 250px;
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    padding: 1.25rem;
    display: flex;
    align-items: center;
    position: relative;
    overflow: hidden;
    text-decoration: none;
    color: inherit;
    transition: all 0.3s ease;
}

.summary-card:hover {
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    transform: translateY(-2px);
}

.summary-card.active {
    border-left: 4px solid var(--primary, #1a73e8);
    padding-left: calc(1.25rem - 4px);
}

.active-indicator {
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background-color: var(--primary, #1a73e8);
}

.summary-icon {
    font-size: 2.5rem;
    color: var(--primary, #1a73e8);
    margin-right: 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 60px;
}

.summary-details {
    flex: 1;
}

.summary-details h3 {
    margin: 0 0 0.5rem 0;
    font-size: 1.1rem;
}

.summary-stats {
    display: flex;
    gap: 1.5rem;
}

.stat {
    display: flex;
    flex-direction: column;
}

.stat-value {
    font-weight: 600;
    font-size: 1.2rem;
    color: var(--text-primary, #333);
}

.stat-label {
    font-size: 0.8rem;
    color: var(--text-secondary, #6c757d);
}

/* Batch link style */
.batch-link {
    color: var(--primary, #1a73e8);
    text-decoration: none;
    font-weight: 500;
    transition: color 0.2s;
}

.batch-link:hover {
    color: var(--primary-dark, #0d47a1);
    text-decoration: underline;
}

/* Alert styles */
.alert {
    padding: 1rem;
    border-radius: 4px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.alert-success {
    background-color: #d4edda;
    border-left: 4px solid #28a745;
    color: #155724;
}

.alert-error {
    background-color: #f8d7da;
    border-left: 4px solid #dc3545;
    color: #721c24;
}

.alert i {
    font-size: 1.25rem;
}

.alert span {
    flex: 1;
}

.close-alert {
    background: none;
    border: none;
    font-size: 1.25rem;
    line-height: 1;
    cursor: pointer;
    color: inherit;
    opacity: 0.7;
}

.close-alert:hover {
    opacity: 1;
}

/* Location and Status badges */
.location-badge, .status-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: 0.85rem;
    font-weight: 500;
}

.location-manufacturing {
    background-color: rgba(66, 133, 244, 0.1);
    color: #4285f4;
}

.location-transit {
    background-color: rgba(251, 188, 4, 0.1);
    color: #b06000;
}

.location-wholesale {
    background-color: rgba(52, 168, 83, 0.1);
    color: #34a853;
}

.status-pending {
    background-color: #fff3cd;
    color: #856404;
}

.status-confirmed {
    background-color: #d4edda;
    color: #155724;
}

.status-cancelled {
    background-color: #f8d7da;
    color: #721c24;
}

/* Inventory Timeline Chart */
.inventory-timeline {
    width: 100%;
    height: 300px;
    margin-bottom: 1rem;
}

.timeline-legend {
    display: flex;
    justify-content: center;
    gap: 2rem;
    margin-top: 1rem;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.color-box {
    width: 16px;
    height: 16px;
    border-radius: 3px;
}

.manufacturing-color {
    background-color: #4285f4;
}

.transit-color {
    background-color: #fbbc04;
}

.wholesale-color {
    background-color: #34a853;
}

/* Filter container */
.filter-container {
    margin-bottom: 1.5rem;
}

.filter-form {
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    padding: 1rem;
}

.filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: flex-end;
}

.filter-group {
    flex: 1;
    min-width: 200px;
}

.filter-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-size: 0.9rem;
    font-weight: 500;
}

.search-input-container {
    position: relative;
}

.search-input-container input {
    width: 100%;
    padding: 0.5rem 2.5rem 0.5rem 0.75rem;
    border: 1px solid #ced4da;
    border-radius: 4px;
}

.search-button {
    position: absolute;
    right: 0;
    top: 0;
    bottom: 0;
    background: none;
    border: none;
    padding: 0 0.75rem;
    color: #6c757d;
    cursor: pointer;
}

.search-button:hover {
    color: #1a73e8;
}

.filter-actions {
    display: flex;
    gap: 0.75rem;
}

.button {
    padding: 0.5rem 1rem;
    border-radius: 4px;
    font-weight: 500;
    cursor: pointer;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
    transition: all 0.2s;
}

.button.primary {
    background-color: #4285f4;
    color: white;
}

.button.primary:hover {
    background-color: #3367d6;
}

.button.secondary {
    background-color: #f8f9fa;
    color: #495057;
    border: 1px solid #ced4da;
}

.button.secondary:hover {
    background-color: #e9ecef;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .inventory-summary {
        flex-direction: column;
    }
    
    .summary-card {
        width: 100%;
    }
    
    .summary-stats {
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .filter-row {
        flex-direction: column;
    }
    
    .filter-group, .filter-actions {
        width: 100%;
    }
    
    .timeline-legend {
        flex-direction: column;
        align-items: center;
        gap: 0.75rem;
    }
}

/* Accessibility enhancements */
@media (prefers-reduced-motion: reduce) {
    .summary-card, .button {
        transition: none;
    }
}

/* Focus styles for better keyboard navigation */
.button:focus,
select:focus,
input:focus {
    outline: 2px solid #4285f4;
    outline-offset: 2px;
}

.close-alert:focus {
    outline: 2px solid #4285f4;
    outline-offset: 2px;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Make sure Font Awesome is loaded
    if (typeof FontAwesome === 'undefined') {
        const fontAwesome = document.createElement('link');
        fontAwesome.rel = 'stylesheet';
        fontAwesome.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css';
        document.head.appendChild(fontAwesome);
    }
    
    // Alert close buttons
    const alertCloseButtons = document.querySelectorAll('.close-alert');
    alertCloseButtons.forEach(button => {
        button.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });
    
    // Render inventory timeline chart
    const timelineCtx = document.getElementById('inventoryTimelineChart');
    
    if (timelineCtx) {
        // Prepare data for chart
        const timelineLabels = <?php 
            // Get unique dates for the last 30 days
            $dates = [];
            $current = new DateTime();
            $current->modify('-30 days');
            
            for ($i = 0; $i < 30; $i++) {
                $current->modify('+1 day');
                $dates[] = $current->format('Y-m-d');
            }
            
            echo json_encode($dates); 
        ?>;
        
        // Prepare datasets
        const manufacturingData = Array(timelineLabels.length).fill(0);
        const transitData = Array(timelineLabels.length).fill(0);
        const wholesaleData = Array(timelineLabels.length).fill(0);
        
        // Fill in the data from the database
        <?php foreach ($timeline_data as $item): ?>
            const dateIndex = timelineLabels.indexOf('<?php echo $item['date']; ?>');
            if (dateIndex !== -1) {
                if ('<?php echo $item['to_location']; ?>' === 'manufacturing') {
                    manufacturingData[dateIndex] += <?php echo $item['quantity']; ?>;
                } else if ('<?php echo $item['to_location']; ?>' === 'transit') {
                    transitData[dateIndex] += <?php echo $item['quantity']; ?>;
                } else if ('<?php echo $item['to_location']; ?>' === 'wholesale') {
                    wholesaleData[dateIndex] += <?php echo $item['quantity']; ?>;
                }
                
                if ('<?php echo $item['from_location']; ?>' === 'manufacturing') {
                    manufacturingData[dateIndex] -= <?php echo $item['quantity']; ?>;
                } else if ('<?php echo $item['from_location']; ?>' === 'transit') {
                    transitData[dateIndex] -= <?php echo $item['quantity']; ?>;
                } else if ('<?php echo $item['from_location']; ?>' === 'wholesale') {
                    wholesaleData[dateIndex] -= <?php echo $item['quantity']; ?>;
                }
            }
        <?php endforeach; ?>
        
        // Create the chart
        new Chart(timelineCtx, {
            type: 'line',
            data: {
                labels: timelineLabels.map(date => {
                    const d = new Date(date);
                    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                }),
                datasets: [
                    {
                        label: 'Manufacturing',
                        data: manufacturingData,
                        borderColor: '#4285f4',
                        backgroundColor: 'rgba(66, 133, 244, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Transit',
                        data: transitData,
                        borderColor: '#fbbc04',
                        backgroundColor: 'rgba(251, 188, 4, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Wholesale',
                        data: wholesaleData,
                        borderColor: '#34a853',
                        backgroundColor: 'rgba(52, 168, 83, 0.1)',
                        tension: 0.4,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                                    tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.raw !== null) {
                                label += context.raw > 0 ? '+' : '';
                                label += context.raw;
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        maxRotation: 45,
                        minRotation: 45
                    }
                },
                y: {
                    title: {
                        display: true,
                        text: 'Inventory Movement'
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                }
            },
            interaction: {
                mode: 'nearest',
                axis: 'x',
                intersect: false
            },
            animation: {
                duration: 1000,
                easing: 'easeOutQuart'
            }
        }
    });
    
    // Log view activity
    if (typeof logUserActivity === 'function') {
        logUserActivity('read', 'inventory', 'Viewed inventory management dashboard');
    }
});
</script>

<?php include_once '../includes/footer.php'; ?>
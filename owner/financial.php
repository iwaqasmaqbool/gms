<!-- owner/financial.php -->
<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
$page_title = "Financial Overview";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get financial summary
$query = "SELECT 
    (SELECT COALESCE(SUM(amount), 0) FROM funds) AS total_investment,
    (SELECT COALESCE(SUM(total_amount), 0) FROM purchases) AS total_purchases,
    (SELECT COALESCE(SUM(amount), 0) FROM manufacturing_costs) AS total_manufacturing_costs,
    (SELECT COALESCE(SUM(net_amount), 0) FROM sales) AS total_sales,
    (SELECT COALESCE(SUM(amount), 0) FROM payments) AS total_payments";
$stmt = $db->prepare($query);
$stmt->execute();
$financial = $stmt->fetch(PDO::FETCH_ASSOC);

// Calculate profit
$total_cost = $financial['total_purchases'] + $financial['total_manufacturing_costs'];
$profit = $financial['total_sales'] - $total_cost;
$profit_margin = $financial['total_sales'] > 0 ? ($profit / $financial['total_sales'] * 100) : 0;

// Get recent funds transfers
$funds_query = "SELECT f.id, f.amount, u1.full_name as from_user, u2.full_name as to_user, 
               f.description, f.transfer_date 
               FROM funds f 
               JOIN users u1 ON f.from_user_id = u1.id 
               JOIN users u2 ON f.to_user_id = u2.id 
               ORDER BY f.transfer_date DESC LIMIT 10";
$funds_stmt = $db->prepare($funds_query);
$funds_stmt->execute();

// NEW: Calculate Cash Position across all phases
// 1. Cash on hand (funds not yet spent)
$cash_query = "SELECT COALESCE(SUM(amount), 0) AS cash_on_hand FROM funds 
              WHERE id NOT IN (SELECT DISTINCT fund_id FROM purchases WHERE fund_id IS NOT NULL)";
$cash_stmt = $db->prepare($cash_query);
$cash_stmt->execute();
$cash_on_hand = $cash_stmt->fetchColumn();

// 2. Value in raw materials inventory
$raw_materials_query = "SELECT COALESCE(SUM(stock_quantity * 
                      (SELECT AVG(unit_price) FROM purchases WHERE material_id = rm.id)), 0) 
                      AS raw_materials_value 
                      FROM raw_materials rm";
$raw_materials_stmt = $db->prepare($raw_materials_query);
$raw_materials_stmt->execute();
$raw_materials_value = $raw_materials_stmt->fetchColumn();

// 3. Value in manufacturing (work in progress)
$wip_query = "SELECT COALESCE(SUM(
              (SELECT COALESCE(SUM(mc.amount), 0) FROM manufacturing_costs mc WHERE mc.batch_id = mb.id) +
              (SELECT COALESCE(SUM(
                bm.quantity_required * 
                (SELECT AVG(unit_price) FROM purchases WHERE material_id = bm.material_id)
              ), 0) FROM batch_materials bm WHERE bm.batch_id = mb.id)
            ), 0) AS wip_value
            FROM manufacturing_batches mb
            WHERE mb.status != 'completed'";
$wip_stmt = $db->prepare($wip_query);
$wip_stmt->execute();
$wip_value = $wip_stmt->fetchColumn();

// 4. Value in finished goods inventory at manufacturing
$manufacturing_inventory_query = "SELECT COALESCE(SUM(
                                 i.quantity * (
                                    (SELECT COALESCE(SUM(mc.amount), 0) FROM manufacturing_costs mc WHERE mc.batch_id = mb.id) +
                                    (SELECT COALESCE(SUM(
                                      bm.quantity_required * 
                                      (SELECT AVG(unit_price) FROM purchases WHERE material_id = bm.material_id)
                                    ), 0) FROM batch_materials bm WHERE bm.batch_id = mb.id)
                                 ) / NULLIF(mb.quantity_produced, 0)
                               ), 0) AS manufacturing_value
                               FROM inventory i
                               JOIN products p ON i.product_id = p.id
                               JOIN manufacturing_batches mb ON p.id = mb.product_id
                               WHERE i.location = 'manufacturing' AND mb.status = 'completed'";
$manufacturing_inventory_stmt = $db->prepare($manufacturing_inventory_query);
$manufacturing_inventory_stmt->execute();
$manufacturing_inventory_value = $manufacturing_inventory_stmt->fetchColumn();

// 5. Value in transit inventory (at cost)
$transit_inventory_query = "SELECT COALESCE(SUM(
                           i.quantity * (
                              (SELECT COALESCE(SUM(mc.amount), 0) FROM manufacturing_costs mc WHERE mc.batch_id = mb.id) +
                              (SELECT COALESCE(SUM(
                                bm.quantity_required * 
                                (SELECT AVG(unit_price) FROM purchases WHERE material_id = bm.material_id)
                              ), 0) FROM batch_materials bm WHERE bm.batch_id = mb.id)
                           ) / NULLIF(mb.quantity_produced, 0)
                         ), 0) AS transit_value
                         FROM inventory i
                         JOIN products p ON i.product_id = p.id
                         JOIN manufacturing_batches mb ON p.id = mb.product_id
                         WHERE i.location = 'transit'";
$transit_inventory_stmt = $db->prepare($transit_inventory_query);
$transit_inventory_stmt->execute();
$transit_inventory_value = $transit_inventory_stmt->fetchColumn();

// 6. Value in wholesale inventory (at retail value with 30% margin)
$wholesale_inventory_query = "SELECT COALESCE(SUM(
                             i.quantity * (
                                (SELECT COALESCE(SUM(mc.amount), 0) FROM manufacturing_costs mc WHERE mc.batch_id = mb.id) +
                                (SELECT COALESCE(SUM(
                                  bm.quantity_required * 
                                  (SELECT AVG(unit_price) FROM purchases WHERE material_id = bm.material_id)
                                ), 0) FROM batch_materials bm WHERE bm.batch_id = mb.id)
                             ) / NULLIF(mb.quantity_produced, 0) * 1.3
                           ), 0) AS wholesale_value
                           FROM inventory i
                           JOIN products p ON i.product_id = p.id
                           JOIN manufacturing_batches mb ON p.id = mb.product_id
                           WHERE i.location = 'wholesale'";
$wholesale_inventory_stmt = $db->prepare($wholesale_inventory_query);
$wholesale_inventory_stmt->execute();
$wholesale_inventory_value = $wholesale_inventory_stmt->fetchColumn();

// 7. Value in accounts receivable (sales not yet paid)
$accounts_receivable = $financial['total_sales'] - $financial['total_payments'];

// 8. Calculate total business value (cash position)
$total_business_value = $cash_on_hand + $raw_materials_value + $wip_value + 
                       $manufacturing_inventory_value + $transit_inventory_value + 
                       $wholesale_inventory_value + $accounts_receivable;

// Get cash position breakdown for chart
$cash_position_data = [
    ['phase' => 'Cash on Hand', 'value' => $cash_on_hand],
    ['phase' => 'Raw Materials', 'value' => $raw_materials_value],
    ['phase' => 'Work in Progress', 'value' => $wip_value],
    ['phase' => 'Manufacturing Inventory', 'value' => $manufacturing_inventory_value],
    ['phase' => 'Transit Inventory', 'value' => $transit_inventory_value],
    ['phase' => 'Wholesale Inventory', 'value' => $wholesale_inventory_value],
    ['phase' => 'Accounts Receivable', 'value' => $accounts_receivable]
];

// Convert to JSON for JavaScript
$cash_position_json = json_encode($cash_position_data);
?>

<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($financial['total_investment'], 2); ?></div>
        <div class="stat-label">Total Investment</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($financial['total_purchases'], 2); ?></div>
        <div class="stat-label">Material Purchases</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($financial['total_manufacturing_costs'], 2); ?></div>
        <div class="stat-label">Manufacturing Costs</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($financial['total_sales'], 2); ?></div>
        <div class="stat-label">Total Sales</div>
    </div>
</div>

<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($profit, 2); ?></div>
        <div class="stat-label">Profit</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($profit_margin, 2); ?>%</div>
        <div class="stat-label">Profit Margin</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($financial['total_payments'], 2); ?></div>
        <div class="stat-label">Payments Received</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($financial['total_sales'] - $financial['total_payments'], 2); ?></div>
        <div class="stat-label">Outstanding Receivables</div>
    </div>
</div>

<div class="cash-position-section">
    <h2 class="section-title">Cash Position Analysis</h2>
    <div class="section-description">
        <p>This analysis shows the total business value distributed across different phases of your operations.</p>
    </div>
    
    <div class="cash-position-overview">
        <div class="total-business-value">
            <div class="value-amount"><?php echo number_format($total_business_value, 2); ?></div>
            <div class="value-label">Total Business Value</div>
        </div>
        
        <div class="cash-position-metrics">
            <div class="metric-card">
                <div class="metric-value"><?php echo number_format($cash_on_hand, 2); ?></div>
                <div class="metric-label">Cash on Hand</div>
                <div class="metric-percentage"><?php echo number_format(($cash_on_hand / $total_business_value) * 100, 1); ?>%</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-value"><?php echo number_format($raw_materials_value, 2); ?></div>
                <div class="metric-label">Raw Materials</div>
                <div class="metric-percentage"><?php echo number_format(($raw_materials_value / $total_business_value) * 100, 1); ?>%</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-value"><?php echo number_format($wip_value + $manufacturing_inventory_value, 2); ?></div>
                <div class="metric-label">Manufacturing</div>
                <div class="metric-percentage"><?php echo number_format((($wip_value + $manufacturing_inventory_value) / $total_business_value) * 100, 1); ?>%</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-value"><?php echo number_format($transit_inventory_value, 2); ?></div>
                <div class="metric-label">In Transit</div>
                <div class="metric-percentage"><?php echo number_format(($transit_inventory_value / $total_business_value) * 100, 1); ?>%</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-value"><?php echo number_format($wholesale_inventory_value, 2); ?></div>
                <div class="metric-label">Wholesale (30% Margin)</div>
                <div class="metric-percentage"><?php echo number_format(($wholesale_inventory_value / $total_business_value) * 100, 1); ?>%</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-value"><?php echo number_format($accounts_receivable, 2); ?></div>
                <div class="metric-label">Accounts Receivable</div>
                <div class="metric-percentage"><?php echo number_format(($accounts_receivable / $total_business_value) * 100, 1); ?>%</div>
            </div>
        </div>
    </div>
</div>

<div class="dashboard-grid">
    <div class="dashboard-card full-width">
        <div class="card-header">
            <h2>Cash Position Distribution</h2>
            <div class="card-actions">
                <button class="button small" id="toggleChartViewBtn">Toggle Chart View</button>
                <button class="button small" id="toggleFlowViewBtn">Show Flow Diagram</button>
            </div>
        </div>
        <div class="card-content">
            <div class="chart-container">
                <canvas id="cashPositionChart" height="300"></canvas>
            </div>
            <div class="cash-flow-diagram" style="display: none;">
                <div class="cash-flow-stages">
                    <div class="flow-stage">
                        <div class="stage-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stage-value"><?php echo number_format($cash_on_hand, 2); ?></div>
                        <div class="stage-label">Cash</div>
                        <div class="stage-arrow"><i class="fas fa-arrow-right"></i></div>
                    </div>
                    
                    <div class="flow-stage">
                        <div class="stage-icon">
                            <i class="fas fa-boxes"></i>
                        </div>
                        <div class="stage-value"><?php echo number_format($raw_materials_value, 2); ?></div>
                        <div class="stage-label">Raw Materials</div>
                        <div class="stage-arrow"><i class="fas fa-arrow-right"></i></div>
                    </div>
                    
                    <div class="flow-stage">
                        <div class="stage-icon">
                            <i class="fas fa-industry"></i>
                        </div>
                        <div class="stage-value"><?php echo number_format($wip_value, 2); ?></div>
                        <div class="stage-label">Work in Progress</div>
                        <div class="stage-arrow"><i class="fas fa-arrow-right"></i></div>
                    </div>
                    
                    <div class="flow-stage">
                        <div class="stage-icon">
                            <i class="fas fa-box-open"></i>
                        </div>
                        <div class="stage-value"><?php echo number_format($manufacturing_inventory_value, 2); ?></div>
                        <div class="stage-label">Finished Goods</div>
                        <div class="stage-arrow"><i class="fas fa-arrow-right"></i></div>
                    </div>
                    
                    <div class="flow-stage">
                        <div class="stage-icon">
                            <i class="fas fa-truck"></i>
                        </div>
                        <div class="stage-value"><?php echo number_format($transit_inventory_value, 2); ?></div>
                        <div class="stage-label">In Transit</div>
                        <div class="stage-arrow"><i class="fas fa-arrow-right"></i></div>
                    </div>
                    
                    <div class="flow-stage">
                        <div class="stage-icon">
                            <i class="fas fa-store"></i>
                        </div>
                        <div class="stage-value"><?php echo number_format($wholesale_inventory_value, 2); ?></div>
                        <div class="stage-label">Wholesale</div>
                        <div class="stage-arrow"><i class="fas fa-arrow-right"></i></div>
                    </div>
                    
                    <div class="flow-stage">
                        <div class="stage-icon">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </div>
                        <div class="stage-value"><?php echo number_format($accounts_receivable, 2); ?></div>
                        <div class="stage-label">Receivables</div>
                    </div>
                </div>
                
                <div class="cash-flow-total">
                    <div class="total-label">Total Business Value</div>
                    <div class="total-value"><?php echo number_format($total_business_value, 2); ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="dashboard-card full-width">
        <div class="card-header">
            <h2>Financial Performance</h2>
        </div>
        <div class="card-content">
            <div class="chart-container" id="financialChart" style="height: 300px;">
                <!-- Chart will be rendered here by JavaScript -->
            </div>
        </div>
    </div>
    
    <div class="dashboard-card full-width">
        <div class="card-header">
            <h2>Recent Fund Transfers</h2>
            <button class="button small" id="newFundTransferBtn">New Transfer</button>
        </div>
        <div class="card-content">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Amount</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($fund = $funds_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?php echo date('M j, Y', strtotime($fund['transfer_date'])); ?></td>
                        <td><?php echo htmlspecialchars($fund['from_user']); ?></td>
                        <td><?php echo htmlspecialchars($fund['to_user']); ?></td>
                        <td class="amount-cell"><?php echo number_format($fund['amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($fund['description']); ?></td>
                    </tr>
                    <?php endwhile; ?>
                    
                    <?php if($funds_stmt->rowCount() === 0): ?>
                    <tr>
                        <td colspan="5" class="no-data">No fund transfers found</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- New Fund Transfer Modal -->
<div id="fundTransferModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h2>Transfer Funds</h2>
        <form id="fundTransferForm" action="../api/transfer-funds.php" method="post">
            <div class="form-group">
                <label for="amount">Amount:</label>
                <input type="number" id="amount" name="amount" step="0.01" min="0" required>
            </div>
            <div class="form-group">
                <label for="to_user_id">Transfer To:</label>
                <select id="to_user_id" name="to_user_id" required>
                    <option value="">Select User</option>
                    <?php
                    // Get all incharge users
                    $users_query = "SELECT id, full_name FROM users WHERE role = 'incharge' AND is_active = 1";
                    $users_stmt = $db->prepare($users_query);
                    $users_stmt->execute();
                    while($user = $users_stmt->fetch(PDO::FETCH_ASSOC)) {
                        echo '<option value="' . $user['id'] . '">' . htmlspecialchars($user['full_name']) . '</option>';
                    }
                    ?>
                </select>
            </div>
            <div class="form-group">
                <label for="description">Description:</label>
                <textarea id="description" name="description" rows="3"></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="button secondary" id="cancelFundTransfer">Cancel</button>
                <button type="submit" class="button primary">Transfer Funds</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Fund Transfer Modal functionality
    const transferModal = document.getElementById('fundTransferModal');
    const transferBtn = document.getElementById('newFundTransferBtn');
    const transferCloseBtn = document.querySelector('#fundTransferModal .close-modal');
    const transferCancelBtn = document.getElementById('cancelFundTransfer');
    
    // Open modal
    if (transferBtn) {
        transferBtn.addEventListener('click', function() {
            transferModal.style.display = 'block';
            transferModal.style.zIndex = '2000';
            document.body.style.overflow = 'hidden';
            
            // Focus on first input for better accessibility
            setTimeout(() => {
                document.getElementById('amount').focus();
            }, 100);
        });
    }
    
    // Close modal via X button
    if (transferCloseBtn) {
        transferCloseBtn.addEventListener('click', function() {
            transferModal.style.display = 'none';
            document.body.style.overflow = '';
        });
    }
    
    // Close modal via Cancel button
    if (transferCancelBtn) {
        transferCancelBtn.addEventListener('click', function() {
            transferModal.style.display = 'none';
            document.body.style.overflow = '';
        });
    }
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target === transferModal) {
            transferModal.style.display = 'none';
            document.body.style.overflow = '';
        }
    });
    
    // NEW: Cash Position Chart
    const cashPositionData = <?php echo $cash_position_json; ?>;
    const ctx = document.getElementById('cashPositionChart').getContext('2d');
    
    // Prepare data for the chart
    const labels = cashPositionData.map(item => item.phase);
    const values = cashPositionData.map(item => item.value);
    
    // Color scheme for better accessibility and visual appeal
    const colors = {
        backgrounds: [
            'rgba(54, 162, 235, 0.6)',  // Cash on Hand
            'rgba(255, 206, 86, 0.6)',  // Raw Materials
            'rgba(75, 192, 192, 0.6)',  // Work in Progress
            'rgba(153, 102, 255, 0.6)', // Manufacturing Inventory
            'rgba(255, 159, 64, 0.6)',  // Transit Inventory
            'rgba(255, 99, 132, 0.6)',  // Wholesale Inventory
            'rgba(201, 203, 207, 0.6)'  // Accounts Receivable
        ],
        borders: [
            'rgb(54, 162, 235)',
            'rgb(255, 206, 86)',
            'rgb(75, 192, 192)',
            'rgb(153, 102, 255)',
            'rgb(255, 159, 64)',
            'rgb(255, 99, 132)',
            'rgb(201, 203, 207)'
        ]
    };
    
    // Format currency helper function
    const formatCurrency = (value) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'PKR',
            minimumFractionDigits: 2
        }).format(value);
    };
    
    // Create the chart
    const cashPositionChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Value in Each Phase',
                data: values,
                backgroundColor: colors.backgrounds,
                borderColor: colors.borders,
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const value = context.raw;
                            const formattedValue = formatCurrency(value);
                            
                            const total = values.reduce((a, b) => a + b, 0);
                            const percentage = ((value / total) * 100).toFixed(1);
                            
                            return `${formattedValue} (${percentage}% of total)`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return new Intl.NumberFormat('en-US', {
                                style: 'currency',
                                currency: 'PKR',
                                notation: 'compact',
                                compactDisplay: 'short'
                            }).format(value);
                        }
                    }
                }
            }
        }
    });
    
    // Toggle between bar chart and pie chart
    const toggleChartViewBtn = document.getElementById('toggleChartViewBtn');
    let isBarChart = true;
    
    if (toggleChartViewBtn) {
        toggleChartViewBtn.addEventListener('click', function() {
            isBarChart = !isBarChart;
            
            // Destroy the current chart
            cashPositionChart.destroy();
            
            // Create a new chart with different type
            const newChart = new Chart(ctx, {
                type: isBarChart ? 'bar' : 'pie',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Value in Each Phase',
                        data: values,
                        backgroundColor: colors.backgrounds,
                        borderColor: colors.borders,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: !isBarChart,
                            position: 'right'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const value = context.raw;
                                    const formattedValue = formatCurrency(value);
                                    
                                    const total = values.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    
                                    return `${context.label}: ${formattedValue} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    scales: isBarChart ? {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return new Intl.NumberFormat('en-US', {
                                        style: 'currency',
                                        currency: 'PKR',
                                        notation: 'compact',
                                        compactDisplay: 'short'
                                    }).format(value);
                                }
                            }
                        }
                    } : {}
                }
            });
            
            // Update button text
            this.textContent = isBarChart ? 'Show Pie Chart' : 'Show Bar Chart';
            
            // Update the chart reference
            Object.assign(cashPositionChart, newChart);
        });
    }
    
    // Toggle between chart and flow diagram view
    const toggleFlowViewBtn = document.getElementById('toggleFlowViewBtn');
    const chartContainer = document.querySelector('.chart-container');
    const cashFlowDiagram = document.querySelector('.cash-flow-diagram');
    
    if (toggleFlowViewBtn && chartContainer && cashFlowDiagram) {
        toggleFlowViewBtn.addEventListener('click', function() {
            const isChartVisible = chartContainer.style.display !== 'none';
            
            if (isChartVisible) {
                chartContainer.style.display = 'none';
                cashFlowDiagram.style.display = 'block';
                this.textContent = 'Show Chart';
                // Hide chart toggle button when showing flow diagram
                toggleChartViewBtn.style.display = 'none';
            } else {
                chartContainer.style.display = 'block';
                cashFlowDiagram.style.display = 'none';
                this.textContent = 'Show Flow Diagram';
                // Show chart toggle button when showing chart
                toggleChartViewBtn.style.display = 'inline-block';
            }
        });
    }
    
    // Format all currency values in the DOM for consistency
    const formatDOMCurrencyValues = () => {
        const currencyElements = document.querySelectorAll('.value-amount, .metric-value, .stage-value, .total-value, .amount-cell');
        
        currencyElements.forEach(element => {
            const rawValue = parseFloat(element.textContent.replace(/,/g, ''));
            if (!isNaN(rawValue)) {
                element.setAttribute('data-raw-value', rawValue);
                // Keep the formatted value but add screen reader text
                const srText = document.createElement('span');
                srText.className = 'sr-only';
                srText.textContent = ` Pakistani Rupees`;
                element.appendChild(srText);
            }
        });
    };
    
    // Call the formatter
    formatDOMCurrencyValues();
    
    // Add keyboard navigation for flow stages
    const flowStages = document.querySelectorAll('.flow-stage');
    flowStages.forEach((stage, index) => {
        stage.setAttribute('tabindex', '0');
        stage.setAttribute('aria-label', `${stage.querySelector('.stage-label').textContent} phase: ${stage.querySelector('.stage-value').textContent} Pakistani Rupees`);
        
        // Add keyboard navigation
        stage.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowRight' && index < flowStages.length - 1) {
                flowStages[index + 1].focus();
            } else if (e.key === 'ArrowLeft' && index > 0) {
                flowStages[index - 1].focus();
            }
        });
    });
});
</script>

<style>
/* Cash Position Section Styles */
.cash-position-section {
    background-color: #f8f9fa;
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.section-title {
    margin-top: 0;
    margin-bottom: 0.75rem;
    font-size: 1.25rem;
    color: #212529;
}

.section-description {
    margin-bottom: 1.5rem;
    color: #6c757d;
    font-size: 0.9rem;
}

.cash-position-overview {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.total-business-value {
    background-color: #e8f0fe;
    padding: 1.25rem;
    border-radius: 8px;
    text-align: center;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    border-left: 4px solid #4285f4;
}

.value-amount {
    font-size: 2rem;
    font-weight: 700;
    color: #4285f4;
    margin-bottom: 0.5rem;
}

.value-label {
    font-size: 1rem;
    color: #4d4d4d;
    font-weight: 500;
}

.cash-position-metrics {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1rem;
}

.metric-card {
    background-color: white;
    padding: 1rem;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    position: relative;
    overflow: hidden;
}

.metric-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.metric-card:focus-within {
    outline: 2px solid #4285f4;
    outline-offset: 2px;
}

.metric-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    height: 4px;
    width: 100%;
    background-color: #4285f4;
}

.metric-card:nth-child(1)::before { background-color: #4285f4; } /* Cash */
.metric-card:nth-child(2)::before { background-color: #fbbc04; } /* Raw Materials */
.metric-card:nth-child(3)::before { background-color: #34a853; } /* Manufacturing */
.metric-card:nth-child(4)::before { background-color: #fa7b17; } /* Transit */
.metric-card:nth-child(5)::before { background-color: #ea4335; } /* Wholesale */
.metric-card:nth-child(6)::before { background-color: #9c27b0; } /* Receivables */

.metric-value {
    font-size: 1.25rem;
    font-weight: 600;
    color: #212529;
    margin-bottom: 0.25rem;
}

.metric-label {
    font-size: 0.875rem;
    color: #6c757d;
    margin-bottom: 0.5rem;
}

.metric-percentage {
    font-size: 0.875rem;
    font-weight: 500;
    color: #4285f4;
    background-color: rgba(66, 133, 244, 0.1);
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    display: inline-block;
}

/* Cash Flow Diagram Styles */
.cash-flow-diagram {
    padding: 1.5rem;
    background-color: #f8f9fa;
    border-radius: 8px;
    margin-top: 1rem;
}

.cash-flow-stages {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: center;
    justify-content: center;
    margin-bottom: 2rem;
}

.flow-stage {
    display: flex;
    flex-direction: column;
    align-items: center;
    background-color: white;
    padding: 1rem;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    min-width: 120px;
    position: relative;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.flow-stage:hover, .flow-stage:focus {
    transform: translateY(-3px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    outline: none;
}

.flow-stage:focus {
    outline: 2px solid #4285f4;
    outline-offset: 2px;
}

.stage-icon {
    font-size: 1.5rem;
    margin-bottom: 0.5rem;
    color: #4285f4;
}

.stage-value {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 0.25rem;
    color: #212529;
}

.stage-label {
    font-size: 0.75rem;
    color: #6c757d;
}

.stage-arrow {
    position: absolute;
    right: -12px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    z-index: 1;
}

.flow-stage:nth-child(1) .stage-icon { color: #4285f4; }
.flow-stage:nth-child(2) .stage-icon { color: #fbbc04; }
.flow-stage:nth-child(3) .stage-icon { color: #34a853; }
.flow-stage:nth-child(4) .stage-icon { color: #673ab7; }
.flow-stage:nth-child(5) .stage-icon { color: #fa7b17; }
.flow-stage:nth-child(6) .stage-icon { color: #ea4335; }
.flow-stage:nth-child(7) .stage-icon { color: #9c27b0; }

.cash-flow-total {
    background-color: #e8f0fe;
    padding: 1rem;
    border-radius: 8px;
    text-align: center;
    border-left: 4px solid #4285f4;
}

.total-label {
    font-size: 0.875rem;
    color: #4d4d4d;
    margin-bottom: 0.25rem;
}

.total-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: #4285f4;
}

/* Chart container */
.chart-container {
    position: relative;
    height: 300px;
    width: 100%;
}

/* Card actions */
.card-actions {
    display: flex;
    gap: 0.5rem;
}

/* Amount styling */
.amount-cell {
    font-family: monospace;
    text-align: right;
    font-weight: 500;
}

/* Screen reader only */
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

/* Responsive Styles */
@media (max-width: 768px) {
    .cash-position-metrics {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    }
    
    .cash-flow-stages {
        flex-direction: column;
        align-items: stretch;
    }
    
    .flow-stage {
        width: 100%;
        flex-direction: row;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem;
    }
    
    .stage-icon {
        margin-bottom: 0;
        margin-right: 0.5rem;
    }
    
    .stage-arrow {
        position: static;
        transform: none;
        margin-top: 0.5rem;
        transform: rotate(90deg);
    }
    
    .value-amount {
        font-size: 1.5rem;
    }
    
    .metric-value {
        font-size: 1rem;
    }
    
    .card-actions {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .card-actions .button {
        margin-bottom: 0.5rem;
    }
}

@media (max-width: 576px) {
    .cash-position-section {
        padding: 1rem;
    }
    
    .cash-position-metrics {
        grid-template-columns: 1fr 1fr;
    }
    
    .metric-card {
        padding: 0.75rem;
    }
    
    .metric-value {
        font-size: 0.9rem;
    }
    
    .metric-label, .metric-percentage {
        font-size: 0.75rem;
    }
    
    .section-title {
        font-size: 1.1rem;
    }
    
    .total-business-value {
        padding: 1rem;
    }
    
    .cash-flow-total {
        padding: 0.75rem;
    }
    
    .total-value {
        font-size: 1.25rem;
    }
}

/* Accessibility improvements */
@media (prefers-reduced-motion: reduce) {
    .metric-card, .flow-stage {
        transition: none;
    }
}

@media (prefers-color-scheme: dark) {
    .cash-position-section {
        background-color: #222;
    }
    
    .section-title {
        color: #f8f9fa;
    }
    
    .section-description {
        color: #adb5bd;
    }
    
    .total-business-value {
        background-color: rgba(66, 133, 244, 0.1);
    }
    
    .metric-card {
        background-color: #333;
    }
    
    .metric-value {
        color: #f8f9fa;
    }
    
    .metric-label {
        color: #adb5bd;
    }
    
    .cash-flow-diagram {
        background-color: #222;
    }
    
    .flow-stage {
        background-color: #333;
    }
    
    .stage-value {
        color: #f8f9fa;
    }
    
    .stage-label {
        color: #adb5bd;
    }
    
    .cash-flow-total {
        background-color: rgba(66, 133, 244, 0.15);
    }
}

/* High contrast mode support */
@media (forced-colors: active) {
    .metric-card, .flow-stage, .total-business-value, .cash-flow-total {
        border: 1px solid;
    }
    
    .metric-card::before {
        background-color: currentColor;
    }
}

/* Print styles */
@media print {
    .cash-position-section {
        break-inside: avoid;
        page-break-inside: avoid;
        background-color: white;
        box-shadow: none;
        padding: 0;
    }
    
    .metric-card, .flow-stage {
        box-shadow: none;
        border: 1px solid #ddd;
    }
    
    .chart-container {
        height: 200px;
        page-break-inside: avoid;
    }
    
    .cash-flow-diagram {
        page-break-inside: avoid;
    }
    
    .dashboard-card {
        break-inside: avoid;
        page-break-inside: avoid;
    }
    
    .button, .modal, #toggleChartViewBtn, #toggleFlowViewBtn {
        display: none !important;
    }
}
</style>

<?php include_once '../includes/footer.php'; ?>
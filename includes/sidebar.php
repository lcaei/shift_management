<?php
/**
 * VitalZone - Sidebar
 * Dynamic sidebar with nested menus and counts.
 * For finance, a simplified flat menu.
 * Expects:
 *   $role (string)
 *   $openShiftsCount (int)
 *   $nursesWithOpenShifts (array)
 *   $daysWithOpenShifts (array)
 *   $activePage (string) - 'dashboard', 'schedule', 'reports', 'profit', etc.
 */
?>
<!-- Sidebar -->
<nav class="col-md-3 col-lg-2 d-md-block sidebar p-3">
    <div class="text-center mb-4">
        <h4 class="text-white">VitalZone</h4>
        <span class="badge bg-light text-dark"><?php echo ucfirst($role); ?></span>
    </div>
    <div class="list-group list-group-flush">
        <?php if ($role == 'finance'): ?>
            <!-- Finance Menu (flat) -->
            <a href="index.php" class="list-group-item list-group-item-action bg-transparent text-white <?php echo $activePage == 'dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt me-2"></i> Dashboard
            </a>
            <a href="schedule.php" class="list-group-item list-group-item-action bg-transparent text-white <?php echo $activePage == 'schedule' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-check me-2"></i> Schedule Board
            </a>
            <a href="finance_shifts.php" class="list-group-item list-group-item-action bg-transparent text-white <?php echo $activePage == 'reports' ? 'active' : ''; ?>">
                <i class="fas fa-file-csv me-2"></i> Shift Reports
            </a>
            <a href="#" class="list-group-item list-group-item-action bg-transparent text-white <?php echo $activePage == 'profit' ? 'active' : ''; ?>">
                <i class="fas fa-chart-line me-2"></i> Profit Dashboard
            </a>
        <?php else: ?>
            <!-- Admin / Roaster Menu (nested) -->
            <!-- Home Section -->
            <div class="list-group-item bg-transparent p-0 border-0">
                <a href="#homeMenu" class="list-group-item list-group-item-action bg-transparent text-white d-flex justify-content-between align-items-center" data-bs-toggle="collapse" aria-expanded="<?php echo $activePage == 'home' ? 'true' : 'false'; ?>">
                    <span><i class="fas fa-home me-2"></i> Home</span>
                    <i class="fas fa-chevron-down"></i>
                </a>
                <div class="collapse <?php echo $activePage == 'home' ? 'show' : ''; ?>" id="homeMenu">
                    <div class="bg-dark p-2">
                        <a href="index.php?view=week" class="text-white d-block p-2 <?php echo ($activePage == 'home' && isset($_GET['view']) && $_GET['view'] == 'week') ? 'bg-primary rounded' : ''; ?>">
                            <i class="fas fa-calendar-week me-2"></i> Week
                        </a>
                        <a href="index.php?view=today" class="text-white d-block p-2 <?php echo ($activePage == 'home' && isset($_GET['view']) && $_GET['view'] == 'today') ? 'bg-primary rounded' : ''; ?>">
                            <i class="fas fa-sun me-2"></i> Today
                        </a>
                        <a href="nurse.php" class="text-white d-block p-2 <?php echo $activePage == 'nurses' ? 'bg-primary rounded' : ''; ?>">
                            <i class="fas fa-user-nurse me-2"></i> Employees
                        </a>
                        <a href="groups.php" class="text-white d-block p-2 <?php echo $activePage == 'groups' ? 'bg-primary rounded' : ''; ?>">
                            <i class="fas fa-users-cog me-2"></i> Groups
                        </a>
                        <a href="patient.php" class="text-white d-block p-2 <?php echo $activePage == 'patients' ? 'bg-primary rounded' : ''; ?>">
                            <i class="fas fa-home me-2"></i> Positions
                        </a>
                        <div class="text-white-50 small p-2">Sort by</div>
                    </div>
                </div>
            </div>

            <!-- Schedule Section -->
            <div class="list-group-item bg-transparent p-0 border-0 mt-2">
                <a href="#scheduleMenu" class="list-group-item list-group-item-action bg-transparent text-white d-flex justify-content-between align-items-center" data-bs-toggle="collapse" aria-expanded="<?php echo $activePage == 'schedule' ? 'true' : 'false'; ?>">
                    <span><i class="fas fa-calendar-alt me-2"></i> Schedule</span>
                    <i class="fas fa-chevron-down"></i>
                </a>
                <div class="collapse <?php echo $activePage == 'schedule' ? 'show' : ''; ?>" id="scheduleMenu">
                    <div class="bg-dark p-2">
                        <a href="roaster.php" class="text-white d-block p-2 d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-clock me-2"></i> Open shift</span>
                            <span class="badge bg-warning"><?php echo $openShiftsCount; ?></span>
                        </a>
                        <?php foreach ($nursesWithOpenShifts as $nurse): ?>
                            <a href="roaster.php?nurse_id=<?php echo $nurse['id']; ?>" class="text-white d-block p-2 d-flex justify-content-between align-items-center">
                                <span><?php echo htmlspecialchars($nurse['name']); ?></span>
                                <span class="badge bg-secondary"><?php echo $nurse['open_count']; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- People Section -->
            <div class="list-group-item bg-transparent p-0 border-0 mt-2">
                <a href="#peopleMenu" class="list-group-item list-group-item-action bg-transparent text-white d-flex justify-content-between align-items-center" data-bs-toggle="collapse" aria-expanded="<?php echo $activePage == 'people' ? 'true' : 'false'; ?>">
                    <span><i class="fas fa-users me-2"></i> People</span>
                    <i class="fas fa-chevron-down"></i>
                </a>
                <div class="collapse <?php echo $activePage == 'people' ? 'show' : ''; ?>" id="peopleMenu">
                    <div class="bg-dark p-2">
                        <?php foreach ($daysWithOpenShifts as $day): ?>
                            <a href="roaster.php?date=<?php echo $day['date']; ?>" class="text-white d-block p-2 d-flex justify-content-between align-items-center">
                                <span><?php echo $day['day_name']; ?></span>
                                <span class="badge bg-secondary"><?php echo $day['open_count']; ?></span>
                            </a>
                        <?php endforeach; ?>
                        <a href="roaster.php?view=today" class="text-white d-block p-2 d-flex justify-content-between align-items-center mt-2 border-top pt-2">
                            <span>Today</span>
                            <span class="badge bg-secondary"><?php echo $daysWithOpenShifts[0]['open_count'] ?? 0; ?></span>
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Logout (always visible) -->
        <a href="logout.php" class="list-group-item list-group-item-action bg-transparent text-white mt-5">
            <i class="fas fa-sign-out-alt me-2"></i> Logout
        </a>
    </div>
</nav>
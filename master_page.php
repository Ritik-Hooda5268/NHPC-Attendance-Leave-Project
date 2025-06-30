<?php
$conn = new mysqli("localhost", "root", "Ritik@8685", "nhpc_db");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
date_default_timezone_set("Asia/Kolkata");

$employeeId = $_GET['emp'] ?? null;
$selectedMonth = $_GET['month'] ?? null;

$empList = [];
$res = $conn->query("SELECT employee_id, name FROM employees ORDER BY name ASC");
while ($row = $res->fetch_assoc()) $empList[] = $row;

$used = ['casual' => 0, 'medical' => 0, 'earned' => 0];
$total = ['casual' => 14, 'medical' => 20, 'earned' => 15];
$availableMonths = [];
$calendar = [];

$presentCount = 0;
$absentCount = 0;

if ($employeeId) {
  // Available months
  $months = [];
  foreach (['attendance_log' => 'attendance_date', 'leave_applications' => 'from_date'] as $table => $field) {
    $stmt = $conn->prepare("SELECT DISTINCT DATE_FORMAT($field, '%Y-%m') AS m FROM $table WHERE employee_id = ?");
    $stmt->bind_param("s", $employeeId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $months[] = $r['m'];
    $stmt->close();
  }
  $availableMonths = array_values(array_unique($months));
  rsort($availableMonths);

  // Leave usage
  $year = date('Y');
  $stmt = $conn->prepare("SELECT leave_type, from_date, to_date FROM leave_applications WHERE employee_id = ? AND YEAR(from_date) = ?");
  $stmt->bind_param("si", $employeeId, $year);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $map = [ 'casual leave' => 'casual', 'cl' => 'casual', 'medical leave' => 'medical', 'ml' => 'medical', 'earned leave' => 'earned', 'el' => 'earned' ];
    $raw = strtolower(trim($row['leave_type']));
    $type = $map[$raw] ?? $raw;

    $d1 = new DateTime($row['from_date']);
    $d2 = new DateTime($row['to_date']);

    if ($type === 'casual') {
      $weekdays = 0;
      $period = new DatePeriod($d1, new DateInterval('P1D'), (clone $d2)->modify('+1 day'));
      foreach ($period as $day) {
        $w = $day->format('w');
        if ($w != 0 && $w != 6) $weekdays++;
      }
      $used['casual'] += $weekdays;
    } else {
      $days = $d1->diff($d2)->days + 1;
      if (isset($used[$type])) $used[$type] += $days;
    }
  }
  $stmt->close();

  // Attendance & Leave Status
  if ($selectedMonth) {
    $calendarMap = [];

    // Attendance
    $stmt = $conn->prepare("SELECT attendance_date FROM attendance_log WHERE employee_id = ? AND DATE_FORMAT(attendance_date,'%Y-%m') = ?");
    $stmt->bind_param("ss", $employeeId, $selectedMonth);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
      $calendarMap[$r['attendance_date']] = '✅ Present';
    }
    $stmt->close();

    // Count Present
    $presentCount = count($calendarMap);

    // Leave
    $stmt = $conn->prepare("SELECT leave_type, from_date, to_date FROM leave_applications WHERE employee_id = ? AND DATE_FORMAT(from_date,'%Y-%m') = ?");
    $stmt->bind_param("ss", $employeeId, $selectedMonth);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
      $rawType = strtolower(trim($r['leave_type']));
      $map = ['cl' => 'casual', 'casual leave' => 'casual', 'ml' => 'medical', 'medical leave' => 'medical', 'el' => 'earned', 'earned leave' => 'earned'];
      $type = $map[$rawType] ?? $rawType;
      $code = strtoupper(substr($r['leave_type'], 0, 2));

      $d1 = new DateTime($r['from_date']);
      $d2 = new DateTime($r['to_date']);
      while ($d1 <= $d2) {
        $date = $d1->format('Y-m-d');
        $dow = $d1->format('w');
        if ($type === 'casual' && ($dow == 0 || $dow == 6)) {
          $calendarMap[$date] = "🟡 Holiday (CL)";
        } else {
          $calendarMap[$date] = "🟥 Leave ($code)";
        }
        $d1->modify('+1 day');
      }
    }
    $stmt->close();

    foreach ($calendarMap as $date => $status) {
      $calendar[] = [$date, date('D', strtotime($date)), $status];
    }
    usort($calendar, fn($a, $b) => strcmp($a[0], $b[0]));

    // Absent Calculation
    $monthStart = new DateTime("$selectedMonth-01");
    $monthEnd = clone $monthStart;
    $monthEnd->modify('last day of this month');
    $allDays = new DatePeriod($monthStart, new DateInterval('P1D'), $monthEnd->modify('+1 day'));

    $weekdays = 0;
    foreach ($allDays as $day) {
      $w = $day->format('w');
      if ($w != 0 && $w != 6) $weekdays++;
    }

    $monthlyLeave = array_sum($used);
    $absentCount = max(0, $weekdays - $presentCount - $monthlyLeave);
  }
}
?>

<!-- HTML below remains mostly unchanged -->
<!DOCTYPE html>
<html>
<head>
  <title>NHPC | Master Dashboard</title>
  <style>
    body { font-family: sans-serif; margin: 30px; background: #f2f5fa; color: #333; }
    h1 { color: #003366; }
    select { padding: 6px; font-size: 15px; margin-right: 15px; }
    table { width: 100%; margin-top: 20px; border-collapse: collapse; background: #fff; }
    th, td { padding: 10px; border: 1px solid #ccc; text-align: center; }
    th { background: #003366; color: white; }
    .summary { display: flex; gap: 20px; margin-top: 20px; flex-wrap: wrap; }
    .card { background: white; padding: 15px 25px; border-radius: 6px; box-shadow: 0 3px 8px rgba(0,0,0,0.1); flex: 1; min-width: 200px; }
    .card h3 { margin-bottom: 10px; color: #003366; }
  </style>
</head>
<body>

<h1>NHPC | Master Dashboard</h1>

<form method="get">
  <label><strong>Employee:</strong></label>
  <select name="emp" required onchange="this.form.submit()">
    <option value="">-- Select --</option>
    <?php foreach ($empList as $e): ?>
      <option value="<?= $e['employee_id'] ?>" <?= ($employeeId === $e['employee_id'] ? 'selected' : '') ?>>
        <?= $e['name'] ?> (<?= $e['employee_id'] ?>)
      </option>
    <?php endforeach; ?>
  </select>

  <?php if ($employeeId): ?>
    <label><strong>Month:</strong></label>
    <select name="month" onchange="this.form.submit()">
      <option value="">-- Select Month --</option>
      <?php foreach ($availableMonths as $m): ?>
        <option value="<?= $m ?>" <?= ($m === $selectedMonth ? 'selected' : '') ?>>
          <?= date("F Y", strtotime($m . "-01")) ?>
        </option>
      <?php endforeach; ?>
    </select>
  <?php endif; ?>
</form>

<?php if ($employeeId): ?>
  <div class="summary">
    <?php foreach ($total as $type => $max): 
      $u = $used[$type];
      $r = $max - $u;
      $clr = ($r < 0) ? 'red' : '#003366'; ?>
      <div class="card">
        <h3><?= strtoupper($type) ?> Leave</h3>
        <p>Used: <?= $u ?> / <?= $max ?></p>
        <p style="color: <?= $clr ?>;"><strong>Remaining: <?= max(0, $r) ?></strong></p>
      </div>
    <?php endforeach; ?>

    <?php if ($selectedMonth): ?>
      <div class="card">
        <h3>Attendance</h3>
        <p>✅ Present: <?= $presentCount ?></p>
        <p style="color: red;"><strong>❌ Absent: <?= $absentCount ?></strong></p>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($selectedMonth && count($calendar)): ?>
  <table>
    <tr><th>Date</th><th>Day</th><th>Status</th></tr>
    <?php foreach ($calendar as $row): ?>
      <tr>
        <td><?= $row[0] ?></td>
        <td><?= $row[1] ?></td>
        <td><?= $row[2] ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php elseif ($selectedMonth): ?>
  <p><em>No entries found for <?= date("F Y", strtotime($selectedMonth . "-01")) ?>.</em></p>
<?php endif; ?>

</body>
</html>

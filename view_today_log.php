<?php
$conn = new mysqli("localhost", "root", "Ritik@8685", "nhpc_db");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

date_default_timezone_set("Asia/Kolkata");

$today = date("Y-m-d");
$monthStart = date("Y-m-01");
$monthEnd = date("Y-m-t");

// Attendance log for current month
$monthAttStmt = $conn->prepare("
  SELECT e.name, a.employee_id, a.attendance_date, a.in_time, a.out_time
  FROM attendance_log a
  JOIN employees e ON a.employee_id = e.employee_id
  WHERE a.attendance_date BETWEEN ? AND ?
  ORDER BY a.attendance_date DESC
");
$monthAttStmt->bind_param("ss", $monthStart, $monthEnd);
$monthAttStmt->execute();
$monthAttResult = $monthAttStmt->get_result();

// Leave applications for current month
$monthLeaveStmt = $conn->prepare("
  SELECT e.name, l.employee_id, l.leave_type, l.duration, l.from_date, l.to_date
  FROM leave_applications l
  JOIN employees e ON l.employee_id = e.employee_id
  WHERE l.from_date <= ? AND l.to_date >= ?
  ORDER BY l.from_date DESC
");
$monthLeaveStmt->bind_param("ss", $monthEnd, $monthStart);
$monthLeaveStmt->execute();
$monthLeaveResult = $monthLeaveStmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
  <title>📊 Monthly Log | NHPC</title>
  <style>
    body {
      font-family: 'Segoe UI', sans-serif;
      background: #f4f8fb;
      padding: 40px;
      color: #003366;
    }
    h2 {
      text-align: center;
      margin-bottom: 30px;
    }
    table {
      width: 90%;
      margin: auto;
      border-collapse: collapse;
      margin-bottom: 40px;
    }
    th, td {
      padding: 12px;
      text-align: left;
      border-bottom: 1px solid #ccc;
    }
    th {
      background-color: #003366;
      color: white;
    }
    tr.today {
      background-color: #d1eaff;
      font-weight: bold;
    }
    .back-btn {
      display: block;
      width: fit-content;
      margin: 20px auto;
      padding: 10px 20px;
      background-color: #003366;
      color: white;
      text-decoration: none;
      border-radius: 6px;
    }
  </style>
</head>
<body>

  <h2>📊 Monthly Attendance – <?= date("F Y"); ?></h2>
  <table>
    <tr>
      <th>Name</th>
      <th>Employee ID</th>
      <th>Date</th>
      <th>In Time</th>
      <th>Out Time</th>
    </tr>
    <?php
    if ($monthAttResult->num_rows > 0) {
      while ($row = $monthAttResult->fetch_assoc()) {
        $highlight = ($row['attendance_date'] === $today) ? "class='today'" : "";
        echo "<tr $highlight>
                <td>{$row['name']}</td>
                <td>{$row['employee_id']}</td>
                <td>{$row['attendance_date']}</td>
                <td>{$row['in_time']}</td>
                <td>{$row['out_time']}</td>
              </tr>";
      }
    } else {
      echo "<tr><td colspan='5'>No attendance records found for this month.</td></tr>";
    }
    ?>
  </table>
    <h2>🗓 Monthly Leave Applications – <?= date("F Y"); ?></h2>
  <table>
    <tr>
      <th>Name</th>
      <th>Employee ID</th>
      <th>Leave Type</th>
      <th>Duration</th>
      <th>From Date</th>
      <th>To Date</th>
    </tr>
    <?php
    if ($monthLeaveResult->num_rows > 0) {
      while ($row = $monthLeaveResult->fetch_assoc()) {
        echo "<tr>
                <td>{$row['name']}</td>
                <td>{$row['employee_id']}</td>
                <td>{$row['leave_type']}</td>
                <td>{$row['duration']}</td>
                <td>{$row['from_date']}</td>
                <td>{$row['to_date']}</td>
              </tr>";
      }
    } else {
      echo "<tr><td colspan='6'>No leave applications found for this month.</td></tr>";
    }
    ?>
  </table>

  <?php
  $leaveTypes = [];
  $typeQuery = $conn->query("SELECT DISTINCT leave_type FROM leave_applications");
  while ($row = $typeQuery->fetch_assoc()) {
    $leaveTypes[] = $row['leave_type'];
  }

  $empQuery = $conn->query("SELECT employee_id, name FROM employees");
  ?>
  <h2>📋 Monthly Summary – <?= date("F Y") ?></h2>
  <table style="width: 95%; margin: auto; border-collapse: collapse; margin-bottom: 40px;">
    <tr style="background:#003366; color:white;">
      <th style="padding:10px;">Employee ID</th>
      <th>Name</th>
      <th>Present Days</th>
      <?php foreach ($leaveTypes as $type): ?>
        <th><?= $type ?></th>
      <?php endforeach ?>
    </tr>
      <?php while ($emp = $empQuery->fetch_assoc()): ?>
    <?php
      $eid = $emp['employee_id'];
      $ename = $emp['name'];

      // Present count
      $stmt = $conn->prepare("SELECT COUNT(DISTINCT attendance_date) AS present FROM attendance_log WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?");
      $stmt->bind_param("sss", $eid, $monthStart, $monthEnd);
      $stmt->execute();
      $present = $stmt->get_result()->fetch_assoc()['present'] ?? 0;
      $stmt->close();

      // Leave count per type (weekend-aware)
      $leaveCounts = [];
      foreach ($leaveTypes as $type) {
        $stmt = $conn->prepare("SELECT from_date, to_date FROM leave_applications 
                                WHERE employee_id = ? AND leave_type = ? 
                                AND to_date >= ? AND from_date <= ?");
        $stmt->bind_param("ssss", $eid, $type, $monthStart, $monthEnd);
        $stmt->execute();
        $res = $stmt->get_result();

        $count = 0;
        while ($row = $res->fetch_assoc()) {
          $d1 = new DateTime(max($row['from_date'], $monthStart));
          $d2 = new DateTime(min($row['to_date'], $monthEnd));
          $period = new DatePeriod($d1, new DateInterval('P1D'), $d2->modify('+1 day'));

          foreach ($period as $day) {
            $w = $day->format('w'); // 0=Sun, 6=Sat
            if (strtolower($type) === 'casual leave' && ($w == 0 || $w == 6)) continue;
            $count++;
          }
        }

        $leaveCounts[$type] = $count;
        $stmt->close();
      }
    ?>
    <tr>
      <td style="padding:10px;"><?= $eid ?></td>
      <td><?= $ename ?></td>
      <td><?= $present ?></td>
      <?php foreach ($leaveTypes as $type): ?>
        <td><?= $leaveCounts[$type] ?? 0 ?></td>
      <?php endforeach ?>
    </tr>
  <?php endwhile ?>
</table>

<a href="leave_attendance.html" class="back-btn">← Back to Form</a>

</body>
</html>

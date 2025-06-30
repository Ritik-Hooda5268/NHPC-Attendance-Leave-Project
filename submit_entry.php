<?php
$conn = new mysqli("localhost", "root", "Ritik@8685", "nhpc_db");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
date_default_timezone_set("Asia/Kolkata");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header("Location: leave_attendance.html");
  exit;
}

$entryType = $_POST['entryType'] ?? null;
$employeeId = $_POST['employeeId'] ?? null;

if (!$entryType || !$employeeId) {
  showConfirmation("❌ Invalid submission. Missing entry type or employee ID.");
}

$emp = $conn->prepare("SELECT name FROM employees WHERE employee_id = ?");
$emp->bind_param("s", $employeeId);
$emp->execute();
$result = $emp->get_result();
if ($row = $result->fetch_assoc()) {
  $employeeName = trim($row['name']);
} else {
  $emp->close();
  showConfirmation("❌ Invalid Employee ID.");
}
$emp->close();

if ($entryType === "attendance") {
  $attendanceDate = date("Y-m-d");
  $timeType = $_POST['timeType'] ?? "in";
  $timeNow = date("H:i");

  $check = $conn->prepare("SELECT in_time, out_time FROM attendance_log WHERE employee_id = ? AND attendance_date = ?");
  $check->bind_param("ss", $employeeId, $attendanceDate);
  $check->execute();
  $result = $check->get_result();
  $row = $result->fetch_assoc();
  $check->close();

  if ($row) {
    if (!empty($row['in_time']) && !empty($row['out_time'])) {
      showConfirmation("✅ Attendance already completed for $employeeName on $attendanceDate.");
    }
    if ($timeType === "in" && !empty($row['in_time'])) {
      showConfirmation("⚠️ In Time already recorded. Duplicate not allowed.");
    }
    if ($timeType === "out" && empty($row['in_time'])) {
      showConfirmation("❌ Cannot mark Out Time before In Time.");
    }

    $stmt = $conn->prepare("UPDATE attendance_log SET " . ($timeType === "in" ? "in_time" : "out_time") . "=? WHERE employee_id=? AND attendance_date=?");
    $stmt->bind_param("sss", $timeNow, $employeeId, $attendanceDate);
    $stmt->execute();
    $stmt->close();
    showConfirmation("✅ " . ucfirst($timeType) . " Time updated for $employeeName.");
  } else {
    if ($timeType === "out") {
      showConfirmation("❌ Cannot mark Out Time before In Time.");
    }

    $stmt = $conn->prepare("INSERT INTO attendance_log (employee_id, attendance_date, in_time) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $employeeId, $attendanceDate, $timeNow);
    $stmt->execute();
    $stmt->close();
    showConfirmation("✅ In Time recorded for $employeeName.");
  }
}
elseif ($entryType === "leave") {
  $leaveType = $_POST['leaveType'] ?? '';
  $reason = $_POST['reason'] ?? '';
  $fromDate = $_POST['fromDate'] ?? '';
  $toDate   = $_POST['toDate'] ?? '';

  function validateAndFormatDate($input) {
    $date = DateTime::createFromFormat('Y-m-d', $input);
    if (!$date) showConfirmation("❌ Invalid date format for '$input'.");
    return $date->format('Y-m-d');
  }

  $fromDate = validateAndFormatDate($fromDate);
  $toDate = validateAndFormatDate($toDate);

  $typeMap = [
    'casual leave' => 'casual',
    'medical leave' => 'medical',
    'earned leave' => 'earned',
    'cl' => 'casual',
    'ml' => 'medical',
    'el' => 'earned',
  ];
  $newType = $typeMap[strtolower(trim($leaveType))] ?? strtolower(trim($leaveType));

  if ($newType === 'casual') {
    $leaveDuration = $_POST['leaveDuration'] ?? '';
    $halfDayTime = $_POST['halfDayTime'] ?? null;
  } else {
    $leaveDuration = 'Full Day';
    $halfDayTime = null;
  }

  // ❌ Type conflict check: CL can't mix with ML/EL
  $check = $conn->prepare("SELECT leave_type FROM leave_applications WHERE employee_id = ? AND from_date <= ? AND to_date >= ?");
  $check->bind_param("sss", $employeeId, $toDate, $fromDate);
  $check->execute();
  $res = $check->get_result();

  while ($row = $res->fetch_assoc()) {
    $existing = $typeMap[strtolower(trim($row['leave_type']))] ?? '';
    if (
      ($existing === 'casual' && in_array($newType, ['medical', 'earned'])) ||
      ($newType === 'casual' && in_array($existing, ['medical', 'earned']))
    ) {
      showConfirmation("
        ❌ Leave Conflict Detected.<br><br>
        <strong>{$existing}</strong> leave already exists.<br>
        CL cannot overlap with ML or EL.<br><br>
        <em>Only ML + EL can be combined. CL must be separate.</em>
      ");
    }
  }
  $check->close();
    // Leave quota config & usage tally
  $total = ['casual' => 14, 'medical' => 20, 'earned' => 15];
  $used = ['casual' => 0, 'medical' => 0, 'earned' => 0];

  $year = date('Y', strtotime($fromDate));
  $stmt = $conn->prepare("SELECT leave_type, from_date, to_date FROM leave_applications WHERE employee_id = ? AND YEAR(from_date) = ?");
  $stmt->bind_param("si", $employeeId, $year);
  $stmt->execute();
  $res = $stmt->get_result();

  while ($row = $res->fetch_assoc()) {
    $mapType = strtolower(trim($row['leave_type']));
    $mapped = $typeMap[$mapType] ?? $mapType;
    $d1 = new DateTime($row['from_date']);
    $d2 = new DateTime($row['to_date']);

    if ($mapped === 'casual') {
      $count = 0;
      $period = new DatePeriod($d1, new DateInterval('P1D'), (clone $d2)->modify('+1 day'));
      foreach ($period as $day) {
        $w = $day->format('w'); // 0 = Sunday, 6 = Saturday
        if ($w !== '0' && $w !== '6') {
          $count++;
        }
      }
      $used['casual'] += $count;
    } elseif (isset($used[$mapped])) {
      $used[$mapped] += $d1->diff($d2)->days + 1;
    }
  }
  $stmt->close();

  // 👇 Enforce leave quota based on normalized type
  $lookup = ['cl' => 'casual', 'ml' => 'medical', 'el' => 'earned'];
  $cleanType = $lookup[$newType] ?? $newType;

  // Count applied leave days excluding weekends if CL
  $leaveDays = 0;
  $from = new DateTime($fromDate);
  $to = new DateTime($toDate);
  $range = new DatePeriod($from, new DateInterval('P1D'), $to->modify('+1 day'));

  foreach ($range as $day) {
    $dayOfWeek = $day->format('w');
    if ($cleanType === 'casual' && ($dayOfWeek == 0 || $dayOfWeek == 6)) continue;
    $leaveDays++;
  }

  if (isset($total[$cleanType]) && ($used[$cleanType] + $leaveDays) > $total[$cleanType]) {
    showConfirmation("❌ You have only " . ($total[$cleanType] - $used[$cleanType]) . " " . strtoupper($cleanType) . " leaves left. This application requires $leaveDays.");
  }

  // Overlap detection
  $conflict = $conn->prepare("SELECT COUNT(*) as cnt FROM leave_applications WHERE employee_id = ? AND NOT (to_date < ? OR from_date > ?)");
  $conflict->bind_param("sss", $employeeId, $fromDate, $toDate);
  $conflict->execute();
  $res = $conflict->get_result();
  $row = $res->fetch_assoc();
  $conflict->close();

  if ($row['cnt'] > 0) {
    showConfirmation("⚠️ You already have a leave during this period. Overlapping leave not allowed.");
  }

  // ✅ Final insert
  $stmt = $conn->prepare("INSERT INTO leave_applications 
    (employee_id, leave_type, duration, half_day_time, from_date, to_date, reason) 
    VALUES (?, ?, ?, ?, ?, ?, ?)");
  $stmt->bind_param("sssssss", $employeeId, $leaveType, $leaveDuration, $halfDayTime, $fromDate, $toDate, $reason);
  $stmt->execute();
  $stmt->close();

 showConfirmation("📝 Leave application submitted for $employeeName.");
}
else {
  showConfirmation("❌ Invalid entry type.");
}

$conn->close();
function generateLeavePolicyTable($fromDate, $toDate, $leaveType) {
  $start = new DateTime($fromDate);
  $end = new DateTime($toDate);
  $end->modify('+1 day');
  $interval = new DateInterval('P1D');
  $period = new DatePeriod($start, $interval, $end);

  $type = strtolower(trim($leaveType));
  if ($type === 'cl' || $type === 'casual leave') $type = 'CL';
  elseif ($type === 'ml' || $type === 'medical leave') $type = 'ML';
  else $type = 'EL';

  $html = "<br><table border='1' cellpadding='6' style='margin:20px auto;border-collapse:collapse;'>";
  $html .= "<tr style='background:#003366;color:white;'><th>Date</th><th>Day</th><th>Status</th><th>Policy Counted</th></tr>";

  foreach ($period as $date) {
    $dow = $date->format('l');
    $d = $date->format('Y-m-d');
    $dayOfWeek = $date->format('w');
    $isWeekend = ($dayOfWeek == 0 || $dayOfWeek == 6);

    $status = $type;
    $counted = "✅ Counted";

    if ($type === 'CL' && $isWeekend) {
      $status = "Holiday";
      $counted = "❌ Not Counted";
    } elseif (($type === 'ML' || $type === 'EL') && $isWeekend) {
      $status = "Absent";
      $counted = "✅ Counted";
    }

    $html .= "<tr><td>$d</td><td>$dow</td><td>$status</td><td>$counted</td></tr>";
  }

  $html .= "</table>";
  return $html;
}

function showConfirmation($message) {
  echo "<!DOCTYPE html>
  <html>
  <head>
    <title>Status</title>
    <style>
      body {
        font-family: 'Segoe UI', sans-serif;
        background: #003366;
        color: #fff;
        text-align: center;
        padding: 80px 20px;
      }
      .box {
        background: #fff;
        color: #003366;
        padding: 40px;
        border-radius: 10px;
        display: inline-block;
        box-shadow: 0 6px 20px rgba(0,0,0,0.25);
        max-width: 600px;
      }
      h2 { margin-bottom: 10px; }
      p { font-size: 16px; }
      .btn-group {
        margin-top: 20px;
        display: flex;
        justify-content: center;
        gap: 15px;
        flex-wrap: wrap;
      }
      a {
        padding: 10px 20px;
        background: #1a75ff;
        color: white;
        text-decoration: none;
        border-radius: 6px;
        font-weight: 500;
        transition: background 0.3s;
      }
      a:hover {
        background: #004dcc;
      }
    </style>
  </head>
  <body>
    <div class='box'>
      <h2>Form Submitted</h2>
      <p>{$message}</p>
      <div class='btn-group'>
        <a href='leave_attendance.html'>← Back to Form</a>
        <a href='view_today_log.php'>📋 View Monthly Log</a>
      </div>
    </div>
  </body>
  </html>";
  exit;
}

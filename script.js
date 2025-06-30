document.addEventListener("DOMContentLoaded", () => {
  const entryRadios = document.getElementsByName("entryType");
  const attendanceSection = document.getElementById("attendanceSection");
  const leaveSection = document.getElementById("leaveSection");
  const attendanceDate = document.getElementById("attendanceDate");
  const leaveDuration = document.getElementById("leaveDuration");
  const halfDayTimeContainer = document.getElementById("halfDayTimeContainer");
  const halfDayTime = document.getElementById("halfDayTime");
  const timeValue = document.getElementById("timeValue");
  const employeeIdInput = document.getElementById("employeeId");
  const employeeNameInput = document.getElementById("employeeName");

  function toggleForm() {
    const selected = [...entryRadios].find(r => r.checked).value;
    attendanceSection.style.display = selected === "attendance" ? "block" : "none";
    leaveSection.style.display = selected === "leave" ? "block" : "none";

    leaveSection.querySelectorAll("input, select, textarea").forEach(field => {
      field.disabled = selected !== "leave";
    });

    attendanceSection.querySelectorAll("input, select").forEach(field => {
      field.disabled = selected !== "attendance";
    });

    attendanceDate.readOnly = selected === "attendance";
  }

  function updateLiveTime() {
    const now = new Date();
    const hh = String(now.getHours()).padStart(2, '0');
    const mm = String(now.getMinutes()).padStart(2, '0');
    if (timeValue) timeValue.value = `${hh}:${mm}`;
  }

  function handleHalfDayLogic() {
    const val = leaveDuration.value;
    if (val.includes("Half Day")) {
      halfDayTimeContainer.style.display = "block";
      if (val.includes("First Half")) {
        halfDayTime.min = "09:00";
        halfDayTime.max = "13:00";
      } else if (val.includes("Second Half")) {
        halfDayTime.min = "13:00";
        halfDayTime.max = "17:00";
      }
    } else {
      halfDayTimeContainer.style.display = "none";
      halfDayTime.value = "";
    }
  }

  function autoSelectDuration() {
    const time = halfDayTime.value;
    if (!time) return;
    leaveDuration.value = time < "13:00" ? "Half Day - First Half" : "Half Day - Second Half";
    handleHalfDayLogic();
  }

  entryRadios.forEach(r => r.addEventListener("change", toggleForm));
  leaveDuration.addEventListener("change", handleHalfDayLogic);
  halfDayTime.addEventListener("change", autoSelectDuration);

  // ✅ Employee Name Autofill on every input (debounced)
  if (employeeIdInput && employeeNameInput) {
    let fetchTimeout;
    employeeIdInput.addEventListener("input", () => {
      clearTimeout(fetchTimeout);
      const empId = employeeIdInput.value.trim();

      if (!empId) {
        employeeNameInput.value = "";
        return;
      }

      fetchTimeout = setTimeout(() => {
        fetch("get_employee_name.php?id=" + encodeURIComponent(empId))
          .then(res => res.text())
          .then(text => {
            try {
              const data = JSON.parse(text.trim());
              console.log("✅ Live Response:", data);
              if (data && data.name) {
                employeeNameInput.value = data.name.trim();
              } else {
                employeeNameInput.value = "Not Found";
              }
            } catch (e) {
              console.error("❌ JSON parse error:", e, "\nRaw response:", text);
              employeeNameInput.value = "Error";
            }
          })
          .catch(err => {
            console.error("❌ Fetch error:", err);
            employeeNameInput.value = "Error";
          });
      }, 300); // ⏳ Debounce delay
    });
  }

  attendanceDate.value = new Date().toISOString().split("T")[0];
  attendanceDate.readOnly = true;

  updateLiveTime();
  setInterval(updateLiveTime, 1000);
  toggleForm();
});

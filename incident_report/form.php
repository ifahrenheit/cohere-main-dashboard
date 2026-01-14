<?php
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_email'])) {
    // Save the current URL to redirect back after login
    $currentUrl = $_SERVER['REQUEST_URI'];
    header("Location: ../login.php?redirect=" . urlencode($currentUrl));
    exit();
}

$conn = getDBConnection();

// Get active employees for dropdown (only Active status) - with collation fix
$employees = [];
$sql = "SELECT DISTINCT e.EmployeeID, e.FirstName, e.LastName 
        FROM Employees e
        INNER JOIN gsheet_active_employees g ON e.EmployeeID COLLATE utf8mb4_unicode_ci = g.employee_id
        WHERE g.status = 'Active'
        ORDER BY e.FirstName, e.LastName";

$result = $conn->query($sql);

if (!$result) {
    die("SQL Error: " . $conn->error);
}

while ($row = $result->fetch_assoc()) {
    $employees[] = $row;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Incident Report Form</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    `    <style>
        body {
            background: linear-gradient(135deg, #0f2557 0%, #1e3a8a 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .form-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .form-container h2 {
            color: #0f2557;
            border-bottom: 3px solid #ff6b35;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        .preview-container {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .preview-item {
            position: relative;
            width: 150px;
            height: 150px;
            border: 2px solid #0f2557;
            border-radius: 8px;
            overflow: hidden;
        }
        .preview-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .preview-item .remove-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            background: #ff6b35;
            color: white;
            border: none;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
        }
        .preview-item .remove-btn:hover {
            background: #e55a2b;
        }
        .file-info {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(15, 37, 87, 0.9);
            color: white;
            padding: 5px;
            font-size: 11px;
            text-align: center;
        }
        .btn-submit {
            background: linear-gradient(135deg, #0f2557 0%, #ff6b35 100%);
            border: none;
            padding: 12px 40px;
            font-size: 16px;
            font-weight: bold;
            color: white;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 107, 53, 0.4);
        }
        .required-field::after {
            content: " *";
            color: #ff6b35;
        }
        .form-label {
            color: #0f2557;
            font-weight: 600;
        }
        .form-control:focus, .form-select:focus {
            border-color: #ff6b35;
            box-shadow: 0 0 0 0.2rem rgba(255, 107, 53, 0.25);
        }
    </style>
</head>
<body>
    <div class="form-container">
        <h2 class="text-center mb-4">📋 Incident Report Form</h2>
        
        <form id="incidentForm" enctype="multipart/form-data">
            <!-- Date of Incident -->
            <div class="mb-3">
                <label for="incident_date" class="form-label required-field">Date of Incident</label>
                <input type="date" class="form-control" id="incident_date" name="incident_date" required max="<?= date('Y-m-d') ?>">
            </div>

            <!-- Agent Involved -->
            <div class="mb-3">
                <label for="employee_id" class="form-label required-field">Agent Involved</label>
                <select class="form-select" id="employee_id" name="employee_id" required>
                    <option value="">Select Agent</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= htmlspecialchars($emp['EmployeeID']) ?>">
                            <?= htmlspecialchars($emp['FirstName'] . ' ' . $emp['LastName']) ?> (<?= htmlspecialchars($emp['EmployeeID']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Select the agent who was involved in this incident</small>
            </div>

            <!-- Summary -->
            <div class="mb-3">
                <label for="summary" class="form-label required-field">Summary</label>
                <textarea class="form-control" id="summary" name="summary" rows="5" required placeholder="Describe the incident in detail..."></textarea>
            </div>

            <!-- HR Escalation Checkbox - ADD THIS -->
            <div class="mb-4">
                <div class="form-check" style="background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ff6b35;">
                    <input class="form-check-input" type="checkbox" id="escalate_to_hr" name="escalate_to_hr" value="1">
                    <label class="form-check-label" for="escalate_to_hr" style="font-weight: 600; color: #0f2557;">
                        📋 Escalate to HR immediately (Written Explanation Required)
                    </label>
                    <div class="form-text" style="margin-left: 24px;">
                        Check this box if this incident requires immediate HR attention and written explanation from the agent.
                    </div>
                </div>
            </div>

            <!-- Attachments -->
            <div class="mb-3">
                <label for="attachments" class="form-label">Attachments (up to 4 images)</label>
                <input type="file" class="form-control" id="attachments" name="attachments[]" multiple accept="image/*">
                <small class="text-muted">Images will be automatically compressed to save space. Max 4 images.</small>
                <div id="previewContainer" class="preview-container"></div>
            </div>

            <!-- Submit Button -->
            <div class="text-center mt-4">
                <button type="submit" class="btn btn-primary btn-submit" id="submitBtn">
                    <span id="submitText">Submit Report</span>
                    <span id="submitSpinner" class="spinner-border spinner-border-sm d-none" role="status"></span>
                </button>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let compressedFiles = [];
        const maxFiles = 4;

        // File input change handler
        document.getElementById('attachments').addEventListener('change', async function(e) {
            const files = Array.from(e.target.files);
            
            if (files.length + compressedFiles.length > maxFiles) {
                alert(`You can only upload up to ${maxFiles} images`);
                e.target.value = '';
                return;
            }

            for (let file of files) {
                if (!file.type.startsWith('image/')) {
                    alert('Only image files are allowed');
                    continue;
                }

                // Compress the image
                const compressed = await compressImage(file);
                compressedFiles.push(compressed);
            }

            updatePreview();
            e.target.value = ''; // Clear input
        });

        // Image compression function
        async function compressImage(file) {
            return new Promise((resolve) => {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    const img = new Image();
                    img.onload = function() {
                        const canvas = document.createElement('canvas');
                        let width = img.width;
                        let height = img.height;
                        
                        // Resize if image is too large
                        const maxDimension = 1920;
                        if (width > maxDimension || height > maxDimension) {
                            if (width > height) {
                                height = (height / width) * maxDimension;
                                width = maxDimension;
                            } else {
                                width = (width / height) * maxDimension;
                                height = maxDimension;
                            }
                        }
                        
                        canvas.width = width;
                        canvas.height = height;
                        
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(img, 0, 0, width, height);
                        
                        // Convert to blob with compression
                        canvas.toBlob(function(blob) {
                            const compressedFile = new File([blob], file.name, {
                                type: 'image/jpeg',
                                lastModified: Date.now()
                            });
                            
                            resolve({
                                file: compressedFile,
                                originalSize: file.size,
                                compressedSize: compressedFile.size,
                                preview: canvas.toDataURL('image/jpeg', 0.8)
                            });
                        }, 'image/jpeg', 0.8);
                    };
                    img.src = e.target.result;
                };
                
                reader.readAsDataURL(file);
            });
        }

        // Update preview
        function updatePreview() {
            const container = document.getElementById('previewContainer');
            container.innerHTML = '';
            
            compressedFiles.forEach((item, index) => {
                const div = document.createElement('div');
                div.className = 'preview-item';
                
                const img = document.createElement('img');
                img.src = item.preview;
                
                const removeBtn = document.createElement('button');
                removeBtn.className = 'remove-btn';
                removeBtn.innerHTML = '×';
                removeBtn.onclick = () => removeImage(index);
                
                const info = document.createElement('div');
                info.className = 'file-info';
                info.textContent = `${(item.compressedSize / 1024).toFixed(0)}KB (${((1 - item.compressedSize / item.originalSize) * 100).toFixed(0)}% saved)`;
                
                div.appendChild(img);
                div.appendChild(removeBtn);
                div.appendChild(info);
                container.appendChild(div);
            });
        }

        // Remove image from array
        function removeImage(index) {
            compressedFiles.splice(index, 1);
            updatePreview();
        }

        // Form submission
        document.getElementById('incidentForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const submitBtn = document.getElementById('submitBtn');
            const submitText = document.getElementById('submitText');
            const submitSpinner = document.getElementById('submitSpinner');
            
            // Disable button and show spinner
            submitBtn.disabled = true;
            submitText.classList.add('d-none');
            submitSpinner.classList.remove('d-none');
            
            const formData = new FormData();
            formData.append('incident_date', document.getElementById('incident_date').value);
            formData.append('employee_id', document.getElementById('employee_id').value);
            formData.append('summary', document.getElementById('summary').value);

            // Add HR escalation checkbox value
            const escalateCheckbox = document.getElementById('escalate_to_hr');
            if (escalateCheckbox.checked) {
                formData.append('escalate_to_hr', '1');
            }

            // Add compressed images
            compressedFiles.forEach((item, index) => {
                formData.append('attachments[]', item.file);
            });
            
            try {
                const response = await fetch('submit.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('✅ Incident report submitted successfully!\nReport Number: ' + result.report_number);
                    document.getElementById('incidentForm').reset();
                    compressedFiles = [];
                    updatePreview();
                } else {
                    alert('❌ Error: ' + result.message);
                }
            } catch (error) {
                alert('❌ Error submitting report: ' + error.message);
            } finally {
                // Re-enable button
                submitBtn.disabled = false;
                submitText.classList.remove('d-none');
                submitSpinner.classList.add('d-none');
            }
        });
    </script>
</body>
</html>
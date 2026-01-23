<!DOCTYPE html>
<html>
<head>
    <title>Live Calls</title>
</head>
<body>
<style>
    body {
        font-family: "Segoe UI", Arial, sans-serif;
        background: #f1f3f6;
        padding: 20px;
    }

    h2 {
        margin-bottom: 15px;
        color: #222;
    }

    .table-wrapper {
        background: #fff;
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 8px 20px rgba(0,0,0,0.08);
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    thead {
        background: linear-gradient(90deg, #1f2937, #111827);
        color: #fff;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    th, td {
        padding: 12px 14px;
        text-align: center;
        font-size: 14px;
    }

    th {
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-size: 13px;
    }

    tbody tr {
        border-bottom: 1px solid #eee;
        transition: background 0.2s ease;
    }

    tbody tr:hover {
        background: #f9fafb;
    }

    /* STATUS BADGES */
    .status {
        padding: 6px 12px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 12px;
        display: inline-block;
        min-width: 90px;
    }

    .answered {
        background: #e6f9f0;
        color: #059669;
        border: 1px solid #34d399;
    }

    .ringing {
        background: #fff4e5;
        color: #d97706;
        border: 1px solid #fbbf24;
        animation: pulse 1.2s infinite;
    }

    @keyframes pulse {
        0% { box-shadow: 0 0 0 0 rgba(251,191,36,0.6); }
        70% { box-shadow: 0 0 0 8px rgba(251,191,36,0); }
        100% { box-shadow: 0 0 0 0 rgba(251,191,36,0); }
    }

    .agent {
        font-weight: 600;
        color: #111827;
    }

    .number {
        font-family: monospace;
        font-size: 13px;
    }

    .time {
        font-family: "Courier New", monospace;
        font-weight: bold;
        color: #1f2937;
    }
</style>

<h2>📞 Live Calls CRM</h2>

<div class="table-wrapper">
    <table id="callTable">
        <thead>
            <tr>
                <th>Agent</th>
                <th>Customer</th>
                <th>Status</th>
                <th>Call Time</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>


<script>
function loadCalls() {
    fetch('calls.php')
        .then(res => res.json())
        .then(data => {
            let html = '';

            if (!Array.isArray(data)) return;

            data.forEach(call => {
                let statusClass = call.state === 'Answered' ? 'answered' : 'ringing';

                html += `
                    <tr>
                        <td class="agent">${call.agent_name ?? '-'}</td>
                        <td class="number">${call.customer_number ?? '-'}</td>
                        <td>
                            <span class="status ${statusClass}">
                                ${call.state ?? '-'}
                            </span>
                        </td>
                        <td class="time">${call.call_time ?? '00:00:00'}</td>
                    </tr>
                `;
            });

            document.querySelector('#callTable tbody').innerHTML = html;
        });
}

loadCalls();
setInterval(loadCalls, 1000); // safer than 1 sec
</script>


</body>
</html>

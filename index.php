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
        padding: 5px 20px; /* Reduced top padding */
    }

    h2 {
        margin-bottom: 10px;
        color: #222;
        font-size: 1.2rem; /* Compact header */
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

    .offline {
        background: #e03d2431;
        color: #9f1b14ff;
        border: 1px solid #9f1b14ff;
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

    .filter-wrapper {
        margin-bottom: 20px;
        display: flex;
        gap: 10px;
        justify-content: flex-end;
    }

    .filter-btn {
        padding: 8px 16px;
        border: 1px solid #e5e7eb;
        background: #fff;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 500;
        color: #4b5563;
        cursor: pointer;
        transition: all 0.2s;
    }

    .filter-btn:hover {
        background: #f9fafb;
        border-color: #d1d5db;
    }

    .filter-btn.active {
        background: #111827;
        color: #fff;
        border-color: #111827;
    }

    .count-badge {
        background: #e5e7eb;
        color: #1f2937;
        padding: 2px 6px;
        border-radius: 10px;
        font-size: 11px;
        font-weight: 700;
        margin-left: 5px;
        vertical-align: middle;
    }
    
    .filter-btn.active .count-badge {
        background: #374151;
        color: #fff;
    }
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
    <h2 style="margin: 0;">📞 Live Calls CRM</h2>
    <!-- Filter Tabs -->
    <div class="filter-wrapper" style="margin: 0;">
        <button id="btn-all" class="filter-btn active" onclick="setFilter('All', this)">All</button>
        <button id="btn-answered" class="filter-btn" onclick="setFilter('Answered', this)">Answered</button>
        <button id="btn-ringing" class="filter-btn" onclick="setFilter('Ringing', this)">Ringing</button>
        <button id="btn-not-on-call" class="filter-btn" onclick="setFilter('Not on call', this)">Not on call</button>
    </div>
</div>

<div class="table-wrapper">
    <table id="callTable">
        <thead>
            <tr>
                <th>S.No</th>
                <th>Agent</th>
                <!-- <th>Customer</th> removed -->
                <th>Status</th>
                <th>Call Time</th>
                <th>Today Total</th>
                <th>Idle Total</th>
                <!-- <th>Yesterday Total</th> -->
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>


<script>
let currentFilter = 'All';

function setFilter(status, btn) {
    currentFilter = status;
    
    // Update UI active state
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    // Reload immediately
    loadCalls();
}

function loadCalls() {
    fetch('calls.php')
        .then(res => res.json())
        .then(data => {
            let html = '';

            if (!Array.isArray(data) || data.length === 0) return;

            // Filter out empty names
            data = data.filter(c => c.agent_name && c.agent_name.trim() !== '');

            // Calculate counts
            let counts = { 'All': data.length, 'Answered': 0, 'Ringing': 0, 'Not on call': 0 };
            data.forEach(c => {
                if (c.state === 'Answered') counts['Answered']++;
                else if (c.state === 'Ringing') counts['Ringing']++;
                else counts['Not on call']++;
            });

            // Update filter buttons
            // document.getElementById('btn-all').innerHTML = `All <span class="count-badge">${counts['All']}</span>`;
            document.getElementById('btn-answered').innerHTML = `Answered <span class="count-badge">${counts['Answered']}</span>`;
            document.getElementById('btn-ringing').innerHTML = `Ringing <span class="count-badge">${counts['Ringing']}</span>`;
            document.getElementById('btn-not-on-call').innerHTML = `Not on call <span class="count-badge">${counts['Not on call']}</span>`;

            let index = 0; // for serial number
            data.forEach(call => {
                // FILTER LOGIC
                if (currentFilter !== 'All' && call.state !== currentFilter) return;

                index++; // Increment serial number only for visible rows

                let statusClass = '';
                if (call.state === 'Answered') statusClass = 'answered';
                else if (call.state === 'Ringing') statusClass = 'ringing';
                else statusClass = 'offline'; // For "Not on call"

                let sourceLabel = '';
                if(String(call.user_id) === '206316') sourceLabel = '<span style="font-size:10px; background:#e0f2fe; color:#0369a1; padding:2px 6px; border-radius:4px; margin-left:8px;">Tata Tele </span>';
                else if(String(call.user_id) === '533950') sourceLabel = '<span style="font-size:10px; background:#f0fdf4; color:#15803d; padding:2px 6px; border-radius:4px; margin-left:8px;">Tata Tele 2</span>';

                html += `
                    <tr>
                        <td>${index}</td>
                        <td class="agent">
                            <div>${call.agent_name ?? '-'}${sourceLabel}</div>
                            <!-- <div class="number" style="font-size: 11px; color: #6b7280; margin-top: 2px;">
                                 ${String(call.customer_number ?? '-').replace(/.(?=.{2})/g, '*')}
                            </div> -->
                        </td>
                        <!-- <td class="number">...</td> removed -->
                        <td>
                            <span class="status ${statusClass}">
                                ${call.state ?? '-'}
                            </span>
                        </td>
                        <td class="time">${call.call_time ?? '00:00:00'}</td>
                        <td class="time" style="color: #059669;">${call.today_total ?? '00:00:00'}</td>
                        <td class="time" style="color: #d97706;">${call.today_idle_total ?? '00:00:00'}</td>
                    </tr>
                `;
            });

            document.querySelector('#callTable tbody').innerHTML = html;
        });
}

loadCalls();
setInterval(loadCalls, 2000); // safer than 1 sec
</script>
                        <!-- <td class="time" style="color: #6b7280;">${call.yesterday_total ?? '00:00:00'}</td> -->


</body>
</html>

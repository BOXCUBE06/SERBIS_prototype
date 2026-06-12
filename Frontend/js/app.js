(function() {
  'use strict';

  var logoData = '/9j/4AAQSkZJRgABAQAAAQABAAD/4gHYSUNDX1BST0ZJTEUAAQEAAAHIAAAAAAQwAABtbnRyUkdCIFhZWiAH4AABAAEAAAAAAABhY3NwAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAQAA9tYAAQAAAADTLQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAlkZXNjAAAA8AAAACRyWFlaAAABFAAAABRnWFlaAAABKAAAABRiWFlaAAABPAAAABR3dHB0AAABUAAAABRyVFJDAAABZAAAAChnVFJDAAABZAAAAChiVFJDAAABZAAAAChjcHJ0AAABjAAAADxtbHVjAAAAAAAAAAEAAAAMZW5VUwAAAAgAAAAcAHMAUgBHAEJYWVogAAAAAAAAb6IAADj1AAADkFhZWiAAAAAAAABimQAAt4UAABjaWFlaIAAAAAAAACSgAAAPhAAAts9YWVogAAAAAAAA9tYAAQAAAADTLXBhcmEAAAAAAAQAAAACZmYAAPKnAAANWQAAE9AAAApbAAAAAAAAAABtbHVjAAAAAAAAAAEAAAAMZW5VUwAAACAAAAAcAEcAbwBvAGcAbABlACAASQBuAGMALgAgADIAMAAxADb/2wBDAAUDBAQEAwUEBAQFBQUGBwwIBwcHBw8LCwkMEQ8SEhEPERETFhwXExQaFRERGCEYGh0dHx8fExciJCIeJBweHx7/2wBDAQUFBQcGBw4ICA4eFBEUHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh7/wAARCAR6BDcDASIAAhEBAxEB/8QAHQABAAICAwEBAAAAAAAAAAAAAAEIBgcCBQkEA//EAGcQAAEDAwIDBAQGDAcMBQoDCQEAAgMEBREGIQcxQQgSUWETcYGRFBUYIqGxIzI3UlVWlLLB0dLUFhdCYnR18CQzNTZTcnOCkpOz4Qk0Q2OiJSYnOUVGVGSD8ShEZaO0woQZKZWk0//EABwBAQADAQEBAQEAAAAAAAAAAAADBAUBAgYHCP/EADkRAQACAgEDAgUDAgYCAQMFAAABAgMRBBIhMRRBBRMiMlEGM2EjcUJSkaGxwQeBFiTR8BU0Q2KC/9oADAMBAAIRAxEAPwCmSIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICIiAiIgIiICL/8AyKuFn4f1n+WU37unyKuFn4f1n+WU37urMogrN8irhZ+H9Z/llN+7p8irhZ+H9Z/llN+7qzKIKzfIq4Wfh/Wf5ZTfu6fIq4Wfh/Wf5ZTfu6syiC';

  // App data initialized empty for backend integration
  var requests = [];
  var users = [];
  var brgyRequests = {};
  var files = [];

  var newAvatarDataUrl = null;
  var currentDetailId = null;

  // Set logos
  function setLogos() {
    var src = 'data:image/png;base64,' + logoData;
    var el1 = document.getElementById('loginLogo');
    var el2 = document.getElementById('sidebarLogo');
    if (el1) el1.src = src;
    if (el2) el2.src = src;
  }

  // SHOW/HIDE SCREEN
  function showScreen(id) {
    document.querySelectorAll('.screen').forEach(function(s) { s.classList.remove('active'); });
    var el = document.getElementById(id);
    if (el) el.classList.add('active');
  }

  // LOGIN
  function doLogin() {
    var e = document.getElementById('emailInp').value.trim();
    var p = document.getElementById('passInp').value.trim();
    var err = document.getElementById('loginErr');
    if (!e || !p) {
      err.style.display = 'block';
      err.textContent = 'Please enter email and password.';
      return;
    }
    err.style.display = 'none';
    showScreen('appScreen');
    showPage('dashboard');
  }

  function doLogout() {
    document.getElementById('emailInp').value = '';
    document.getElementById('passInp').value = '';
    document.getElementById('loginErr').style.display = 'none';
    showScreen('loginScreen');
  }

  // NAV
  window.showPage = function showPage(id) {
    document.querySelectorAll('.page').forEach(function(p) { p.classList.remove('active'); });
    document.querySelectorAll('.sb-nav a').forEach(function(a) { a.classList.remove('active'); });
    var page = document.getElementById('page-' + id);
    var nav = document.getElementById('nav-' + id);
    if (page) page.classList.add('active');
    if (nav) nav.classList.add('active');
    if (id === 'dashboard') renderDashboard();
    if (id === 'requests') renderRequests();
    if (id === 'users') renderUsers(users);
    if (id === 'files') renderFiles(files);
  }

  // DASHBOARD
  function renderDashboard() {
    var dateEl = document.getElementById('dashDate');
    if (dateEl) dateEl.textContent = new Date().toLocaleDateString('en-PH',{year:'numeric',month:'long',day:'numeric'});

    var barangays = Object.keys(brgyRequests);
    var brgyAll = [];
    barangays.forEach(function(b){
      (brgyRequests[b] || []).forEach(function(r){
        brgyAll.push({barangay:b, requestor:r.requestor, service:r.service, date:r.date, status:r.status});
      });
    });

    var pending   = brgyAll.filter(function(r){ return r.status==='pending'; }).length;
    var completed = brgyAll.filter(function(r){ return r.status==='approved'; }).length;
    var denied    = brgyAll.filter(function(r){ return r.status==='cancelled'; }).length;
    var total     = brgyAll.length || 1;

    var sp=document.getElementById('statPending');   if(sp) sp.textContent=pending;
    var sc=document.getElementById('statCompleted'); if(sc) sc.textContent=completed;
    var sx=document.getElementById('statCancelled'); if(sx) sx.textContent=denied;
    var uniqueRequestors = brgyAll.reduce(function(acc,r){ if(acc.indexOf(r.requestor)===-1) acc.push(r.requestor); return acc; },[]);
    var su=document.getElementById('statUsers'); if(su) su.textContent=uniqueRequestors.length;

    function setBar(barId, pctId, val) {
      var pct = Math.round(val/total*100);
      var b=document.getElementById(barId); if(b) b.style.width=pct+'%';
      var p=document.getElementById(pctId); if(p) p.textContent=pct+'% of total';
    }
    setBar('barPending','pctPending',pending);
    setBar('barCompleted','pctCompleted',completed);
    setBar('barCancelled','pctCancelled',denied);

    var cats = [
      {label:'Ambulance Assistance', color:'#1b5e38'},
      {label:'Rescue Operation',     color:'#2980b9'},
      {label:'Fire Assistance',      color:'#e74c3c'},
      {label:'Flood Relief',         color:'#f39c12'},
      {label:'Other',                color:'#95a5a6'},
    ];
    var counts = {};
    cats.forEach(function(c){ counts[c.label]=0; });
    brgyAll.forEach(function(r){
      if(counts.hasOwnProperty(r.service)) counts[r.service]++;
      else counts['Other']++;
    });
    var totalAll = brgyAll.length;
    document.getElementById('donutTotal').textContent = totalAll;

    var cv=document.getElementById('donutChart');
    if(cv && cv.getContext){
      var ctx=cv.getContext('2d');
      ctx.clearRect(0,0,150,150);
      var cx=75,cy=75,outerR=68,innerR=42,sa=-Math.PI/2;
      cats.forEach(function(c){
        var cnt=counts[c.label]||0;
        if(!cnt) return;
        var slice=cnt/(totalAll || 1)*2*Math.PI;
        ctx.beginPath(); ctx.moveTo(cx,cy);
        ctx.arc(cx,cy,outerR,sa,sa+slice); ctx.closePath();
        ctx.fillStyle=c.color; ctx.fill();
        sa+=slice;
      });
      ctx.beginPath(); ctx.arc(cx,cy,innerR,0,2*Math.PI);
      ctx.fillStyle='#fff'; ctx.fill();
    }

    var legEl=document.getElementById('donutLegend');
    if(legEl){
      var lh='';
      cats.forEach(function(c){
        var cnt=counts[c.label]||0;
        var pct=totalAll>0?Math.round(cnt/totalAll*100):0;
        lh+='<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:9px;">';
        lh+='<div style="display:flex;align-items:center;gap:7px;">';
        lh+='<div style="width:10px;height:10px;border-radius:50%;background:'+c.color+';flex-shrink:0;"></div>';
        lh+='<span style="font-size:11px;color:#555;">'+c.label+'</span>';
        lh+='</div>';
        lh+='<span style="font-size:11px;font-weight:700;color:#1a1a1a;">'+pct+'% <span style="color:#aaa;font-weight:400;">('+cnt+')</span></span>';
        lh+='</div>';
      });
      legEl.innerHTML=lh;
    }

    var barCounts=barangays.map(function(b){ return brgyRequests[b].length; });
    var maxBar=Math.max.apply(null,barCounts)||1;
    var barColors=['#1b5e38','#2980b9','#e07b00'];
    var wrap=document.getElementById('barChartWrap');
    var bc2=document.getElementById('barChart');
    if(bc2 && wrap){
      var W=wrap.clientWidth||320;
      bc2.width=W; bc2.height=160;
      var bctx=bc2.getContext('2d');
      bctx.clearRect(0,0,W,160);
      var barW=Math.min(60,Math.floor((W-60)/barangays.length*0.6));
      var totalBarW=barangays.length*barW;
      var totalGap=W-totalBarW;
      var gap=totalGap/(barangays.length+1);
      var chartH=110,topPad=18;
      for(var g=0;g<=4;g++){
        var gy=topPad+chartH-chartH*g/4;
        bctx.beginPath(); bctx.strokeStyle='#f0f0f0'; bctx.lineWidth=1;
        bctx.moveTo(0,gy); bctx.lineTo(W,gy); bctx.stroke();
      }
      barangays.forEach(function(b,idx){
        var x=gap+idx*(barW+gap);
        var h=Math.max(4,(barCounts[idx]/maxBar)*chartH);
        var y=topPad+chartH-h;
        bctx.beginPath();
        if(bctx.roundRect){ bctx.roundRect(x,y,barW,h,6); } else { bctx.rect(x,y,barW,h); }
        bctx.fillStyle=barColors[idx]; bctx.fill();
        bctx.fillStyle='#1a1a1a'; bctx.font='bold 13px Inter,sans-serif'; bctx.textAlign='center';
        bctx.fillText(barCounts[idx],x+barW/2,y-6);
        bctx.fillStyle='#666'; bctx.font='11px Inter,sans-serif';
        bctx.fillText(b,x+barW/2,topPad+chartH+16);
      });
    }

    var allRecs = brgyAll;
    var rcEl=document.getElementById('brgyRecordCount'); if(rcEl) rcEl.textContent=allRecs.length+' records';
    var tbody=document.getElementById('brgyTableBody');
    if(tbody){
      var th='';
      allRecs.forEach(function(r){
        var badge=r.status==='approved'
          ?'<span style="background:#d4edda;color:#155724;padding:3px 10px;border-radius:10px;font-size:10px;font-weight:700;">Completed</span>'
          :r.status==='pending'
          ?'<span style="background:#fff3cd;color:#856404;padding:3px 10px;border-radius:10px;font-size:10px;font-weight:700;">Pending</span>'
          :'<span style="background:#fdecea;color:#c0392b;padding:3px 10px;border-radius:10px;font-size:10px;font-weight:700;">Denied</span>';
        th+='<tr style="border-bottom:1px solid #f3f3f3;" onmouseover="this.style.background=\'#f7fdf9\'" onmouseout="this.style.background=\'' + '\' ">';
        th+='<td style="padding:12px 16px;font-size:12px;font-weight:600;color:#1a1a1a;">'+r.requestor+'</td>';
        th+='<td style="padding:12px 16px;font-size:12px;font-weight:600;color:#1b5e38;">'+r.barangay+'</td>';
        th+='<td style="padding:12px 16px;font-size:12px;color:#555;">'+r.service+'</td>';
        th+='<td style="padding:12px 16px;font-size:12px;color:#888;">'+r.date+'</td>';
        th+='<td style="padding:12px 16px;">'+badge+'</td>';
        th+='</tr>';
      });
      tbody.innerHTML=th;
    }
  }

  function svcIcon(s) {
    var icons = {
      'Ambulance Assistance': '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
      'Rescue Operation': '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>',
      'Fire Assistance': '<path d="M12 2c0 6-6 8-6 14a6 6 0 0 0 12 0c0-6-6-8-6-14z"/>',
      'Flood Relief': '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/>'
    };
    return icons[s] || '<circle cx="12" cy="12" r="10"/>';
  }

  function renderRequests() {
    var pending = requests.filter(function(r) { return r.status === 'pending'; }).length;
    var rc = document.getElementById('reqCount');
    if (rc) rc.textContent = pending;
    var tr = document.getElementById('totalReq');
    if (tr) tr.textContent = requests.length;

    var tbody = document.getElementById('reqTableBody');
    if (!tbody) return;
    var html = '';
    requests.forEach(function(r) {
      var action = '';
      if (r.status === 'pending') {
        action = '<button class="abtn approve-btn" onclick="event.stopPropagation();window.approveReq(\'' + r.id + '\')">Approve</button>';
        action += '<button class="abtn cancel-btn-sm" onclick="event.stopPropagation();window.cancelReq(\'' + r.id + '\')">Cancel</button>';
      } else if (r.status === 'approved') {
        action = '<span class="status-pill pill-approved"><span class="status-dot" style="background:#1b5e38;"></span>Approved</span>';
      } else {
        action = '<span class="status-pill pill-cancelled"><span class="status-dot" style="background:#c0392b;"></span>Cancelled</span>';
      }
      html += '<div class="req-row" onclick="window.openReqDetail(\'' + r.id + '\')">';
      html += '<div class="svc-cell"><div class="svc-icon"><svg viewBox="0 0 24 24" stroke="#1b5e38" fill="none" stroke-width="2" width="16" height="16">' + svcIcon(r.service) + '</svg></div><span class="svc-name">' + r.service + '</span></div>';
      html += '<span class="req-name">' + r.requestor + '</span>';
      html += '<div><span class="ts-date">' + r.date + '</span><span class="ts-time"> ' + r.time + '</span></div>';
      html += '<div class="action-btns">' + action + '</div>';
      html += '</div>';
    });
    tbody.innerHTML = html;
  }

  window.approveReq = function(id) {
    var r = requests.find(function(x) { return x.id === id; });
    if (r) r.status = 'approved';
    renderRequests();
    renderDashboard();
  };

  window.cancelReq = function(id) {
    var r = requests.find(function(x) { return x.id === id; });
    if (r) r.status = 'cancelled';
    renderRequests();
    renderDashboard();
  };

  window.openReqDetail = function(id) {
    var r = requests.find(function(x) { return x.id === id; });
    if (!r) return;
    currentDetailId = id;

    document.getElementById('detailService').textContent = r.service;
    document.getElementById('detailName').textContent = r.requestor;
    document.getElementById('detailDate').textContent = r.date + ' at ' + r.time;
    document.getElementById('detailId').textContent = r.id;

    var statusMap = {
      pending:   '<span class="status-pill pill-pending"><span class="status-dot" style="background:#856404;"></span>Pending</span>',
      approved:  '<span class="status-pill pill-approved"><span class="status-dot" style="background:#1b5e38;"></span>Approved</span>',
      cancelled: '<span class="status-pill pill-cancelled"><span class="status-dot" style="background:#c0392b;"></span>Cancelled</span>'
    };
    document.getElementById('detailStatus').innerHTML = statusMap[r.status] || '';

    var tl = '<div style="display:flex;flex-direction:column;gap:10px;">';
    tl += '<div style="display:flex;align-items:flex-start;gap:10px;"><div style="width:8px;height:8px;border-radius:50%;background:#1b5e38;margin-top:4px;flex-shrink:0;"></div><div><div style="font-size:12px;font-weight:700;color:#1a1a1a;">Request Submitted</div><div style="font-size:11px;color:#888;">' + r.date + ' at ' + r.time + '</div></div></div>';
    if (r.status === 'approved') {
      tl += '<div style="display:flex;align-items:flex-start;gap:10px;"><div style="width:8px;height:8px;border-radius:50%;background:#1b5e38;margin-top:4px;flex-shrink:0;"></div><div><div style="font-size:12px;font-weight:700;color:#1a1a1a;">Approved by MDRRMO</div><div style="font-size:11px;color:#888;">Action taken</div></div></div>';
    }
    if (r.status === 'cancelled') {
      tl += '<div style="display:flex;align-items:flex-start;gap:10px;"><div style="width:8px;height:8px;border-radius:50%;background:#c0392b;margin-top:4px;flex-shrink:0;"></div><div><div style="font-size:12px;font-weight:700;color:#c0392b;">Request Cancelled</div><div style="font-size:11px;color:#888;">Action taken</div></div></div>';
    }
    if (r.notes) {
      tl += '<div style="padding:10px 14px;background:#fff;border-radius:8px;border:1px solid #eee;font-size:12px;color:#555;"><strong>Notes:</strong> ' + r.notes + '</div>';
    }
    tl += '</div>';
    document.getElementById('detailTimeline').innerHTML = tl;

    var actEl = document.getElementById('detailActions');
    if (r.status === 'pending') {
      actEl.innerHTML = '<button onclick="window.approveReq(\'' + r.id + '\');closeReqDetail();" style="flex:1;background:#1b5e38;color:#fff;border:none;border-radius:9px;padding:12px;font-size:13px;font-weight:700;cursor:pointer;">Approve</button><button onclick="window.cancelReq(\'' + r.id + '\');closeReqDetail();" style="flex:1;background:#fff;border:1.5px solid #e8b4b4;color:#c0392b;border-radius:9px;padding:12px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>';
    } else {
      actEl.innerHTML = '<button onclick="closeReqDetail()" style="flex:1;background:#1b5e38;color:#fff;border:none;border-radius:9px;padding:12px;font-size:13px;font-weight:700;cursor:pointer;">Close</button>';
    }
    actEl.style.display = 'flex';
    actEl.style.gap = '10px';

    openModal('reqDetailModal');
  };

  window.closeReqDetail = function() {
    closeModal('reqDetailModal');
  }

  function openModal(id) {
    var m = document.getElementById(id);
    if (m) m.style.display = 'flex';
  }
  window.closeModal = function(id) {
    var m = document.getElementById(id);
    if (m) m.style.display = 'none';
  }

  var RATE_PER_SEGMENT = 11.90;
  var CHARS_PER_SEGMENT = 160;
  var RECIPIENT_MAP = {
    'San Fabian Echague Isabela': 45,
    'Brgy. Divisoria, Echague': 32,
    'Brgy. Calamagui, Echague': 28,
    'All Barangays': 120
  };

  function calcSegments(len) {
    if (len === 0) return 0;
    return Math.ceil(len / CHARS_PER_SEGMENT);
  }

  function getRecipientCount() {
    var sel = document.getElementById('smsAreaSel');
    if (!sel) return 1;
    return RECIPIENT_MAP[sel.value] || 1;
  }

  window.updateSMSCost = function() {
    var msgEl = document.getElementById('smsMsg');
    var charEl = document.getElementById('charCount');
    var badgeEl = document.getElementById('smsCostBadge');
    var areaEl = document.getElementById('smsAreaSel');
    if (!msgEl) return;
    var len = msgEl.value.length;
    var segs = (len === 0) ? 0 : Math.ceil(len / 160);
    var recip = RECIPIENT_MAP[(areaEl ? areaEl.value : '')] || 1;
    var costPerRecip = segs * RATE_PER_SEGMENT;
    var total = costPerRecip * recip;
    if (charEl) charEl.textContent = len + ' chars / ' + segs + ' segment' + (segs !== 1 ? 's' : '');
    if (badgeEl) badgeEl.textContent = 'Total Cost: ₱' + total.toFixed(2);
  };
  function updateCharCount() {
    updateSMSCost();
  }
  window.updateCharCount = updateCharCount;

  function sendSMS() {
    var t = document.getElementById('smsToast');
    if (t) {
      var recipients = getRecipientCount();
      var msg = document.getElementById('smsMsg');
      var segments = msg ? calcSegments(msg.value.length) : 1;
      var cost = (segments * RATE_PER_SEGMENT * recipients).toFixed(2);
      t.textContent = '✓ SMS sent to ' + recipients + ' recipients! Total charged: ₱' + cost;
      t.style.display = 'block';
      setTimeout(function() { t.style.display = 'none'; }, 4000);
    }
  }

  function openAddUserModal() {
    document.getElementById('newName').value = '';
    document.getElementById('newPhone').value = '';
    document.getElementById('newEmail').value = '';
    document.getElementById('newBarangay').value = '';
    document.getElementById('addUserErr').style.display = 'none';
    document.getElementById('avatarInitials').textContent = '?';
    document.getElementById('avatarInitials').style.display = 'block';
    var prev = document.getElementById('avatarPreview');
    prev.style.backgroundImage = '';
    prev.style.backgroundSize = '';
    newAvatarDataUrl = null;
    var radios = document.querySelectorAll('input[name="newStatus"]');
    if (radios.length) radios[0].checked = true;
    openModal('addUserModal');
  }

  function updateAvatarPreview() {
    var name = document.getElementById('newName').value.trim();
    var parts = name.split(' ').filter(function(p) { return p.length > 0; });
    var initials = parts.length >= 2
      ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
      : (parts.length === 1 ? parts[0][0].toUpperCase() : '?');
    document.getElementById('avatarInitials').textContent = initials;
  }

  function handleAvatarUpload(input) {
    if (!input.files || !input.files.length) return;
    var reader = new FileReader();
    reader.onload = function(e) {
      newAvatarDataUrl = e.target.result;
      var prev = document.getElementById('avatarPreview');
      prev.style.backgroundImage = 'url(' + e.target.result + ')';
      prev.style.backgroundSize = 'cover';
      prev.style.backgroundPosition = 'center';
      document.getElementById('avatarInitials').style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
  }

  function submitAddUser() {
    var name = document.getElementById('newName').value.trim();
    var phone = document.getElementById('newPhone').value.trim();
    var email = document.getElementById('newEmail').value.trim();
    var barangay = document.getElementById('newBarangay').value;
    var statusEl = document.querySelector('input[name="newStatus"]:checked');
    var status = statusEl ? statusEl.value : 'online';
    var err = document.getElementById('addUserErr');

    if (!name) { err.style.display = 'block'; err.textContent = 'Full name is required.'; return; }
    if (!phone) { err.style.display = 'block'; err.textContent = 'Phone number is required.'; return; }
    if (!email || email.indexOf('@') === -1) { err.style.display = 'block'; err.textContent = 'Valid email is required.'; return; }
    if (!barangay) { err.style.display = 'block'; err.textContent = 'Please select a barangay.'; return; }
    err.style.display = 'none';

    var parts = name.split(' ').filter(function(p) { return p.length > 0; });
    var initials = parts.length >= 2
      ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
      : parts[0][0].toUpperCase();

    users.unshift({initials: initials, name: name, barangay: barangay, phone: phone, email: email, status: status, avatarImg: newAvatarDataUrl});
    closeModal('addUserModal');
    renderUsers(users);
    showToast(name + ' has been added successfully!');
  }

  function renderUsers(list) {
    var tbody = document.getElementById('userTableBody');
    if (!tbody) return;
    var html = '';
    list.forEach(function(u) {
      var realIdx = users.indexOf(u);
      var pill = u.status === 'online'
        ? '<span class="online-pill"><span class="pill-dot" style="background:#1b5e38;"></span>Online</span>'
        : '<span class="offline-pill"><span class="pill-dot" style="background:#aaa;"></span>Offline</span>';
      var avatar = u.avatarImg
        ? '<div style="width:36px;height:36px;border-radius:50%;background-image:url(' + u.avatarImg + ');background-size:cover;background-position:center;flex-shrink:0;"></div>'
        : '<div class="av-circle" style="flex-shrink:0;">' + u.initials + '</div>';
      html += '<div class="user-row" onclick="window.openEditUserModal(' + realIdx + ')" style="cursor:pointer;" title="Click to edit profile">';
      html += avatar;
      html += '<span style="font-size:12px;font-weight:600;color:#1a1a1a;">' + u.name + '</span>';
      html += '<span style="font-size:12px;color:#666;">' + u.barangay + '</span>';
      html += '<span style="font-size:12px;color:#666;">' + u.phone + '</span>';
      html += '<span style="font-size:12px;color:#666;">' + u.email + '</span>';
      html += pill;
      html += '</div>';
    });
    tbody.innerHTML = html;
  }

  function filterUsers() {
    var q = document.getElementById('userSearch').value.toLowerCase();
    var s = document.getElementById('userStatusFilter').value;
    var list = users.filter(function(u) {
      var matchQ = !q || u.name.toLowerCase().indexOf(q) !== -1 || u.email.toLowerCase().indexOf(q) !== -1;
      var matchS = s === 'all' || u.status === s;
      return matchQ && matchS;
    });
    renderUsers(list);
  }

  var catColors = {Health:['#1b5e38','#e8f5ec'], Incident:['#e07b00','#fff3e0'], Calamity:['#c0392b','#fdecea'], General:['#1565c0','#e3f0fb']};
  var typeIcons = {
    pdf: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/>',
    docx: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
    img: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>'
  };
  var typeColors = {pdf:['#c0392b','#fdecea'], docx:['#1565c0','#e3f0fb'], img:['#e07b00','#fff3e0']};

  function renderFiles(list) {
    var tbody = document.getElementById('fileTableBody');
    if (!tbody) return;
    var html = '';
    list.forEach(function(f, i) {
      var cc = catColors[f.cat] || ['#555','#eee'];
      var tc = typeColors[f.type] || ['#555','#eee'];
      var ic = typeIcons[f.type] || typeIcons.docx;
      html += '<tr>';
      html += '<td class="fname-cell"><div class="ftype-icon" style="background:' + tc[1] + ';color:' + tc[0] + ';"><svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" width="14" height="14">' + ic + '</svg></div>' + f.name + '</td>';
      html += '<td><span class="cat-badge" style="background:' + cc[1] + ';color:' + cc[0] + ';">' + f.cat + '</span></td>';
      html += '<td style="color:#777;">' + f.size + '</td>';
      html += '<td style="color:#777;">' + f.date + '</td>';
      html += '<td><button class="icon-btn dl-btn" title="Download">↓</button><button class="icon-btn del-btn" title="Delete" onclick="window.deleteFile(' + i + ')">🗑</button></td>';
      html += '</tr>';
    });
    tbody.innerHTML = html;
    var fc = document.getElementById('fileCount');
    if (fc) fc.textContent = 'Showing ' + list.length + ' file' + (list.length === 1 ? '' : 's');
  }

  window.deleteFile = function(i) {
    if (confirm('Delete ' + files[i].name + '?')) {
      files.splice(i, 1);
      renderFiles(files);
    }
  };

  function handleFileUpload(input) {
    if (!input.files || !input.files.length) return;
    var f = input.files[0];
    var ext = f.name.split('.').pop().toLowerCase();
    var type = (ext === 'jpg' || ext === 'jpeg' || ext === 'png') ? 'img' : ext;
    files.unshift({name: f.name, cat: 'General', size: (f.size / 1024 / 1024).toFixed(1) + ' MB', date: 'Mar 21, 2026', type: type});
    renderFiles(files);
    var t = document.getElementById('fileToast');
    if (t) { t.textContent = f.name + ' uploaded successfully!'; t.style.display = 'block'; setTimeout(function() { t.style.display = 'none'; }, 3000); }
    input.value = '';
  }

  function sendToMobile() {
    var t = document.getElementById('fileToast');
    if (t) { t.textContent = 'Documents pushed to MDRRMO Mobile App!'; t.style.display = 'block'; setTimeout(function() { t.style.display = 'none'; }, 3000); }
  }

  function showToast(msg) {
    var t = document.getElementById('globalToast');
    if (t) { t.textContent = msg; t.style.display = 'block'; setTimeout(function() { t.style.display = 'none'; }, 3000); }
  }

  function init() {
    setLogos();
    renderRequests();
    renderUsers(users);
    renderFiles(files);

    document.getElementById('loginBtn').addEventListener('click', doLogin);
    document.getElementById('passInp').addEventListener('keydown', function(e) { if (e.key === 'Enter') doLogin(); });
    document.getElementById('emailInp').addEventListener('keydown', function(e) { if (e.key === 'Enter') doLogin(); });
    document.getElementById('logoutBtn').addEventListener('click', doLogout);

    var navLinks = {'nav-dashboard':'dashboard','nav-requests':'requests','nav-sms':'sms','nav-users':'users','nav-files':'files','nav-logs':'logs'};
    Object.keys(navLinks).forEach(function(navId) {
      var el = document.getElementById(navId);
      if (el) el.addEventListener('click', function() { showPage(navLinks[navId]); });
    });

    var smsMsg = document.getElementById('smsMsg');
    if (smsMsg) smsMsg.addEventListener('input', updateCharCount);
    updateSMSCost();
    var sendBtn = document.getElementById('sendSmsBtn');
    if (sendBtn) sendBtn.addEventListener('click', sendSMS);
    var sendBtnB = document.getElementById('sendSmsBtnBottom');
    if (sendBtnB) sendBtnB.addEventListener('click', sendSMS);

    document.getElementById('addUserBtn').addEventListener('click', openAddUserModal);
    document.getElementById('closeAddUserBtn').addEventListener('click', function() { closeModal('addUserModal'); });
    document.getElementById('cancelAddUserBtn').addEventListener('click', function() { closeModal('addUserModal'); });
    document.getElementById('submitAddUserBtn').addEventListener('click', submitAddUser);
    document.getElementById('newName').addEventListener('input', updateAvatarPreview);
    document.getElementById('avatarInput').addEventListener('change', function() { handleAvatarUpload(this); });
    document.getElementById('addUserModal').addEventListener('click', function(e) { if (e.target === this) closeModal('addUserModal'); });

    document.getElementById('userSearch').addEventListener('input', filterUsers);
    document.getElementById('userStatusFilter').addEventListener('change', filterUsers);

    document.getElementById('closeReqDetailBtn').addEventListener('click', closeReqDetail);
    document.getElementById('reqDetailModal').addEventListener('click', function(e) { if (e.target === this) closeReqDetail(); });

    var uploadBtn = document.getElementById('uploadFileBtn');
    var fileInput = document.getElementById('fileInput');
    if (uploadBtn) uploadBtn.addEventListener('click', function() { fileInput.click(); });
    if (fileInput) fileInput.addEventListener('change', function() { handleFileUpload(this); });
    var dropZone = document.getElementById('dropZone');
    if (dropZone) {
      dropZone.addEventListener('click', function() { fileInput.click(); });
      dropZone.addEventListener('dragover', function(e) { e.preventDefault(); });
      dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        if (e.dataTransfer.files.length) handleFileUpload({files: e.dataTransfer.files, value: ''});
      });
    }
    var mobileBtn = document.getElementById('sendMobileBtn');
    if (mobileBtn) mobileBtn.addEventListener('click', sendToMobile);

    document.querySelectorAll('.folder-card').forEach(function(card) {
      card.addEventListener('click', function() {
        document.querySelectorAll('.folder-card').forEach(function(c) { c.classList.remove('active'); });
        card.classList.add('active');
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  var editingUserIdx = -1;
  var editAvatarDataUrl = null;

  window.openEditUserModal = function(idx) {
    if (idx < 0 || idx >= users.length) return;
    editingUserIdx = idx;
    var u = users[idx];
    document.getElementById('editName').value = u.name || '';
    document.getElementById('editPhone').value = u.phone || '';
    document.getElementById('editEmail').value = u.email || '';
    document.getElementById('editBarangay').value = u.barangay || '';
    document.getElementById('editUserErr').style.display = 'none';
    editAvatarDataUrl = u.avatarImg || null;
    var prev = document.getElementById('editAvatarPreview');
    var initEl = document.getElementById('editAvatarInitials');
    if (u.avatarImg) {
      prev.style.backgroundImage = 'url(' + u.avatarImg + ')';
      prev.style.backgroundSize = 'cover';
      prev.style.backgroundPosition = 'center';
      if (initEl) initEl.style.display = 'none';
    } else {
      prev.style.backgroundImage = '';
      if (initEl) { initEl.style.display = 'flex'; initEl.textContent = u.initials || '?'; }
    }
    var radios = document.querySelectorAll('input[name="editStatus"]');
    radios.forEach(function(r) { r.checked = (r.value === u.status); });
    document.getElementById('editUserModal').style.display = 'flex';
  };

  window.handleEditAvatarUpload = function(input) {
    if (!input.files || !input.files.length) return;
    var reader = new FileReader();
    reader.onload = function(e) {
      editAvatarDataUrl = e.target.result;
      var prev = document.getElementById('editAvatarPreview');
      prev.style.backgroundImage = 'url(' + e.target.result + ')';
      prev.style.backgroundSize = 'cover';
      prev.style.backgroundPosition = 'center';
      var el = document.getElementById('editAvatarInitials');
      if (el) el.style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
  };

  window.updateEditInitials = function() {
    if (editAvatarDataUrl) return;
    var name = document.getElementById('editName').value.trim();
    var parts = name.split(' ').filter(function(p){ return p.length > 0; });
    var initials = parts.length >= 2 ? (parts[0][0]+parts[parts.length-1][0]).toUpperCase() : (parts.length===1 ? parts[0][0].toUpperCase() : '?');
    var el = document.getElementById('editAvatarInitials');
    if (el) el.textContent = initials;
  };

  window.submitEditUser = function() {
    if (editingUserIdx < 0) return;
    var name = document.getElementById('editName').value.trim();
    var phone = document.getElementById('editPhone').value.trim();
    var email = document.getElementById('editEmail').value.trim();
    var barangay = document.getElementById('editBarangay').value;
    var statusEl = document.querySelector('input[name="editStatus"]:checked');
    var status = statusEl ? statusEl.value : 'online';
    var err = document.getElementById('editUserErr');
    if (!name) { err.style.display='block'; err.textContent='Full name is required.'; return; }
    if (!phone) { err.style.display='block'; err.textContent='Phone number is required.'; return; }
    if (!email || email.indexOf('@')===-1) { err.style.display='block'; err.textContent='Valid email is required.'; return; }
    if (!barangay) { err.style.display='block'; err.textContent='Please select a barangay.'; return; }
    err.style.display = 'none';
    var parts = name.split(' ').filter(function(p){ return p.length>0; });
    var initials = parts.length>=2 ? (parts[0][0]+parts[parts.length-1][0]).toUpperCase() : parts[0][0].toUpperCase();
    users[editingUserIdx] = { initials:initials, name:name, barangay:barangay, phone:phone, email:email, status:status, avatarImg:editAvatarDataUrl };
    document.getElementById('editUserModal').style.display = 'none';
    renderUsers(users);
    renderDashboard();
    showToast(name + ' profile updated successfully!');
  };

  window.deleteEditUser = function() {
    if (editingUserIdx < 0) return;
    var uname = users[editingUserIdx].name;
    if (confirm('Delete ' + uname + '? This cannot be undone.')) {
      users.splice(editingUserIdx, 1);
      document.getElementById('editUserModal').style.display = 'none';
      renderUsers(users);
      renderDashboard();
      showToast(uname + ' has been removed.');
    }
  };

  document.addEventListener('DOMContentLoaded', function() {
    var ce = document.getElementById('closeEditUserBtn');
    if (ce) ce.addEventListener('click', function(){ document.getElementById('editUserModal').style.display='none'; });
    var ca = document.getElementById('cancelEditUserBtn');
    if (ca) ca.addEventListener('click', function(){ document.getElementById('editUserModal').style.display='none'; });
    var se = document.getElementById('submitEditUserBtn');
    if (se) se.addEventListener('click', window.submitEditUser);
    var de = document.getElementById('deleteUserBtn');
    if (de) de.addEventListener('click', window.deleteEditUser);
    var ae = document.getElementById('editAvatarInput');
    if (ae) ae.addEventListener('change', function(){ window.handleEditAvatarUpload(this); });
    var ne = document.getElementById('editName');
    if (ne) ne.addEventListener('input', window.updateEditInitials);
    var em = document.getElementById('editUserModal');
    if (em) em.addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
  });
})();

/**
 * ═══════════════════════════════════════════════════════════════
 *  KPI Dashboard — โรงพยาบาลแม่แตง
 *  Google Apps Script Backend  (Code.gs)
 *
 *  วิธีติดตั้ง:
 *  1. เปิด https://script.google.com → New Project
 *  2. Copy code นี้ทั้งหมดวางแทน Code.gs
 *  3. แก้ SPREADSHEET_ID ด้านล่าง
 *  4. Deploy → New Deployment → Web App
 *     - Execute as: Me
 *     - Who has access: Anyone
 *  5. Copy URL ไปใส่ใน Dashboard ตรง "Google Sheets API URL"
 * ═══════════════════════════════════════════════════════════════
 */

// ══════════════════════════════════════════
//  CONFIG — แก้ค่านี้ก่อนใช้งาน
// ══════════════════════════════════════════
const SPREADSHEET_ID = 'YOUR_SPREADSHEET_ID_HERE'; // ← ใส่ ID ของ Google Sheet ที่สร้างไว้

// ชื่อ Sheet (tab) ต่าง ๆ
const SH = {
  STRAT:    'KPI_ยุทธศาสตร์',
  HA1:      'KPI_HA_Part1',
  HA2:      'KPI_HA_Part2',
  HA4:      'KPI_HA_Part4',
  LOGS:     'Logs',
  USERS:    'Users',
  SETTINGS: 'Settings',
  SYNC_MAP: 'SyncMap',     // ตาราง mapping HA → ยุทธศาสตร์
};

// ปีงบประมาณที่รองรับ
const FYS = ['2568','2569','2570'];

// ══════════════════════════════════════════
//  CORS HEADER
// ══════════════════════════════════════════
function setCORS(output) {
  return ContentService
    .createTextOutput(JSON.stringify(output))
    .setMimeType(ContentService.MimeType.JSON);
}

// ══════════════════════════════════════════
//  doPost — Router หลัก
// ══════════════════════════════════════════
function doPost(e) {
  try {
    const params = JSON.parse(e.postData.contents);
    const action = params.action;

    switch (action) {
      case 'ping':         return setCORS({ ok: true, msg: 'pong' });
      case 'login':        return setCORS(handleLogin(params));
      case 'saveKPI':      return setCORS(handleSaveKPI(params));
      case 'loadAll':      return setCORS(handleLoadAll(params));
      case 'getLogs':      return setCORS(handleGetLogs(params));
      case 'getUsers':     return setCORS(handleGetUsers(params));
      case 'addUser':      return setCORS(handleAddUser(params));
      case 'deleteUser':   return setCORS(handleDeleteUser(params));
      case 'saveSettings': return setCORS(handleSaveSettings(params));
      case 'getSettings':  return setCORS(handleGetSettings());
      default:             return setCORS({ ok: false, msg: 'Unknown action: ' + action });
    }
  } catch (err) {
    return setCORS({ ok: false, msg: err.toString() });
  }
}

// ══════════════════════════════════════════
//  doGet — Health check / initial setup
// ══════════════════════════════════════════
function doGet(e) {
  const p = e.parameter;
  if (p.setup === '1') {
    setupSheets();
    return setCORS({ ok: true, msg: 'Sheets setup complete' });
  }
  return setCORS({ ok: true, msg: 'KPI Dashboard API — รพ.แม่แตง', version: '2.3' });
}

// ══════════════════════════════════════════
//  SPREADSHEET HELPER
// ══════════════════════════════════════════
function getSS() {
  return SpreadsheetApp.openById(SPREADSHEET_ID);
}

function getSheet(name, create) {
  const ss = getSS();
  let sh = ss.getSheetByName(name);
  if (!sh && create) {
    sh = ss.insertSheet(name);
  }
  return sh;
}

// ══════════════════════════════════════════
//  SETUP — สร้าง Sheets ครั้งแรก
// ══════════════════════════════════════════
function setupSheets() {
  const ss = getSS();

  // ── Users ──
  let usersSheet = getSheet(SH.USERS, true);
  if (usersSheet.getLastRow() === 0) {
    usersSheet.appendRow(['id','username','password','name','role','dept','canEdit','createdAt']);
    // Default users
    const now = new Date().toISOString();
    usersSheet.appendRow(['u1','superadmin','Admin@1234','Super Administrator','superadmin','IT','[]', now]);
    usersSheet.appendRow(['u2','hn.admin','HN@2568','เจ้าหน้าที่ HA ทีมนำ','admin','HA-P1','["ha1"]', now]);
    usersSheet.appendRow(['u3','rm.admin','RM@2568','เจ้าหน้าที่ RM','admin','HA-P2','["ha2"]', now]);
    usersSheet.appendRow(['u4','strategy.admin','ST@2568','เจ้าหน้าที่ยุทธศาสตร์','admin','Strategic','["strat"]', now]);
    usersSheet.appendRow(['u5','viewer','View@123','ผู้สังเกตการณ์','user','-','[]', now]);
    styleHeaderRow(usersSheet);
  }

  // ── Settings ──
  let setSheet = getSheet(SH.SETTINGS, true);
  if (setSheet.getLastRow() === 0) {
    setSheet.appendRow(['key','value']);
    setSheet.appendRow(['activeYear','2569']);
    setSheet.appendRow(['hospitalName','โรงพยาบาลแม่แตง']);
    setSheet.appendRow(['version','2.3']);
    setSheet.appendRow(['lastUpdate', new Date().toISOString()]);
    styleHeaderRow(setSheet);
  }

  // ── Logs ──
  let logsSheet = getSheet(SH.LOGS, true);
  if (logsSheet.getLastRow() === 0) {
    logsSheet.appendRow(['timestamp','user','role','action','detail','level','ip']);
    styleHeaderRow(logsSheet);
    logsSheet.setFrozenRows(1);
  }

  // ── KPI ยุทธศาสตร์ ──
  setupStratSheet();

  // ── KPI HA Part 1 ──
  setupHASheet(SH.HA1, 'Part 1 – ทีมนำ');

  // ── KPI HA Part 2 ──
  setupHASheet(SH.HA2, 'Part 2 – ระบบงานสำคัญ');

  // ── KPI HA Part 4 ──
  setupHASheet(SH.HA4, 'Part 4 – ผลลัพธ์');

  // ── SyncMap ──
  let syncSheet = getSheet(SH.SYNC_MAP, true);
  if (syncSheet.getLastRow() === 0) {
    syncSheet.appendRow(['ha_indicator_id','ha_part','strat_indicator_id','description','lastSync']);
    styleHeaderRow(syncSheet);
  }

  return { ok: true };
}

function setupStratSheet() {
  let sh = getSheet(SH.STRAT, true);
  if (sh.getLastRow() > 0) return;

  // Headers
  const months2568 = fyMonths('2568');
  const months2569 = fyMonths('2569');
  const months2570 = fyMonths('2570');

  const header = [
    'indicator_id','ยุทธศาสตร์_no','ยุทธศาสตร์_name',
    'แผนงาน_no','แผนงาน_name','ลำดับที่','ตัวชี้วัด',
    'หน่วยงานหลัก','รายงาน','เป้าหมาย','หน่วย',
    'ha_link_id', 'lastUpdated', 'updatedBy'
  ];
  // append FY columns
  ['2568','2569','2570'].forEach(fy => {
    const ms = fyMonths(fy);
    ms.forEach(m => header.push(`${fy}_${m}`));
    header.push(`${fy}_สถานะ`);
  });

  sh.appendRow(header);
  styleHeaderRow(sh);
  sh.setFrozenRows(1);
  sh.setFrozenColumns(7);
}

function setupHASheet(shName, partName) {
  let sh = getSheet(shName, true);
  if (sh.getLastRow() > 0) return;

  const header = [
    'indicator_id','section_id','section_name','ลำดับที่','ตัวชี้วัด',
    'เป้าหมาย','หน่วย','ผู้รับผิดชอบ','strat_link_id',
    'lastUpdated','updatedBy'
  ];
  ['2568','2569','2570'].forEach(fy => {
    const ms = fyMonths(fy);
    ms.forEach(m => header.push(`${fy}_${m}`));
    header.push(`${fy}_สถานะ`);
  });

  sh.appendRow(header);
  styleHeaderRow(sh);
  sh.setFrozenRows(1);
  sh.setFrozenColumns(5);
}

function styleHeaderRow(sh) {
  const range = sh.getRange(1, 1, 1, sh.getLastColumn() || 30);
  range.setBackground('#1e3a5f')
       .setFontColor('#ffffff')
       .setFontWeight('bold')
       .setFontSize(11);
  sh.setFrozenRows(1);
}

// ══════════════════════════════════════════
//  fyMonths — เหมือนใน HTML
// ══════════════════════════════════════════
function fyMonths(fy) {
  const y = parseInt(fy), p = y - 1;
  const s = n => String(n).slice(2);
  return [
    `ต.ค.${s(p)}`,`พ.ย.${s(p)}`,`ธ.ค.${s(p)}`,
    `ม.ค.${s(y)}`,`ก.พ.${s(y)}`,`มี.ค.${s(y)}`,
    `เม.ย.${s(y)}`,`พ.ค.${s(y)}`,`มิ.ย.${s(y)}`,
    `ก.ค.${s(y)}`,`ส.ค.${s(y)}`,`ก.ย.${s(y)}`,
  ];
}

// ══════════════════════════════════════════
//  LOGIN
// ══════════════════════════════════════════
function handleLogin(params) {
  const { username, password } = params;
  const sh = getSheet(SH.USERS, false);
  if (!sh) return { ok: false, msg: 'Users sheet not found. Please run Setup first.' };

  const data = sh.getDataRange().getValues();
  for (let i = 1; i < data.length; i++) {
    const row = data[i];
    if (row[1] === username && row[2] === password) {
      const user = {
        id: row[0], username: row[1], name: row[3],
        role: row[4], dept: row[5],
        canEdit: JSON.parse(row[6] || '[]')
      };
      writeLog(username, row[4], 'เข้าสู่ระบบ', 'login สำเร็จ', 'success');
      return { ok: true, user };
    }
  }
  writeLog(username, 'guest', 'Login ล้มเหลว', 'username: '+username, 'warn');
  return { ok: false, msg: 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง' };
}

// ══════════════════════════════════════════
//  SAVE KPI
// ══════════════════════════════════════════
function handleSaveKPI(params) {
  /*
   params: {
     action: 'saveKPI',
     token: { username, name, role },
     kpiType: 'strategic' | 'ha1' | 'ha2' | 'ha4',
     indicatorId: 'xxx',
     indicatorName: 'xxx',
     sectionId: 'xxx',     // for HA only
     target: 'xxx',
     values: { '2568': ['','','',....], '2569': [...], '2570': [...] },
     haLink: 'strat_id',   // for HA — ถ้ามีให้ sync ไปยุทธศาสตร์ด้วย
     unit: 'ร้อยละ'
   }
  */
  const { token, kpiType, indicatorId, indicatorName, sectionId,
          target, values, haLink, unit, focal, report } = params;

  const user = token?.name || 'ระบบ';
  const now  = new Date().toISOString();

  let shName;
  if (kpiType === 'strategic') shName = SH.STRAT;
  else if (kpiType === 'ha1')  shName = SH.HA1;
  else if (kpiType === 'ha2')  shName = SH.HA2;
  else if (kpiType === 'ha4')  shName = SH.HA4;
  else return { ok: false, msg: 'Invalid kpiType: ' + kpiType };

  const sh = getSheet(shName, true);
  if (sh.getLastRow() === 0) {
    if (kpiType === 'strategic') setupStratSheet();
    else setupHASheet(shName, kpiType);
  }

  // Build column map from header row
  const headers = sh.getRange(1, 1, 1, sh.getLastColumn()).getValues()[0];
  const colMap = {};
  headers.forEach((h, i) => { colMap[h] = i + 1; }); // 1-based

  // Find existing row or append
  const allData = sh.getDataRange().getValues();
  let rowNum = -1;
  for (let i = 1; i < allData.length; i++) {
    if (allData[i][0] === indicatorId) { rowNum = i + 1; break; }
  }

  // Build row data
  const rowData = buildRowData(headers, {
    indicator_id:    indicatorId,
    section_id:      sectionId || '',
    ตัวชี้วัด:        indicatorName,
    เป้าหมาย:        target || '',
    หน่วย:           unit || '',
    ผู้รับผิดชอบ:    params.responsible || '',
    หน่วยงานหลัก:   focal || '',
    รายงาน:          report || '',
    ha_link_id:      haLink || '',
    strat_link_id:   haLink || '',
    lastUpdated:     now,
    updatedBy:       user,
    values:          values || {}
  });

  if (rowNum > 0) {
    sh.getRange(rowNum, 1, 1, rowData.length).setValues([rowData]);
  } else {
    sh.appendRow(rowData);
    rowNum = sh.getLastRow();
  }

  // Compute status per FY and write
  FYS.forEach(fy => {
    const statusCol = colMap[`${fy}_สถานะ`];
    if (statusCol) {
      const vals = values?.[fy] || [];
      const latest = [...vals].reverse().find(v => v !== '');
      const s = computeStatus(latest, target);
      sh.getRange(rowNum, statusCol).setValue(s);
    }
  });

  // Format value cells
  colorValueCells(sh, rowNum, headers, values || {}, target);

  // ── AUTO SYNC to Strategic if haLink exists ──
  if (haLink && kpiType !== 'strategic') {
    syncHAToStrategic(haLink, values, user, target);
  }

  writeLog(user, token?.role||'admin', 'บันทึก KPI', `${shName}: ${indicatorName}`, 'success');
  return { ok: true, synced: !!haLink, sheet: shName };
}

// ══════════════════════════════════════════
//  AUTO SYNC: HA → Strategic
// ══════════════════════════════════════════
function syncHAToStrategic(stratIndicatorId, values, user, haTarget) {
  try {
    const sh = getSheet(SH.STRAT, false);
    if (!sh || sh.getLastRow() === 0) return;

    const headers = sh.getRange(1, 1, 1, sh.getLastColumn()).getValues()[0];
    const allData = sh.getDataRange().getValues();
    let rowNum = -1;
    for (let i = 1; i < allData.length; i++) {
      if (allData[i][0] === stratIndicatorId) { rowNum = i + 1; break; }
    }
    if (rowNum < 0) return; // ไม่เจอ ข้ามไป

    // Update monthly values
    FYS.forEach(fy => {
      const ms = fyMonths(fy);
      ms.forEach((m, mi) => {
        const col = headers.indexOf(`${fy}_${m}`) + 1;
        if (col > 0) {
          const v = (values?.[fy] || [])[mi];
          if (v !== '' && v !== undefined) {
            sh.getRange(rowNum, col).setValue(v).setBackground('#e6f4ea');
          }
        }
      });
      // Update status
      const statusCol = headers.indexOf(`${fy}_สถานะ`) + 1;
      if (statusCol > 0) {
        const vals = values?.[fy] || [];
        const latest = [...vals].reverse().find(v => v !== '');
        sh.getRange(rowNum, statusCol).setValue(computeStatus(latest, haTarget));
      }
    });

    // Update lastUpdated
    const luCol = headers.indexOf('lastUpdated') + 1;
    const ubCol = headers.indexOf('updatedBy') + 1;
    if (luCol > 0) sh.getRange(rowNum, luCol).setValue(new Date().toISOString());
    if (ubCol > 0) sh.getRange(rowNum, ubCol).setValue(user + ' (Sync จาก HA)');

    // Log sync event
    const syncSh = getSheet(SH.SYNC_MAP, false);
    if (syncSh) {
      syncSh.appendRow([
        '', 'HA→Strat', stratIndicatorId,
        'Auto Sync', new Date().toISOString()
      ]);
    }
  } catch (e) {
    Logger.log('syncHAToStrategic error: ' + e.toString());
  }
}

// ══════════════════════════════════════════
//  LOAD ALL DATA
// ══════════════════════════════════════════
function handleLoadAll(params) {
  try {
    const result = {};

    // Strategic
    const stSh = getSheet(SH.STRAT, false);
    if (stSh && stSh.getLastRow() > 1) {
      result.strategic = sheetToObjects(stSh);
    }

    // HA Parts
    [SH.HA1, SH.HA2, SH.HA4].forEach(shName => {
      const sh = getSheet(shName, false);
      const key = shName.replace('KPI_', '').toLowerCase();
      if (sh && sh.getLastRow() > 1) {
        result[key] = sheetToObjects(sh);
      }
    });

    // Settings
    result.settings = loadSettings();

    return { ok: true, data: result };
  } catch (e) {
    return { ok: false, msg: e.toString() };
  }
}

// ══════════════════════════════════════════
//  GET LOGS
// ══════════════════════════════════════════
function handleGetLogs(params) {
  const sh = getSheet(SH.LOGS, false);
  if (!sh || sh.getLastRow() <= 1) return { ok: true, logs: [] };

  const data = sh.getDataRange().getValues();
  const headers = data[0];
  const logs = [];
  for (let i = data.length - 1; i >= 1; i--) {
    const row = data[i];
    const obj = {};
    headers.forEach((h, j) => { obj[h] = row[j]; });
    logs.push(obj);
    if (logs.length >= 500) break;
  }
  return { ok: true, logs };
}

// ══════════════════════════════════════════
//  USERS CRUD
// ══════════════════════════════════════════
function handleGetUsers(params) {
  const sh = getSheet(SH.USERS, false);
  if (!sh) return { ok: false, msg: 'Sheet not found' };
  const data = sh.getDataRange().getValues();
  const headers = data[0];
  const users = [];
  for (let i = 1; i < data.length; i++) {
    const row = data[i];
    if (!row[0]) continue;
    const u = {};
    headers.forEach((h, j) => { u[h] = row[j]; });
    delete u['password']; // ไม่ส่ง password กลับ
    u.canEdit = JSON.parse(u.canEdit || '[]');
    users.push(u);
  }
  return { ok: true, users };
}

function handleAddUser(params) {
  const { name, username, password, role, dept, canEdit } = params;
  const sh = getSheet(SH.USERS, true);
  const id = 'u' + Date.now();
  const now = new Date().toISOString();
  sh.appendRow([id, username, password, name, role, dept, JSON.stringify(canEdit||[]), now]);
  writeLog(params.token?.name||'admin', params.token?.role||'admin', 'เพิ่มผู้ใช้', username, 'success');
  return { ok: true };
}

function handleDeleteUser(params) {
  const { uid } = params;
  const sh = getSheet(SH.USERS, false);
  if (!sh) return { ok: false };
  const data = sh.getDataRange().getValues();
  for (let i = 1; i < data.length; i++) {
    if (data[i][0] === uid) {
      sh.deleteRow(i + 1);
      writeLog(params.token?.name||'admin', params.token?.role||'admin', 'ลบผู้ใช้', uid, 'warn');
      return { ok: true };
    }
  }
  return { ok: false, msg: 'User not found' };
}

// ══════════════════════════════════════════
//  SETTINGS
// ══════════════════════════════════════════
function handleSaveSettings(params) {
  const { settings } = params;
  const sh = getSheet(SH.SETTINGS, true);
  const data = sh.getDataRange().getValues();
  const rowMap = {};
  for (let i = 1; i < data.length; i++) rowMap[data[i][0]] = i + 1;

  Object.entries(settings).forEach(([k, v]) => {
    if (rowMap[k]) sh.getRange(rowMap[k], 2).setValue(v);
    else sh.appendRow([k, v]);
  });
  sh.getRange(rowMap['lastUpdate'] || sh.getLastRow()+1, 2).setValue(new Date().toISOString());
  return { ok: true };
}

function handleGetSettings() {
  return { ok: true, settings: loadSettings() };
}

function loadSettings() {
  const sh = getSheet(SH.SETTINGS, false);
  if (!sh) return {};
  const data = sh.getDataRange().getValues();
  const s = {};
  for (let i = 1; i < data.length; i++) { s[data[i][0]] = data[i][1]; }
  return s;
}

// ══════════════════════════════════════════
//  LOG WRITER
// ══════════════════════════════════════════
function writeLog(user, role, action, detail, level) {
  const sh = getSheet(SH.LOGS, true);
  if (sh.getLastRow() === 0) {
    sh.appendRow(['timestamp','user','role','action','detail','level']);
    styleHeaderRow(sh);
  }
  sh.appendRow([
    new Date().toLocaleString('th-TH', { timeZone: 'Asia/Bangkok' }),
    user, role, action, detail, level || 'info'
  ]);
  // Keep max 2000 rows
  if (sh.getLastRow() > 2001) sh.deleteRow(2);
}

// ══════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════
function buildRowData(headers, obj) {
  return headers.map(h => {
    if (h === 'indicator_id')   return obj.indicator_id || '';
    if (h === 'section_id')     return obj.section_id || '';
    if (h === 'section_name')   return obj.section_name || '';
    if (h === 'ยุทธศาสตร์_no') return obj['ยุทธศาสตร์_no'] || '';
    if (h === 'ยุทธศาสตร์_name') return obj['ยุทธศาสตร์_name'] || '';
    if (h === 'แผนงาน_no')     return obj['แผนงาน_no'] || '';
    if (h === 'แผนงาน_name')   return obj['แผนงาน_name'] || '';
    if (h === 'ลำดับที่')       return obj['ลำดับที่'] || '';
    if (h === 'ตัวชี้วัด')     return obj.ตัวชี้วัด || '';
    if (h === 'หน่วยงานหลัก')  return obj.หน่วยงานหลัก || '';
    if (h === 'รายงาน')         return obj.รายงาน || '';
    if (h === 'เป้าหมาย')      return obj.เป้าหมาย || '';
    if (h === 'หน่วย')          return obj.หน่วย || '';
    if (h === 'ผู้รับผิดชอบ')  return obj.ผู้รับผิดชอบ || '';
    if (h === 'ha_link_id')     return obj.ha_link_id || '';
    if (h === 'strat_link_id')  return obj.strat_link_id || '';
    if (h === 'lastUpdated')    return obj.lastUpdated || '';
    if (h === 'updatedBy')      return obj.updatedBy || '';
    // Monthly values: format = "2569_ต.ค.68"
    const m = h.match(/^(\d{4})_(.+)$/);
    if (m && m[2] !== 'สถานะ') {
      const fy = m[1], month = m[2];
      const months = fyMonths(fy);
      const idx = months.indexOf(month);
      if (idx >= 0) return (obj.values?.[fy] || [])[idx] || '';
    }
    return '';
  });
}

function sheetToObjects(sh) {
  const data = sh.getDataRange().getValues();
  if (data.length <= 1) return [];
  const headers = data[0];
  return data.slice(1).filter(r => r[0]).map(row => {
    const obj = {};
    headers.forEach((h, i) => { obj[h] = row[i]; });
    return obj;
  });
}

function computeStatus(value, target) {
  if (value === '' || value === null || value === undefined) return '⏳ รอข้อมูล';
  const vs = String(value).toLowerCase().trim();
  if (vs.includes('ผ่าน') && !vs.includes('ไม่')) return '✅ ผ่าน';
  if (vs.includes('ไม่ผ่าน')) return '❌ ไม่ผ่าน';
  const nv = parseFloat(vs);
  if (isNaN(nv)) return '— N/A';
  const t = String(target || '');
  const nt = parseFloat(t.replace(/[^0-9.]/g, ''));
  if (isNaN(nt)) return '— N/A';
  if (t.match(/^[>≥]/) || t.includes('>=')) return nv >= nt ? '✅ ผ่าน' : '❌ ไม่ผ่าน';
  if (t.match(/^[<≤]/) || t.includes('<=')) return nv <= nt ? '✅ ผ่าน' : '❌ ไม่ผ่าน';
  return '— N/A';
}

function colorValueCells(sh, rowNum, headers, values, target) {
  FYS.forEach(fy => {
    const months = fyMonths(fy);
    months.forEach((m, mi) => {
      const col = headers.indexOf(`${fy}_${m}`) + 1;
      if (col <= 0) return;
      const v = (values?.[fy] || [])[mi];
      if (v === '' || v === undefined) return;
      const s = computeStatus(v, target);
      const cell = sh.getRange(rowNum, col);
      if (s === '✅ ผ่าน')      cell.setBackground('#e6f4ea').setFontColor('#1a7f37');
      else if (s === '❌ ไม่ผ่าน') cell.setBackground('#fce8e6').setFontColor('#c5221f');
      else                         cell.setBackground(null).setFontColor(null);
    });
  });
}

// ══════════════════════════════════════════
//  MANUAL TRIGGER — Setup all sheets
//  (Run once from Apps Script Editor)
// ══════════════════════════════════════════
function runSetup() {
  setupSheets();
  Logger.log('✅ Setup complete!');
}

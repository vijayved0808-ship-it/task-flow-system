import { useState, useEffect, useRef } from "react";

// ─── MOCK DATA ────────────────────────────────────────────────────────────────
const MOCK = {
  overview: {
    totalTasks: 284,
    openTasks: 97,
    completedTasks: 163,
    overdueTasks: 24,
    teamProductivity: 78.4,
  },
  employees: [
    { id: 1, name: "Priya Sharma", role: "Field Executive", phone: "+91 98765 43210", dept: "Sales", apix: 91.2, rank: 1, tasksCompleted: 47, tasksTotal: 51, status: "online", avatar: "PS", trend: "up" },
    { id: 2, name: "Rohan Mehta", role: "Sales Executive", phone: "+91 87654 32109", dept: "Sales", apix: 84.7, rank: 2, tasksCompleted: 38, tasksTotal: 44, status: "online", avatar: "RM", trend: "up" },
    { id: 3, name: "Anita Joshi", role: "Lab Coordinator", phone: "+91 76543 21098", dept: "Operations", apix: 79.3, rank: 3, tasksCompleted: 29, tasksTotal: 35, status: "away", avatar: "AJ", trend: "stable" },
    { id: 4, name: "Kiran Patel", role: "Collection Agent", phone: "+91 65432 10987", dept: "Finance", apix: 71.8, rank: 4, tasksCompleted: 24, tasksTotal: 30, status: "online", avatar: "KP", trend: "down" },
    { id: 5, name: "Deepak Singh", role: "Marketing Exec", phone: "+91 54321 09876", dept: "Marketing", apix: 65.4, rank: 5, tasksCompleted: 18, tasksTotal: 26, status: "offline", avatar: "DS", trend: "down" },
    { id: 6, name: "Meera Gupta", role: "Receptionist", phone: "+91 43210 98765", dept: "Admin", apix: 58.2, rank: 6, tasksCompleted: 15, tasksTotal: 22, status: "online", avatar: "MG", trend: "up" },
  ],
  tasks: [
    { id: "T001", title: "Visit Dr. Patel Clinic — Navrangpura", assignee: "Priya Sharma", priority: "high", status: "in_progress", due: "Today 5PM", points: 50, dept: "Sales", updates: 3, lastUpdate: "8 mins ago" },
    { id: "T002", title: "Collect samples from Sterling Hospital", assignee: "Anita Joshi", priority: "critical", status: "waiting", due: "Today 3PM", points: 80, dept: "Operations", updates: 1, lastUpdate: "2 hrs ago" },
    { id: "T003", title: "Submit monthly report to CFO", assignee: "Kiran Patel", priority: "high", status: "overdue", due: "Yesterday", points: 60, dept: "Finance", updates: 0, lastUpdate: "Never" },
    { id: "T004", title: "WhatsApp campaign for cardiology package", assignee: "Deepak Singh", priority: "medium", status: "assigned", due: "Tomorrow", points: 40, dept: "Marketing", updates: 0, lastUpdate: "Just assigned" },
    { id: "T005", title: "Doctor visits — 15 GPs in Satellite", assignee: "Rohan Mehta", priority: "high", status: "completed", due: "Today 6PM", points: 70, dept: "Sales", updates: 5, lastUpdate: "30 mins ago" },
    { id: "T006", title: "Train new phlebotomist — Maninagar branch", assignee: "Anita Joshi", priority: "medium", status: "verified", due: "Jun 1", points: 45, dept: "Operations", updates: 4, lastUpdate: "1 hr ago" },
    { id: "T007", title: "Follow up with Zydus Hospital BD team", assignee: "Rohan Mehta", priority: "critical", status: "escalated", due: "Today 2PM", points: 90, dept: "Sales", updates: 2, lastUpdate: "45 mins ago" },
    { id: "T008", title: "Equipment maintenance check — Chandkheda", assignee: "Meera Gupta", priority: "low", status: "in_progress", due: "Jun 3", points: 30, dept: "Admin", updates: 1, lastUpdate: "3 hrs ago" },
  ],
  aiInsights: [
    { type: "alert", icon: "⚠️", title: "Deepak Singh inactive", desc: "No task activity in 18 hours. 2 tasks overdue.", action: "Send nudge" },
    { type: "risk", icon: "🚨", title: "T002 at risk of missing SLA", desc: "Sterling Hospital samples task overdue by 2 hours. Client satisfaction risk.", action: "Escalate" },
    { type: "star", icon: "🏆", title: "Priya Sharma top performer", desc: "91.2 APIX this week. 94% on-time completion. Consider recognition.", action: "Send praise" },
    { type: "insight", icon: "📊", title: "Sales team 14% below target", desc: "Doctor visits down vs last week. Tuesday mornings show low activity.", action: "View report" },
  ],
  weeklyTrend: [62, 71, 68, 79, 74, 82, 78],
  apixHistory: {
    "Priya Sharma": [82, 85, 88, 87, 90, 91, 91],
    "Rohan Mehta": [78, 80, 82, 84, 83, 85, 85],
    "Anita Joshi": [72, 74, 76, 78, 77, 79, 79],
  },
};

const STATUS_CONFIG = {
  assigned: { label: "Assigned", color: "#64748b", bg: "rgba(100,116,139,0.12)" },
  accepted: { label: "Accepted", color: "#3b82f6", bg: "rgba(59,130,246,0.12)" },
  in_progress: { label: "In Progress", color: "#f59e0b", bg: "rgba(245,158,11,0.12)" },
  waiting: { label: "Waiting", color: "#8b5cf6", bg: "rgba(139,92,246,0.12)" },
  completed: { label: "Completed", color: "#10b981", bg: "rgba(16,185,129,0.12)" },
  verified: { label: "Verified", color: "#06b6d4", bg: "rgba(6,182,212,0.12)" },
  rejected: { label: "Rejected", color: "#ef4444", bg: "rgba(239,68,68,0.12)" },
  escalated: { label: "Escalated", color: "#f97316", bg: "rgba(249,115,22,0.12)" },
  overdue: { label: "Overdue", color: "#dc2626", bg: "rgba(220,38,38,0.12)" },
};

const PRIORITY_CONFIG = {
  low: { color: "#64748b", label: "Low" },
  medium: { color: "#3b82f6", label: "Med" },
  high: { color: "#f59e0b", label: "High" },
  critical: { color: "#ef4444", label: "Crit" },
};

const APIX_BAND = (score) => {
  if (score >= 90) return { label: "Elite", color: "#f59e0b" };
  if (score >= 75) return { label: "High", color: "#10b981" };
  if (score >= 60) return { label: "On Track", color: "#3b82f6" };
  if (score >= 45) return { label: "Needs Attn", color: "#f97316" };
  return { label: "At Risk", color: "#ef4444" };
};

// ─── MINI CHART ───────────────────────────────────────────────────────────────
const SparkLine = ({ data, color = "#10b981", height = 40 }) => {
  const max = Math.max(...data);
  const min = Math.min(...data);
  const w = 120, h = height;
  const pts = data.map((v, i) => {
    const x = (i / (data.length - 1)) * w;
    const y = h - ((v - min) / (max - min || 1)) * (h - 6) - 3;
    return `${x},${y}`;
  }).join(" ");
  return (
    <svg width={w} height={h} style={{ overflow: "visible" }}>
      <defs>
        <linearGradient id={`sg-${color.replace("#","")}`} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor={color} stopOpacity="0.3" />
          <stop offset="100%" stopColor={color} stopOpacity="0" />
        </linearGradient>
      </defs>
      <polygon
        points={`0,${h} ${pts.split(" ").map((p,i) => p).join(" ")} ${w},${h}`}
        fill={`url(#sg-${color.replace("#","")})`}
      />
      <polyline points={pts} fill="none" stroke={color} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
      {data.map((v, i) => {
        const x = (i / (data.length - 1)) * w;
        const y = h - ((v - min) / (max - min || 1)) * (h - 6) - 3;
        return i === data.length - 1 ? <circle key={i} cx={x} cy={y} r="3" fill={color} /> : null;
      })}
    </svg>
  );
};

// ─── APIX RING ────────────────────────────────────────────────────────────────
const APIMRing = ({ score, size = 64 }) => {
  const band = APIX_BAND(score);
  const r = size / 2 - 5;
  const circ = 2 * Math.PI * r;
  const offset = circ - (score / 100) * circ;
  return (
    <div style={{ position: "relative", width: size, height: size, flexShrink: 0 }}>
      <svg width={size} height={size} style={{ transform: "rotate(-90deg)" }}>
        <circle cx={size/2} cy={size/2} r={r} fill="none" stroke="rgba(255,255,255,0.08)" strokeWidth="4" />
        <circle cx={size/2} cy={size/2} r={r} fill="none" stroke={band.color} strokeWidth="4"
          strokeDasharray={circ} strokeDashoffset={offset} strokeLinecap="round"
          style={{ transition: "stroke-dashoffset 1s ease" }} />
      </svg>
      <div style={{ position: "absolute", inset: 0, display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center" }}>
        <span style={{ fontSize: size > 56 ? 14 : 11, fontWeight: 700, color: band.color, fontFamily: "'DM Mono', monospace" }}>{score}</span>
      </div>
    </div>
  );
};

// ─── BAR CHART ────────────────────────────────────────────────────────────────
const BarChart = ({ data, labels, color = "#3b82f6" }) => {
  const max = Math.max(...data);
  const days = ["M", "T", "W", "T", "F", "S", "S"];
  return (
    <div style={{ display: "flex", alignItems: "flex-end", gap: 6, height: 60 }}>
      {data.map((v, i) => (
        <div key={i} style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 4, flex: 1 }}>
          <div style={{
            width: "100%", background: i === data.length - 1 ? color : `${color}55`,
            borderRadius: "3px 3px 0 0",
            height: `${(v / max) * 52}px`,
            transition: "height 0.6s ease",
            minHeight: 4,
          }} />
          <span style={{ fontSize: 9, color: "rgba(255,255,255,0.35)", fontFamily: "'DM Mono', monospace" }}>{days[i]}</span>
        </div>
      ))}
    </div>
  );
};

// ─── MAIN DASHBOARD ───────────────────────────────────────────────────────────
export default function TaskFlowDashboard() {
  const [activeView, setActiveView] = useState("overview");
  const [taskFilter, setTaskFilter] = useState("all");
  const [selectedEmployee, setSelectedEmployee] = useState(null);
  const [showNewTask, setShowNewTask] = useState(false);
  const [newTaskData, setNewTaskData] = useState({ title: "", assignee: "", priority: "medium", due: "", points: 50 });
  const [liveTime, setLiveTime] = useState(new Date());
  const [animateIn, setAnimateIn] = useState(false);
  const [notification, setNotification] = useState(null);
  const [waSimulator, setWaSimulator] = useState(false);
  const [waMessages, setWaMessages] = useState([
    { from: "system", text: "Task assigned: Visit Dr. Patel Clinic", time: "9:00 AM" },
    { from: "priya", text: "START", time: "9:15 AM" },
    { from: "system", text: "✅ Task started! Update us with UPDATE command.", time: "9:15 AM" },
    { from: "priya", text: "UPDATE\nVisited 5 doctors, all positive. Moving to Vastrapur area.", time: "11:30 AM" },
    { from: "system", text: "📝 Update logged! Keep going 💪", time: "11:30 AM" },
  ]);
  const [waInput, setWaInput] = useState("");

  useEffect(() => {
    const t = setInterval(() => setLiveTime(new Date()), 1000);
    return () => clearInterval(t);
  }, []);

  useEffect(() => {
    setAnimateIn(false);
    setTimeout(() => setAnimateIn(true), 50);
  }, [activeView]);

  const showNotif = (msg, type = "success") => {
    setNotification({ msg, type });
    setTimeout(() => setNotification(null), 3000);
  };

  const handleWaSend = () => {
    if (!waInput.trim()) return;
    const msgs = [...waMessages, { from: "priya", text: waInput, time: new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }) }];
    setWaMessages(msgs);
    setWaInput("");
    const cmd = waInput.trim().toUpperCase().split("\n")[0];
    setTimeout(() => {
      let reply = "Command received. Processing...";
      if (cmd === "COMPLETE") reply = "🎉 Task marked complete!\nManager has been notified.\n⭐ +50 points added.\nYour APIX today: 91.2";
      else if (cmd === "DELAY") reply = "⏰ Delay noted. Reason logged.\nManager has been informed.";
      else if (cmd === "ESCALATE") reply = "🚨 Task escalated to your manager.\nThey've been notified immediately.";
      else if (cmd === "SCORE") reply = "📊 Your APIX Score: 91.2 🏆 Elite\nThis week: +3.4 improvement";
      else if (cmd === "HELP") reply = "📋 Commands:\nSTART – Begin task\nUPDATE – Send progress\nCOMPLETE – Mark done\nDELAY – Report delay\nESCALATE – Flag issue\nSCORE – Your score";
      else if (cmd === "UPDATE") reply = "📝 Update logged! AI analyzing quality...\n\nKeep it up! 3 tasks remaining today.";
      setWaMessages(prev => [...prev, { from: "system", text: reply, time: new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }) }]);
    }, 800);
  };

  const filteredTasks = taskFilter === "all" ? MOCK.tasks : MOCK.tasks.filter(t => t.status === taskFilter);

  const NAV = [
    { id: "overview", icon: "⊞", label: "Overview" },
    { id: "tasks", icon: "✓", label: "Tasks" },
    { id: "employees", icon: "◉", label: "People" },
    { id: "ai", icon: "✦", label: "AI Insights" },
    { id: "reports", icon: "≡", label: "Reports" },
  ];

  const css = `
    @import url('https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&family=Inter:wght@300;400;500;600&display=swap');
    * { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg: #080c14;
      --surface: #0e1420;
      --surface2: #131928;
      --border: rgba(255,255,255,0.06);
      --border2: rgba(255,255,255,0.10);
      --text: #e2e8f0;
      --text2: rgba(226,232,240,0.6);
      --text3: rgba(226,232,240,0.35);
      --accent: #22d3ee;
      --accent2: #10b981;
      --accent3: #f59e0b;
      --danger: #ef4444;
    }
    body { background: var(--bg); color: var(--text); font-family: 'Inter', sans-serif; }
    ::-webkit-scrollbar { width: 4px; height: 4px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 2px; }
    .fade-in { animation: fadeIn 0.3s ease; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    .pulse { animation: pulse 2s infinite; }
    @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }
    .slide-up { animation: slideUp 0.4s cubic-bezier(0.16,1,0.3,1); }
    @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    .tag {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 2px 8px; border-radius: 4px; font-size: 11px;
      font-family: 'DM Mono', monospace; font-weight: 500; white-space: nowrap;
    }
    .btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 7px 14px; border-radius: 8px; font-size: 13px; font-weight: 500;
      cursor: pointer; border: none; transition: all 0.15s; font-family: 'Inter', sans-serif;
    }
    .btn-primary { background: var(--accent); color: #080c14; }
    .btn-primary:hover { background: #67e8f9; transform: translateY(-1px); }
    .btn-ghost { background: rgba(255,255,255,0.06); color: var(--text); border: 1px solid var(--border2); }
    .btn-ghost:hover { background: rgba(255,255,255,0.10); }
    .btn-danger { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }
    input, select, textarea {
      background: rgba(255,255,255,0.05); border: 1px solid var(--border2);
      border-radius: 8px; color: var(--text); font-family: 'Inter', sans-serif;
      font-size: 13px; padding: 8px 12px; outline: none; width: 100%;
    }
    input:focus, select:focus, textarea:focus { border-color: var(--accent); }
    select option { background: #1a2035; }
  `;

  return (
    <>
      <style dangerouslySetInnerHTML={{ __html: css }} />
      <div style={{ display: "flex", height: "100vh", overflow: "hidden", fontFamily: "'Inter', sans-serif" }}>

        {/* ── SIDEBAR ── */}
        <aside style={{
          width: 220, background: "var(--surface)", borderRight: "1px solid var(--border)",
          display: "flex", flexDirection: "column", flexShrink: 0,
        }}>
          {/* Logo */}
          <div style={{ padding: "20px 20px 16px", borderBottom: "1px solid var(--border)" }}>
            <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
              <div style={{
                width: 34, height: 34, borderRadius: 10,
                background: "linear-gradient(135deg, #22d3ee, #0891b2)",
                display: "flex", alignItems: "center", justifyContent: "center",
                fontSize: 16, fontWeight: 800, color: "#080c14", fontFamily: "'Syne', sans-serif",
              }}>TF</div>
              <div>
                <div style={{ fontFamily: "'Syne', sans-serif", fontWeight: 800, fontSize: 15, letterSpacing: "-0.3px" }}>TaskFlow</div>
                <div style={{ fontSize: 10, color: "var(--accent)", fontFamily: "'DM Mono', monospace" }}>WhatsApp OS</div>
              </div>
            </div>
          </div>

          {/* Tenant badge */}
          <div style={{ margin: "12px 12px 4px", background: "rgba(34,211,238,0.08)", border: "1px solid rgba(34,211,238,0.15)", borderRadius: 8, padding: "8px 12px" }}>
            <div style={{ fontSize: 10, color: "var(--accent)", fontFamily: "'DM Mono', monospace", marginBottom: 2 }}>TENANT</div>
            <div style={{ fontSize: 12, fontWeight: 600, color: "var(--text)" }}>UIC Group</div>
            <div style={{ fontSize: 10, color: "var(--text3)" }}>Ahmedabad · 26 branches</div>
          </div>

          {/* Nav */}
          <nav style={{ padding: "8px 8px", flex: 1 }}>
            {NAV.map(item => (
              <button key={item.id} onClick={() => setActiveView(item.id)} style={{
                width: "100%", display: "flex", alignItems: "center", gap: 10, padding: "9px 12px",
                borderRadius: 8, border: "none", cursor: "pointer", marginBottom: 2,
                background: activeView === item.id ? "rgba(34,211,238,0.1)" : "transparent",
                color: activeView === item.id ? "var(--accent)" : "var(--text2)",
                fontSize: 13, fontWeight: activeView === item.id ? 600 : 400, transition: "all 0.15s",
                textAlign: "left", fontFamily: "'Inter', sans-serif",
              }}>
                <span style={{ fontSize: 14, width: 18, textAlign: "center" }}>{item.icon}</span>
                {item.label}
                {item.id === "ai" && <span style={{ marginLeft: "auto", background: "var(--danger)", color: "#fff", borderRadius: 4, fontSize: 9, padding: "1px 5px", fontFamily: "'DM Mono', monospace" }}>4</span>}
              </button>
            ))}
          </nav>

          {/* WA Simulator toggle */}
          <div style={{ padding: "12px", borderTop: "1px solid var(--border)" }}>
            <button onClick={() => setWaSimulator(!waSimulator)} className="btn btn-ghost" style={{ width: "100%", justifyContent: "center", fontSize: 12 }}>
              <span>💬</span> WA Simulator
            </button>
          </div>

          {/* User */}
          <div style={{ padding: "12px", borderTop: "1px solid var(--border)", display: "flex", alignItems: "center", gap: 10 }}>
            <div style={{ width: 32, height: 32, borderRadius: "50%", background: "linear-gradient(135deg, #10b981, #059669)", display: "flex", alignItems: "center", justifyContent: "center", fontSize: 12, fontWeight: 700, color: "#fff", flexShrink: 0 }}>AG</div>
            <div style={{ minWidth: 0 }}>
              <div style={{ fontSize: 12, fontWeight: 600, color: "var(--text)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>Dr. Amit Gupta</div>
              <div style={{ fontSize: 10, color: "var(--text3)" }}>Admin</div>
            </div>
          </div>
        </aside>

        {/* ── MAIN CONTENT ── */}
        <main style={{ flex: 1, overflow: "auto", background: "var(--bg)" }}>
          {/* Top bar */}
          <header style={{
            padding: "14px 24px", borderBottom: "1px solid var(--border)",
            display: "flex", alignItems: "center", justifyContent: "space-between",
            position: "sticky", top: 0, background: "rgba(8,12,20,0.92)", backdropFilter: "blur(12px)", zIndex: 10,
          }}>
            <div>
              <h1 style={{ fontFamily: "'Syne', sans-serif", fontSize: 18, fontWeight: 700, letterSpacing: "-0.3px" }}>
                {activeView === "overview" && "Dashboard Overview"}
                {activeView === "tasks" && "Task Management"}
                {activeView === "employees" && "People & Performance"}
                {activeView === "ai" && "AI Insights & Reports"}
                {activeView === "reports" && "Analytics Reports"}
              </h1>
              <div style={{ fontSize: 11, color: "var(--text3)", fontFamily: "'DM Mono', monospace", marginTop: 2 }}>
                {liveTime.toLocaleDateString("en-IN", { weekday: "short", day: "2-digit", month: "short", year: "numeric" })} · {liveTime.toLocaleTimeString()}
              </div>
            </div>
            <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
              <div style={{ display: "flex", alignItems: "center", gap: 6, background: "rgba(16,185,129,0.1)", border: "1px solid rgba(16,185,129,0.2)", borderRadius: 8, padding: "5px 10px" }}>
                <span style={{ width: 6, height: 6, borderRadius: "50%", background: "#10b981", display: "block" }} className="pulse" />
                <span style={{ fontSize: 11, color: "#10b981", fontFamily: "'DM Mono', monospace" }}>4 online</span>
              </div>
              <button className="btn btn-primary" onClick={() => setShowNewTask(true)}>
                <span>+</span> New Task
              </button>
            </div>
          </header>

          {/* Content */}
          <div className={animateIn ? "fade-in" : ""} style={{ padding: 24 }}>

            {/* ─── OVERVIEW ─── */}
            {activeView === "overview" && (
              <div style={{ display: "flex", flexDirection: "column", gap: 20 }}>
                {/* KPI Cards */}
                <div style={{ display: "grid", gridTemplateColumns: "repeat(5, 1fr)", gap: 12 }}>
                  {[
                    { label: "Total Tasks", value: MOCK.overview.totalTasks, icon: "✓", color: "#22d3ee", sub: "All time" },
                    { label: "Open Tasks", value: MOCK.overview.openTasks, icon: "◐", color: "#3b82f6", sub: "Active now" },
                    { label: "Completed", value: MOCK.overview.completedTasks, icon: "●", color: "#10b981", sub: "+12 today" },
                    { label: "Overdue", value: MOCK.overview.overdueTasks, icon: "!", color: "#ef4444", sub: "Needs action" },
                    { label: "Team APIX", value: `${MOCK.overview.teamProductivity}%`, icon: "✦", color: "#f59e0b", sub: "+2.3 vs last wk" },
                  ].map((kpi, i) => (
                    <div key={i} style={{
                      background: "var(--surface)", border: "1px solid var(--border)", borderRadius: 12,
                      padding: "16px", position: "relative", overflow: "hidden",
                    }}>
                      <div style={{ position: "absolute", top: 12, right: 14, width: 32, height: 32, borderRadius: 8, background: `${kpi.color}15`, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 14, color: kpi.color }}>{kpi.icon}</div>
                      <div style={{ fontSize: 10, color: "var(--text3)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase", letterSpacing: 1 }}>{kpi.label}</div>
                      <div style={{ fontSize: 28, fontWeight: 800, color: kpi.color, fontFamily: "'Syne', sans-serif", marginTop: 6, letterSpacing: "-1px" }}>{kpi.value}</div>
                      <div style={{ fontSize: 10, color: "var(--text3)", marginTop: 4 }}>{kpi.sub}</div>
                    </div>
                  ))}
                </div>

                {/* Charts row */}
                <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 16 }}>
                  {/* Weekly trend */}
                  <div style={{ background: "var(--surface)", border: "1px solid var(--border)", borderRadius: 12, padding: 20 }}>
                    <div style={{ fontSize: 11, color: "var(--text3)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase", letterSpacing: 1, marginBottom: 12 }}>Weekly Productivity</div>
                    <div style={{ marginBottom: 8 }}>
                      <span style={{ fontSize: 24, fontWeight: 800, color: "var(--accent)", fontFamily: "'Syne', sans-serif" }}>78.4%</span>
                      <span style={{ fontSize: 11, color: "#10b981", marginLeft: 6 }}>↑ +4.2</span>
                    </div>
                    <BarChart data={MOCK.weeklyTrend} color="#22d3ee" />
                  </div>

                  {/* Top performers */}
                  <div style={{ background: "var(--surface)", border: "1px solid var(--border)", borderRadius: 12, padding: 20 }}>
                    <div style={{ fontSize: 11, color: "var(--text3)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase", letterSpacing: 1, marginBottom: 14 }}>Top Performers</div>
                    {MOCK.employees.slice(0, 4).map((emp, i) => {
                      const band = APIX_BAND(emp.apix);
                      return (
                        <div key={i} style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 10 }}>
                          <span style={{ fontSize: 10, color: "var(--text3)", fontFamily: "'DM Mono', monospace", width: 14 }}>#{i + 1}</span>
                          <div style={{ width: 26, height: 26, borderRadius: "50%", background: `linear-gradient(135deg, ${band.color}66, ${band.color}33)`, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 9, fontWeight: 700, color: band.color }}>{emp.avatar}</div>
                          <div style={{ flex: 1, minWidth: 0 }}>
                            <div style={{ fontSize: 12, fontWeight: 600, color: "var(--text)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{emp.name.split(" ")[0]}</div>
                            <div style={{ height: 3, background: "rgba(255,255,255,0.06)", borderRadius: 2, marginTop: 3 }}>
                              <div style={{ height: "100%", width: `${emp.apix}%`, background: band.color, borderRadius: 2 }} />
                            </div>
                          </div>
                          <span style={{ fontSize: 11, fontWeight: 700, color: band.color, fontFamily: "'DM Mono', monospace" }}>{emp.apix}</span>
                        </div>
                      );
                    })}
                  </div>

                  {/* Task status distribution */}
                  <div style={{ background: "var(--surface)", border: "1px solid var(--border)", borderRadius: 12, padding: 20 }}>
                    <div style={{ fontSize: 11, color: "var(--text3)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase", letterSpacing: 1, marginBottom: 14 }}>Task Status Breakdown</div>
                    {[
                      { label: "In Progress", count: 34, color: "#f59e0b", pct: 35 },
                      { label: "Completed", count: 163, color: "#10b981", pct: 57 },
                      { label: "Overdue", count: 24, color: "#ef4444", pct: 25 },
                      { label: "Assigned", count: 63, color: "#64748b", pct: 22 },
                    ].map((s, i) => (
                      <div key={i} style={{ marginBottom: 10 }}>
                        <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 3 }}>
                          <span style={{ fontSize: 11, color: "var(--text2)" }}>{s.label}</span>
                          <span style={{ fontSize: 11, fontFamily: "'DM Mono', monospace", color: s.color }}>{s.count}</span>
                        </div>
                        <div style={{ height: 4, background: "rgba(255,255,255,0.06)", borderRadius: 2 }}>
                          <div style={{ height: "100%", width: `${s.pct}%`, background: s.color, borderRadius: 2, transition: "width 1s ease" }} />
                        </div>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Recent activity */}
                <div style={{ background: "var(--surface)", border: "1px solid var(--border)", borderRadius: 12, padding: 20 }}>
                  <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 16 }}>
                    <div style={{ fontSize: 11, color: "var(--text3)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase", letterSpacing: 1 }}>Recent WhatsApp Activity</div>
                    <span style={{ fontSize: 10, color: "var(--accent)", cursor: "pointer" }}>View all →</span>
                  </div>
                  <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
                    {[
                      { name: "Priya Sharma", action: "sent COMPLETE for", task: "Visit Dr. Patel Clinic", time: "8 mins ago", color: "#10b981" },
                      { name: "Rohan Mehta", action: "sent UPDATE for", task: "15 GPs in Satellite", time: "31 mins ago", color: "#3b82f6" },
                      { name: "Anita Joshi", action: "sent DELAY for", task: "Sterling Hospital samples", time: "2 hrs ago", color: "#f59e0b" },
                      { name: "Kiran Patel", action: "has not responded to", task: "Monthly CFO report", time: "Since yesterday", color: "#ef4444" },
                    ].map((act, i) => (
                      <div key={i} style={{ display: "flex", alignItems: "center", gap: 12, padding: "10px 14px", background: "rgba(255,255,255,0.02)", borderRadius: 8, border: `1px solid var(--border)` }}>
                        <div style={{ width: 30, height: 30, borderRadius: "50%", background: `${act.color}22`, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 10, fontWeight: 700, color: act.color, flexShrink: 0 }}>
                          {act.name.split(" ").map(n => n[0]).join("")}
                        </div>
                        <div style={{ flex: 1 }}>
                          <span style={{ fontSize: 12, fontWeight: 600, color: "var(--text)" }}>{act.name}</span>
                          <span style={{ fontSize: 12, color: "var(--text3)" }}> {act.action} </span>
                          <span style={{ fontSize: 12, color: act.color }}>{act.task}</span>
                        </div>
                        <span style={{ fontSize: 10, color: "var(--text3)", fontFamily: "'DM Mono', monospace", whiteSpace: "nowrap" }}>{act.time}</span>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            )}

            {/* ─── TASKS ─── */}
            {activeView === "tasks" && (
              <div style={{ display: "flex", flexDirection: "column", gap: 16 }}>
                {/* Filters */}
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap", alignItems: "center" }}>
                  {["all", "in_progress", "assigned", "completed", "overdue", "escalated"].map(f => (
                    <button key={f} className="tag" onClick={() => setTaskFilter(f)} style={{
                      cursor: "pointer", border: `1px solid ${taskFilter === f ? "var(--accent)" : "var(--border2)"}`,
                      background: taskFilter === f ? "rgba(34,211,238,0.1)" : "rgba(255,255,255,0.03)",
                      color: taskFilter === f ? "var(--accent)" : "var(--text2)", padding: "6px 14px", fontSize: 12,
                    }}>
                      {f === "all" ? "All" : STATUS_CONFIG[f]?.label || f}
                      {f === "all" && <span style={{ marginLeft: 4, color: "var(--text3)" }}>{MOCK.tasks.length}</span>}
                    </button>
                  ))}
                </div>

                {/* Task cards */}
                <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
                  {filteredTasks.map((task, i) => {
                    const sc = STATUS_CONFIG[task.status] || {};
                    const pc = PRIORITY_CONFIG[task.priority] || {};
                    return (
                      <div key={task.id} style={{
                        background: "var(--surface)", border: "1px solid var(--border)", borderRadius: 12,
                        padding: "14px 18px", display: "flex", alignItems: "center", gap: 16,
                        cursor: "pointer", transition: "border-color 0.15s",
                      }}
                        onMouseEnter={e => e.currentTarget.style.borderColor = "rgba(255,255,255,0.15)"}
                        onMouseLeave={e => e.currentTarget.style.borderColor = "var(--border)"}
                      >
                        <span className="tag" style={{ background: sc.bg, color: sc.color, border: `1px solid ${sc.color}40`, width: 88, justifyContent: "center", flexShrink: 0 }}>
                          {sc.label}
                        </span>
                        <div style={{ flex: 1, minWidth: 0 }}>
                          <div style={{ fontSize: 13, fontWeight: 600, color: "var(--text)", marginBottom: 2, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{task.title}</div>
                          <div style={{ fontSize: 11, color: "var(--text3)" }}>{task.assignee} · {task.dept}</div>
                        </div>
                        <span className="tag" style={{ background: `${pc.color}15`, color: pc.color, border: `1px solid ${pc.color}30`, flexShrink: 0 }}>
                          {pc.label}
                        </span>
                        <div style={{ textAlign: "right", flexShrink: 0 }}>
                          <div style={{ fontSize: 11, color: task.status === "overdue" ? "#ef4444" : "var(--text3)", fontFamily: "'DM Mono', monospace" }}>{task.due}</div>
                          <div style={{ fontSize: 10, color: "var(--text3)", marginTop: 1 }}>{task.updates} updates · {task.lastUpdate}</div>
                        </div>
                        <div style={{ textAlign: "right", flexShrink: 0 }}>
                          <div style={{ fontSize: 12, color: "#f59e0b", fontFamily: "'DM Mono', monospace" }}>⭐ {task.points}</div>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}

            {/* ─── EMPLOYEES ─── */}
            {activeView === "employees" && (
              <div style={{ display: "flex", flexDirection: "column", gap: 16 }}>
                <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: 14 }}>
                  {MOCK.employees.map((emp, i) => {
                    const band = APIX_BAND(emp.apix);
                    const isSelected = selectedEmployee?.id === emp.id;
                    return (
                      <div key={emp.id} onClick={() => setSelectedEmployee(isSelected ? null : emp)} style={{
                        background: "var(--surface)", border: `1px solid ${isSelected ? "var(--accent)" : "var(--border)"}`,
                        borderRadius: 14, padding: 18, cursor: "pointer", transition: "all 0.2s",
                        transform: isSelected ? "translateY(-2px)" : "none",
                      }}>
                        <div style={{ display: "flex", gap: 12, alignItems: "flex-start" }}>
                          <div style={{ position: "relative", flexShrink: 0 }}>
                            <div style={{ width: 44, height: 44, borderRadius: "50%", background: `linear-gradient(135deg, ${band.color}44, ${band.color}22)`, border: `2px solid ${band.color}55`, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 14, fontWeight: 700, color: band.color }}>{emp.avatar}</div>
                            <div style={{
                              position: "absolute", bottom: -1, right: -1,
                              width: 10, height: 10, borderRadius: "50%", border: "2px solid var(--surface)",
                              background: emp.status === "online" ? "#10b981" : emp.status === "away" ? "#f59e0b" : "#64748b",
                            }} />
                          </div>
                          <div style={{ flex: 1, minWidth: 0 }}>
                            <div style={{ fontSize: 13, fontWeight: 700, color: "var(--text)" }}>{emp.name}</div>
                            <div style={{ fontSize: 11, color: "var(--text3)", marginTop: 1 }}>{emp.role}</div>
                            <div style={{ fontSize: 10, color: "var(--text3)", marginTop: 2, fontFamily: "'DM Mono', monospace" }}>{emp.phone}</div>
                          </div>
                          <APIMRing score={emp.apix} size={54} />
                        </div>
                        <div style={{ marginTop: 14, display: "flex", gap: 6 }}>
                          <span className="tag" style={{ background: `${band.color}15`, color: band.color, border: `1px solid ${band.color}30` }}>
                            {band.label}
                          </span>
                          <span style={{ fontSize: 10, color: "var(--text3)", fontFamily: "'DM Mono', monospace", marginLeft: "auto", display: "flex", alignItems: "center" }}>
                            {emp.tasksCompleted}/{emp.tasksTotal} tasks
                          </span>
                          <span style={{ fontSize: 11, color: emp.trend === "up" ? "#10b981" : emp.trend === "down" ? "#ef4444" : "var(--text3)" }}>
                            {emp.trend === "up" ? "↑" : emp.trend === "down" ? "↓" : "→"}
                          </span>
                        </div>
                        {isSelected && (
                          <div className="slide-up" style={{ marginTop: 12, paddingTop: 12, borderTop: "1px solid var(--border)" }}>
                            <div style={{ fontSize: 10, color: "var(--text3)", fontFamily: "'DM Mono', monospace", marginBottom: 8 }}>APIX BREAKDOWN</div>
                            {[
                              { label: "Completion Rate", val: 91, weight: "30%" },
                              { label: "Timeliness", val: 88, weight: "25%" },
                              { label: "Update Quality", val: 85, weight: "20%" },
                              { label: "Consistency", val: 94, weight: "15%" },
                              { label: "Manager Rating", val: 90, weight: "10%" },
                            ].map((m, j) => (
                              <div key={j} style={{ marginBottom: 6 }}>
                                <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 2 }}>
                                  <span style={{ fontSize: 10, color: "var(--text2)" }}>{m.label}</span>
                                  <span style={{ fontSize: 10, fontFamily: "'DM Mono', monospace", color: band.color }}>{m.val}</span>
                                </div>
                                <div style={{ height: 3, background: "rgba(255,255,255,0.06)", borderRadius: 2 }}>
                                  <div style={{ height: "100%", width: `${m.val}%`, background: band.color, borderRadius: 2 }} />
                                </div>
                              </div>
                            ))}
                            <div style={{ marginTop: 10, display: "flex", gap: 6 }}>
                              <button className="btn btn-ghost" style={{ fontSize: 11, padding: "5px 10px" }} onClick={e => { e.stopPropagation(); showNotif(`WhatsApp message sent to ${emp.name}`); }}>💬 WA Message</button>
                              <button className="btn btn-ghost" style={{ fontSize: 11, padding: "5px 10px" }} onClick={e => { e.stopPropagation(); showNotif("Task assigned!"); }}>+ Assign Task</button>
                            </div>
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>
              </div>
            )}

            {/* ─── AI INSIGHTS ─── */}
            {activeView === "ai" && (
              <div style={{ display: "flex", flexDirection: "column", gap: 20 }}>
                {/* Alert cards */}
                <div style={{ display: "grid", gridTemplateColumns: "repeat(2, 1fr)", gap: 14 }}>
                  {MOCK.aiInsights.map((ins, i) => {
                    const colors = { alert: "#f59e0b", risk: "#ef4444", star: "#10b981", insight: "#3b82f6" };
                    const c = colors[ins.type];
                    return (
                      <div key={i} style={{
                        background: "var(--surface)", border: `1px solid ${c}22`,
                        borderLeft: `3px solid ${c}`, borderRadius: "0 12px 12px 0",
                        padding: 18, display: "flex", gap: 14,
                      }}>
                        <span style={{ fontSize: 22, flexShrink: 0 }}>{ins.icon}</span>
                        <div style={{ flex: 1 }}>
                          <div style={{ fontSize: 13, fontWeight: 700, color: "var(--text)", marginBottom: 4 }}>{ins.title}</div>
                          <div style={{ fontSize: 11, color: "var(--text3)", lineHeight: 1.5, marginBottom: 10 }}>{ins.desc}</div>
                          <button className="btn" onClick={() => showNotif(`Action taken: ${ins.action}`)} style={{ fontSize: 11, padding: "5px 12px", background: `${c}15`, color: c, border: `1px solid ${c}30` }}>{ins.action}</button>
                        </div>
                      </div>
                    );
                  })}
                </div>

                {/* APIX score breakdown */}
                <div style={{ background: "var(--surface)", border: "1px solid var(--border)", borderRadius: 12, padding: 20 }}>
                  <div style={{ fontSize: 11, color: "var(--text3)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase", letterSpacing: 1, marginBottom: 16 }}>APIX Score Formula & Engine</div>
                  <div style={{ display: "grid", gridTemplateColumns: "repeat(5, 1fr)", gap: 12 }}>
                    {[
                      { label: "Completion\nRate", weight: "30%", formula: "Done/Assigned × 100", color: "#22d3ee" },
                      { label: "Timeliness\nScore", weight: "25%", formula: "Avg per-task timing score", color: "#10b981" },
                      { label: "AI Quality\nScore", weight: "20%", formula: "Claude evaluates updates", color: "#a78bfa" },
                      { label: "Consistency\nScore", weight: "15%", formula: "Active days / working days", color: "#f59e0b" },
                      { label: "Manager\nRating", weight: "10%", formula: "Direct rating post-verify", color: "#f97316" },
                    ].map((c, i) => (
                      <div key={i} style={{ textAlign: "center", background: `${c.color}08`, border: `1px solid ${c.color}20`, borderRadius: 10, padding: 14 }}>
                        <div style={{ fontSize: 20, fontWeight: 800, color: c.color, fontFamily: "'Syne', sans-serif" }}>{c.weight}</div>
                        <div style={{ fontSize: 10, fontWeight: 600, color: "var(--text2)", marginTop: 4, whiteSpace: "pre-line", lineHeight: 1.4 }}>{c.label}</div>
                        <div style={{ fontSize: 9, color: "var(--text3)", marginTop: 6, lineHeight: 1.4 }}>{c.formula}</div>
                      </div>
                    ))}
                  </div>
                  <div style={{ marginTop: 16, padding: "12px 16px", background: "rgba(255,255,255,0.03)", borderRadius: 8, border: "1px solid var(--border)" }}>
                    <code style={{ fontSize: 12, color: "#a78bfa", fontFamily: "'DM Mono', monospace" }}>
                      APIX = (CR × 0.30) + (TS × 0.25) + (QS × 0.20) + (CS × 0.15) + (MR × 0.10)
                    </code>
                  </div>
                </div>

                {/* Daily report preview */}
                <div style={{ background: "var(--surface)", border: "1px solid var(--border)", borderRadius: 12, padding: 20 }}>
                  <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 16 }}>
                    <div style={{ fontSize: 11, color: "var(--text3)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase", letterSpacing: 1 }}>Today's AI Report · {new Date().toLocaleDateString("en-IN", { day: "2-digit", month: "short" })}</div>
                    <button className="btn btn-ghost" style={{ fontSize: 11 }} onClick={() => showNotif("Report sent via WhatsApp!")}>📤 Send to Team</button>
                  </div>
                  <div style={{ fontSize: 12, color: "var(--text2)", lineHeight: 2 }}>
                    <div><span style={{ color: "var(--accent)", fontFamily: "'DM Mono', monospace" }}>TASKS:</span> 12 assigned · 8 completed · 2 overdue · 1 escalated</div>
                    <div><span style={{ color: "#10b981", fontFamily: "'DM Mono', monospace" }}>TOP PERFORMER:</span> Priya Sharma (APIX 91.2) — completed all assigned tasks before deadline</div>
                    <div><span style={{ color: "#f59e0b", fontFamily: "'DM Mono', monospace" }}>AT RISK:</span> Deepak Singh (APIX 65.4) — 18 hours inactive, 2 tasks unresponded</div>
                    <div><span style={{ color: "#ef4444", fontFamily: "'DM Mono', monospace" }}>ACTION NEEDED:</span> Sterling Hospital sample collection overdue by 2 hrs. Escalate to Anita's manager.</div>
                    <div><span style={{ color: "#a78bfa", fontFamily: "'DM Mono', monospace" }}>AI SUGGESTION:</span> Schedule morning stand-up at 8AM via WhatsApp broadcast to improve response times.</div>
                  </div>
                </div>
              </div>
            )}

            {/* ─── REPORTS ─── */}
            {activeView === "reports" && (
              <div style={{ display: "flex", flexDirection: "column", gap: 20 }}>
                <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: 14 }}>
                  {["Daily", "Weekly", "Monthly"].map((type, i) => (
                    <div key={i} style={{ background: "var(--surface)", border: "1px solid var(--border)", borderRadius: 12, padding: 20 }}>
                      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", marginBottom: 16 }}>
                        <div>
                          <div style={{ fontSize: 11, color: "var(--text3)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase" }}>{type} Report</div>
                          <div style={{ fontSize: 13, fontWeight: 700, color: "var(--text)", marginTop: 4 }}>
                            {type === "Daily" ? "Today, May 29" : type === "Weekly" ? "May 23 – 29" : "May 2026"}
                          </div>
                        </div>
                        <span style={{ fontSize: 20 }}>{type === "Daily" ? "📋" : type === "Weekly" ? "📊" : "📈"}</span>
                      </div>
                      <div style={{ display: "flex", flexDirection: "column", gap: 6, marginBottom: 16 }}>
                        {type === "Daily" && <>
                          <div style={{ display: "flex", justifyContent: "space-between" }}><span style={{ fontSize: 11, color: "var(--text3)" }}>Tasks Assigned</span><span style={{ fontSize: 11, fontFamily: "'DM Mono', monospace", color: "var(--text)" }}>12</span></div>
                          <div style={{ display: "flex", justifyContent: "space-between" }}><span style={{ fontSize: 11, color: "var(--text3)" }}>Tasks Completed</span><span style={{ fontSize: 11, fontFamily: "'DM Mono', monospace", color: "#10b981" }}>8</span></div>
                          <div style={{ display: "flex", justifyContent: "space-between" }}><span style={{ fontSize: 11, color: "var(--text3)" }}>Overdue</span><span style={{ fontSize: 11, fontFamily: "'DM Mono', monospace", color: "#ef4444" }}>2</span></div>
                          <div style={{ display: "flex", justifyContent: "space-between" }}><span style={{ fontSize: 11, color: "var(--text3)" }}>Team APIX</span><span style={{ fontSize: 11, fontFamily: "'DM Mono', monospace", color: "#f59e0b" }}>78.4</span></div>
                        </>}
                        {type === "Weekly" && <>
                          <div style={{ display: "flex", justifyContent: "space-between" }}><span style={{ fontSize: 11, color: "var(--text3)" }}>Total Completed</span><span style={{ fontSize: 11, fontFamily: "'DM Mono', monospace", color: "var(--text)" }}>63</span></div>
                          <div style={{ display: "flex", justifyContent: "space-between" }}><span style={{ fontSize: 11, color: "var(--text3)" }}>On-Time Rate</span><span style={{ fontSize: 11, fontFamily: "'DM Mono', monospace", color: "#10b981" }}>84%</span></div>
                          <div style={{ display: "flex", justifyContent: "space-between" }}><span style={{ fontSize: 11, color: "var(--text3)" }}>Avg APIX</span><span style={{ fontSize: 11, fontFamily: "'DM Mono', monospace", color: "#f59e0b" }}>75.1</span></div>
                          <div style={{ display: "flex", justifyContent: "space-between" }}><span style={{ fontSize: 11, color: "var(--text3)" }}>WA Response Rate</span><span style={{ fontSize: 11, fontFamily: "'DM Mono', monospace", color: "#22d3ee" }}>91%</span></div>
                        </>}
                        {type === "Monthly" && <>
                          <div style={{ display: "flex", justifyContent: "space-between" }}><span style={{ fontSize: 11, color: "var(--text3)" }}>KPI Achievement</span><span style={{ fontSize: 11, fontFamily: "'DM Mono', monospace", color: "#10b981" }}>78%</span></div>
                          <div style={{ display: "flex", justifyContent: "space-between" }}><span style={{ fontSize: 11, color: "var(--text3)" }}>Tasks Completed</span><span style={{ fontSize: 11, fontFamily: "'DM Mono', monospace", color: "var(--text)" }}>284</span></div>
                          <div style={{ display: "flex", justifyContent: "space-between" }}><span style={{ fontSize: 11, color: "var(--text3)" }}>Best Team</span><span style={{ fontSize: 11, fontFamily: "'DM Mono', monospace", color: "#f59e0b" }}>Sales</span></div>
                          <div style={{ display: "flex", justifyContent: "space-between" }}><span style={{ fontSize: 11, color: "var(--text3)" }}>Improvement Δ</span><span style={{ fontSize: 11, fontFamily: "'DM Mono', monospace", color: "#22d3ee" }}>+12%</span></div>
                        </>}
                      </div>
                      <button className="btn btn-ghost" style={{ width: "100%", justifyContent: "center", fontSize: 12 }} onClick={() => showNotif(`${type} report generation queued!`)}>
                        Generate & Share Report
                      </button>
                    </div>
                  ))}
                </div>

                {/* Trend chart */}
                <div style={{ background: "var(--surface)", border: "1px solid var(--border)", borderRadius: 12, padding: 20 }}>
                  <div style={{ fontSize: 11, color: "var(--text3)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase", letterSpacing: 1, marginBottom: 16 }}>Team APIX Trend — Last 7 Days</div>
                  <div style={{ display: "flex", gap: 24 }}>
                    {Object.entries(MOCK.apixHistory).map(([name, data], i) => {
                      const colors = ["#22d3ee", "#10b981", "#f59e0b"];
                      return (
                        <div key={i} style={{ flex: 1 }}>
                          <div style={{ fontSize: 11, color: colors[i], marginBottom: 6, fontFamily: "'DM Mono', monospace" }}>{name.split(" ")[0]}</div>
                          <SparkLine data={data} color={colors[i]} height={50} />
                        </div>
                      );
                    })}
                  </div>
                </div>
              </div>
            )}
          </div>
        </main>

        {/* ── WA SIMULATOR PANEL ── */}
        {waSimulator && (
          <div style={{
            width: 320, background: "#111b21", borderLeft: "1px solid rgba(255,255,255,0.08)",
            display: "flex", flexDirection: "column", flexShrink: 0,
          }}>
            <div style={{ padding: "14px 16px", borderBottom: "1px solid rgba(255,255,255,0.08)", display: "flex", alignItems: "center", gap: 10, background: "#202c33" }}>
              <div style={{ width: 36, height: 36, borderRadius: "50%", background: "linear-gradient(135deg, #10b981, #059669)", display: "flex", alignItems: "center", justifyContent: "center", fontSize: 12, fontWeight: 700, color: "#fff" }}>PS</div>
              <div>
                <div style={{ fontSize: 13, fontWeight: 600, color: "#e9edef" }}>Priya Sharma</div>
                <div style={{ fontSize: 10, color: "#8696a0" }}>+91 98765 43210 · Employee</div>
              </div>
              <button onClick={() => setWaSimulator(false)} style={{ marginLeft: "auto", background: "none", border: "none", color: "#8696a0", cursor: "pointer", fontSize: 18 }}>✕</button>
            </div>
            <div style={{ flex: 1, overflow: "auto", padding: 12, display: "flex", flexDirection: "column", gap: 8, background: "#0b141a" }}>
              {waMessages.map((msg, i) => {
                const isEmployee = msg.from === "priya";
                return (
                  <div key={i} style={{ display: "flex", justifyContent: isEmployee ? "flex-end" : "flex-start" }}>
                    <div style={{
                      maxWidth: "80%", padding: "8px 12px", borderRadius: isEmployee ? "12px 12px 4px 12px" : "12px 12px 12px 4px",
                      background: isEmployee ? "#005c4b" : "#202c33", fontSize: 12, color: "#e9edef", lineHeight: 1.5,
                      whiteSpace: "pre-wrap",
                    }}>
                      {msg.text}
                      <div style={{ fontSize: 9, color: "#8696a0", marginTop: 4, textAlign: "right" }}>{msg.time}</div>
                    </div>
                  </div>
                );
              })}
            </div>
            {/* Quick commands */}
            <div style={{ padding: "6px 10px", background: "#202c33", display: "flex", gap: 4, flexWrap: "wrap" }}>
              {["START", "UPDATE", "COMPLETE", "DELAY", "ESCALATE", "SCORE"].map(cmd => (
                <button key={cmd} onClick={() => setWaInput(cmd)} style={{
                  padding: "3px 8px", background: "rgba(255,255,255,0.08)", border: "1px solid rgba(255,255,255,0.12)",
                  borderRadius: 6, color: "#aebac1", fontSize: 10, cursor: "pointer", fontFamily: "'DM Mono', monospace",
                }}>{cmd}</button>
              ))}
            </div>
            <div style={{ padding: "8px 12px", background: "#202c33", display: "flex", gap: 8, alignItems: "center" }}>
              <textarea value={waInput} onChange={e => setWaInput(e.target.value)} placeholder="Type a command..." onKeyDown={e => e.key === "Enter" && !e.shiftKey && (e.preventDefault(), handleWaSend())} style={{ flex: 1, background: "#2a3942", border: "none", borderRadius: 8, padding: "8px 12px", color: "#e9edef", fontSize: 12, resize: "none", height: 38, outline: "none" }} />
              <button onClick={handleWaSend} style={{ background: "#00a884", border: "none", borderRadius: "50%", width: 36, height: 36, cursor: "pointer", display: "flex", alignItems: "center", justifyContent: "center", color: "#fff", fontSize: 14, flexShrink: 0 }}>➤</button>
            </div>
          </div>
        )}
      </div>

      {/* ── NEW TASK MODAL ── */}
      {showNewTask && (
        <div style={{ position: "fixed", inset: 0, background: "rgba(0,0,0,0.7)", display: "flex", alignItems: "center", justifyContent: "center", zIndex: 100, backdropFilter: "blur(4px)" }} onClick={() => setShowNewTask(false)}>
          <div className="slide-up" style={{ background: "var(--surface2)", border: "1px solid var(--border2)", borderRadius: 16, padding: 28, width: 440, maxWidth: "90vw" }} onClick={e => e.stopPropagation()}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 20 }}>
              <div>
                <h2 style={{ fontFamily: "'Syne', sans-serif", fontSize: 18, fontWeight: 700 }}>New Task</h2>
                <div style={{ fontSize: 11, color: "var(--text3)", marginTop: 2 }}>Employee will receive via WhatsApp</div>
              </div>
              <button onClick={() => setShowNewTask(false)} style={{ background: "none", border: "none", color: "var(--text3)", cursor: "pointer", fontSize: 20 }}>✕</button>
            </div>
            <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
              <div>
                <label style={{ fontSize: 11, color: "var(--text3)", display: "block", marginBottom: 5, fontFamily: "'DM Mono', monospace" }}>TASK TITLE</label>
                <input value={newTaskData.title} onChange={e => setNewTaskData({ ...newTaskData, title: e.target.value })} placeholder="e.g. Visit 15 doctors in Navrangpura" />
              </div>
              <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12 }}>
                <div>
                  <label style={{ fontSize: 11, color: "var(--text3)", display: "block", marginBottom: 5, fontFamily: "'DM Mono', monospace" }}>ASSIGN TO</label>
                  <select value={newTaskData.assignee} onChange={e => setNewTaskData({ ...newTaskData, assignee: e.target.value })}>
                    <option value="">Select employee</option>
                    {MOCK.employees.map(e => <option key={e.id} value={e.id}>{e.name}</option>)}
                  </select>
                </div>
                <div>
                  <label style={{ fontSize: 11, color: "var(--text3)", display: "block", marginBottom: 5, fontFamily: "'DM Mono', monospace" }}>PRIORITY</label>
                  <select value={newTaskData.priority} onChange={e => setNewTaskData({ ...newTaskData, priority: e.target.value })}>
                    <option value="low">Low</option>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                    <option value="critical">Critical</option>
                  </select>
                </div>
              </div>
              <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12 }}>
                <div>
                  <label style={{ fontSize: 11, color: "var(--text3)", display: "block", marginBottom: 5, fontFamily: "'DM Mono', monospace" }}>DUE DATE</label>
                  <input type="datetime-local" value={newTaskData.due} onChange={e => setNewTaskData({ ...newTaskData, due: e.target.value })} />
                </div>
                <div>
                  <label style={{ fontSize: 11, color: "var(--text3)", display: "block", marginBottom: 5, fontFamily: "'DM Mono', monospace" }}>REWARD POINTS</label>
                  <input type="number" value={newTaskData.points} onChange={e => setNewTaskData({ ...newTaskData, points: e.target.value })} min="0" max="200" />
                </div>
              </div>
              <div style={{ display: "flex", gap: 8, marginTop: 4 }}>
                <button className="btn btn-ghost" style={{ flex: 1, justifyContent: "center" }} onClick={() => setShowNewTask(false)}>Cancel</button>
                <button className="btn btn-primary" style={{ flex: 2, justifyContent: "center" }} onClick={() => {
                  setShowNewTask(false);
                  showNotif("✅ Task assigned! Employee notified via WhatsApp.");
                  setNewTaskData({ title: "", assignee: "", priority: "medium", due: "", points: 50 });
                }}>
                  Assign via WhatsApp
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ── NOTIFICATION TOAST ── */}
      {notification && (
        <div className="slide-up" style={{
          position: "fixed", bottom: 24, right: 24, zIndex: 200,
          background: notification.type === "error" ? "rgba(239,68,68,0.15)" : "rgba(16,185,129,0.15)",
          border: `1px solid ${notification.type === "error" ? "rgba(239,68,68,0.3)" : "rgba(16,185,129,0.3)"}`,
          borderRadius: 10, padding: "12px 18px", fontSize: 13, color: "var(--text)",
          backdropFilter: "blur(12px)", maxWidth: 320,
        }}>
          {notification.msg}
        </div>
      )}
    </>
  );
}

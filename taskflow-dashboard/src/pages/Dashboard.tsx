import { useState, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import { useAuthStore } from "../store/auth";
import api from "../shared/api/client";

// ─── TYPES ────────────────────────────────────────────────────────────────────
interface Overview {
  total_tasks: number;
  open_tasks: number;
  completed_tasks: number;
  overdue_tasks: number;
  completed_today: number;
  active_employees: number;
  team_apix: number;
  apix_delta: number;
  weekly_trend: number[];
  status_breakdown: { in_progress: number; completed: number; overdue: number; assigned: number };
  recent_activity: Array<{ user_name: string; user_initials: string; action: string; task_title: string; time: string; color: string }>;
}

interface LeaderboardUser {
  id: string;
  name: string;
  first_name: string;
  initials: string;
  apix: number;
  phone: string;
  role: string;
  designation?: string;
}

interface AIInsight {
  type: 'alert' | 'risk' | 'star' | 'insight';
  icon: string;
  title: string;
  desc: string;
  action: string;
  user_id?: string;
  task_id?: string;
}

interface ReportData {
  type: string;
  label: string;
  stats: any;
}

interface User {
  id: string;
  name: string;
  email: string;
  phone: string;
  role: string;
  department?: string;
  designation?: string;
  reports_to?: string | null;
  is_active: boolean;
  stats?: { total_assigned: number; completed: number; pending: number; overdue: number };
}

interface TreeNode extends User {
  children: TreeNode[];
  stats: { total_assigned: number; completed: number; pending: number; overdue: number };
}

interface Task {
  id: string;
  title: string;
  status: string;
  priority: string;
  due_date?: string;
  reward_points: number;
  assigned_to?: User;
  assigned_by?: User;
  created_at: string;
}

interface ActivityLogEntry {
  id: string;
  type: string;
  action: string;
  status: string;
  message: string;
  phone?: string;
  created_at: string;
}

// ─── CONFIG ───────────────────────────────────────────────────────────────────
const STATUS_CONFIG: Record<string, { label: string; color: string; bg: string }> = {
  assigned:    { label: "Assigned",    color: "#64748b", bg: "rgba(100,116,139,0.12)" },
  accepted:    { label: "Accepted",    color: "#3b82f6", bg: "rgba(59,130,246,0.12)" },
  in_progress: { label: "In Progress", color: "#f59e0b", bg: "rgba(245,158,11,0.12)" },
  waiting:     { label: "Waiting",     color: "#8b5cf6", bg: "rgba(139,92,246,0.12)" },
  completed:   { label: "Completed",   color: "#10b981", bg: "rgba(16,185,129,0.12)" },
  verified:    { label: "Verified",    color: "#06b6d4", bg: "rgba(6,182,212,0.12)" },
  rejected:    { label: "Rejected",    color: "#ef4444", bg: "rgba(239,68,68,0.12)" },
  escalated:   { label: "Escalated",   color: "#f97316", bg: "rgba(249,115,22,0.12)" },
  overdue:     { label: "Overdue",     color: "#dc2626", bg: "rgba(220,38,38,0.12)" },
};

const ROLE_CONFIG: Record<string, { label: string; color: string; icon: string }> = {
  admin:    { label: "Admin",    color: "#0891b2", icon: "👑" },
  manager:  { label: "Manager",  color: "#f59e0b", icon: "👔" },
  employee: { label: "Employee", color: "#10b981", icon: "👤" },
};

const APIX_BAND = (score: number) => {
  if (score >= 90) return { label: "Elite", color: "#f59e0b" };
  if (score >= 75) return { label: "High", color: "#10b981" };
  if (score >= 60) return { label: "On Track", color: "#3b82f6" };
  if (score >= 45) return { label: "Needs Attn", color: "#f97316" };
  return { label: "At Risk", color: "#ef4444" };
};

const LOG_STATUS_COLOR = (s: string) => s === 'success' ? '#10b981' : s === 'failed' ? '#ef4444' : '#f59e0b';
const LOG_ICON = (type: string, status: string) => {
  if (status === 'failed') return '❌';
  if (type === 'whatsapp_out' && status === 'success') return '✅';
  if (type === 'whatsapp_in') return '📩';
  if (type === 'task') return '📋';
  if (type === 'user') return '👤';
  return 'ℹ️';
};

// ─── BAR CHART ────────────────────────────────────────────────────────────────
const BarChart = ({ data, color = "#0891b2" }: { data: number[]; color?: string }) => {
  const max = Math.max(...data, 1);
  const days = ["M", "T", "W", "T", "F", "S", "S"];
  return (
    <div style={{ display: "flex", alignItems: "flex-end", gap: 6, height: 60 }}>
      {data.map((v, i) => (
        <div key={i} style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 4, flex: 1 }}>
          <div style={{
            width: "100%",
            background: i === data.length - 1 ? color : `${color}55`,
            borderRadius: "3px 3px 0 0",
            height: `${(v / max) * 52}px`,
            transition: "height 0.6s ease",
            minHeight: 4,
          }} />
          <span style={{ fontSize: 9, color: "rgba(15,23,42,0.5)", fontFamily: "'DM Mono', monospace" }}>{days[i]}</span>
        </div>
      ))}
    </div>
  );
};

// ─── SPARK LINE ───────────────────────────────────────────────────────────────
const SparkLine = ({ data, color }: { data: number[]; color: string }) => {
  if (!data || data.length === 0) return null;
  const max = Math.max(...data, 1);
  const min = Math.min(...data, 0);
  const w = 140, h = 50;
  const range = max - min || 1;
  const pts = data.map((v, i) => {
    const x = (i / Math.max(data.length - 1, 1)) * w;
    const y = h - ((v - min) / range) * (h - 6) - 3;
    return `${x},${y}`;
  }).join(" ");
  const lastIdx = data.length - 1;
  const lastX = (lastIdx / Math.max(lastIdx, 1)) * w;
  const lastY = h - ((data[lastIdx] - min) / range) * (h - 6) - 3;
  return (
    <svg width={w} height={h} style={{ overflow: "visible" }}>
      <polyline points={pts} fill="none" stroke={color} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
      <circle cx={lastX} cy={lastY} r="3" fill={color} />
    </svg>
  );
};

// ─── APIX RING ────────────────────────────────────────────────────────────────
const APIXRing = ({ score, size = 54 }: { score: number; size?: number }) => {
  const band = APIX_BAND(score);
  const r = size / 2 - 5;
  const circ = 2 * Math.PI * r;
  const offset = circ - (score / 100) * circ;
  return (
    <div style={{ position: "relative", width: size, height: size, flexShrink: 0 }}>
      <svg width={size} height={size} style={{ transform: "rotate(-90deg)" }}>
        <circle cx={size/2} cy={size/2} r={r} fill="none" stroke="#e2e8f0" strokeWidth="4" />
        <circle cx={size/2} cy={size/2} r={r} fill="none" stroke={band.color} strokeWidth="4"
          strokeDasharray={circ} strokeDashoffset={offset} strokeLinecap="round"
          style={{ transition: "stroke-dashoffset 1s ease" }} />
      </svg>
      <div style={{ position: "absolute", inset: 0, display: "flex", alignItems: "center", justifyContent: "center" }}>
        <span style={{ fontSize: size > 56 ? 14 : 11, fontWeight: 700, color: band.color, fontFamily: "'DM Mono', monospace" }}>{score}</span>
      </div>
    </div>
  );
};

// ─── MAIN ─────────────────────────────────────────────────────────────────────
export default function Dashboard() {
  const [activeView, setActiveView] = useState<"overview" | "tasks" | "people" | "tree" | "ai" | "reports" | "logs" | "settings">("overview");
  const [overview, setOverview] = useState<Overview | null>(null);
  const [tasks, setTasks] = useState<Task[]>([]);
  const [allUsers, setAllUsers] = useState<User[]>([]);
  const [tree, setTree] = useState<TreeNode[]>([]);
  const [leaderboard, setLeaderboard] = useState<LeaderboardUser[]>([]);
  const [aiInsights, setAiInsights] = useState<AIInsight[]>([]);
  const [aiReport, setAiReport] = useState<any>(null);
  const [dailyReport, setDailyReport] = useState<ReportData | null>(null);
  const [weeklyReport, setWeeklyReport] = useState<ReportData | null>(null);
  const [monthlyReport, setMonthlyReport] = useState<ReportData | null>(null);
  const [apixTrend, setApixTrend] = useState<any[]>([]);
  const [logs, setLogs] = useState<ActivityLogEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [showAddUser, setShowAddUser] = useState(false);
  const [showNewTask, setShowNewTask] = useState(false);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [editUser, setEditUser] = useState<User | null>(null);
  const [notification, setNotification] = useState<{ msg: string; type: 'success' | 'error' } | null>(null);
  const [liveTime, setLiveTime] = useState(new Date());
  const [taskFilter, setTaskFilter] = useState("all");

  const { user, logout } = useAuthStore();
  const navigate = useNavigate();

  const showNotif = (msg: string, type: 'success' | 'error' = 'success') => {
    setNotification({ msg, type });
    setTimeout(() => setNotification(null), 3000);
  };

  const loadData = async () => {
    try {
      setLoading(true);
      const [ov, ts, us, tr, lb, ai, aiR, dr, wr, mr, apixTr, logsRes] = await Promise.all([
        api.get("/analytics/overview").catch(() => ({ data: null })),
        api.get("/tasks").catch(() => ({ data: { data: [] } })),
        api.get("/users").catch(() => ({ data: [] })),
        api.get("/users/tree").catch(() => ({ data: [] })),
        api.get("/analytics/leaderboard").catch(() => ({ data: [] })),
        api.get("/ai/insights").catch(() => ({ data: [] })),
        api.get("/ai/reports/daily").catch(() => ({ data: null })),
        api.get("/analytics/reports?type=daily").catch(() => ({ data: null })),
        api.get("/analytics/reports?type=weekly").catch(() => ({ data: null })),
        api.get("/analytics/reports?type=monthly").catch(() => ({ data: null })),
        api.get("/analytics/apix-trend").catch(() => ({ data: [] })),
        api.get("/logs").catch(() => ({ data: [] })),
      ]);
      if (ov.data) setOverview(ov.data);
      setTasks(ts.data?.data || ts.data || []);
      setAllUsers(us.data || []);
      setTree(tr.data || []);
      setLeaderboard(lb.data || []);
      setAiInsights(ai.data || []);
      setAiReport(aiR.data);
      setDailyReport(dr.data);
      setWeeklyReport(wr.data);
      setMonthlyReport(mr.data);
      setApixTrend(apixTr.data || []);
      setLogs(logsRes.data || []);
    } catch (err) {
      console.error("Failed to load data", err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadData();
    const interval = setInterval(loadData, 15000);
    return () => clearInterval(interval);
  }, []);

  useEffect(() => {
    const t = setInterval(() => setLiveTime(new Date()), 1000);
    return () => clearInterval(t);
  }, []);

  const handleLogout = () => { logout(); navigate("/login"); };

  const handleDeleteUser = async (u: User) => {
    if (u.role === 'admin') { showNotif("Admin can't be deleted", 'error'); return; }
    if (!confirm(`Delete ${u.name}?`)) return;
    try {
      await api.delete(`/users/${u.id}`);
      showNotif(`${u.name} deactivated`);
      loadData();
    } catch (err: any) { showNotif(err.response?.data?.message || "Failed", 'error'); }
  };

  const handleClearLogs = async () => {
    if (!confirm("Clear all logs?")) return;
    try { await api.delete("/logs"); showNotif("Logs cleared"); setLogs([]); }
    catch { showNotif("Failed", 'error'); }
  };

  const formatTime = (iso: string) => {
    const d = new Date(iso);
    const diffSec = Math.floor((Date.now() - d.getTime()) / 1000);
    if (diffSec < 60) return `${diffSec}s ago`;
    if (diffSec < 3600) return `${Math.floor(diffSec / 60)}m ago`;
    if (diffSec < 86400) return `${Math.floor(diffSec / 3600)}h ago`;
    return d.toLocaleDateString();
  };

  const filteredTasks = taskFilter === "all" ? tasks : tasks.filter(t => t.status === taskFilter);
  const activeUsersCount = allUsers.filter(u => u.is_active).length;

  const NAV = [
    { id: "overview" as const, icon: "⊞", label: "Overview" },
    { id: "tasks" as const, icon: "✓", label: "Tasks" },
    { id: "people" as const, icon: "◉", label: "People" },
    { id: "tree" as const, icon: "🌳", label: "Org Tree" },
    { id: "ai" as const, icon: "✦", label: "AI Insights", badge: aiInsights.filter(i => i.type === 'alert' || i.type === 'risk').length },
    { id: "reports" as const, icon: "≡", label: "Reports" },
    { id: "logs" as const, icon: "📊", label: "Live Logs", badge: logs.length },
    { id: "settings" as const, icon: "⚙", label: "Settings" },
  ];

  const css = `
    @import url('https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&family=Inter:wght@300;400;500;600&display=swap');
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #f8fafc; color: #0f172a; font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; }
    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: rgba(15,23,42,0.15); border-radius: 3px; }
    ::-webkit-scrollbar-thumb:hover { background: rgba(15,23,42,0.3); }

    .fade-in { animation: fadeIn 0.3s ease; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    .pulse { animation: pulse 2s infinite; }
    @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }

    input, select, textarea {
      background: #ffffff; border: 1px solid #e2e8f0;
      border-radius: 8px; color: #0f172a; font-family: 'Inter', sans-serif;
      font-size: 14px; padding: 10px 14px; outline: none; width: 100%;
      transition: border-color 0.15s, box-shadow 0.15s;
    }
    input:focus, select:focus, textarea:focus {
      border-color: #0891b2;
      box-shadow: 0 0 0 3px rgba(8,145,178,0.12);
    }
    select option { background: #ffffff; color: #0f172a; }
    input::placeholder { color: #94a3b8; }

    .btn {
      display: inline-flex; align-items: center; justify-content: center;
      gap: 6px; padding: 9px 16px; border-radius: 8px;
      font-size: 13px; font-weight: 500; cursor: pointer;
      border: 1px solid transparent;
      transition: transform 0.1s ease, box-shadow 0.15s, background 0.15s, color 0.15s, border-color 0.15s;
      -webkit-tap-highlight-color: transparent;
      user-select: none;
    }
    .btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(15,23,42,0.08); }
    .btn:active { transform: scale(0.97); box-shadow: 0 1px 2px rgba(15,23,42,0.05); transition-duration: 0.05s; }
    .btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none !important; }

    .btn-primary { background: #0f172a; color: #ffffff; }
    .btn-primary:hover { background: #1e293b; }
    .btn-ghost { background: #ffffff; color: #0f172a; border-color: #e2e8f0; }
    .btn-ghost:hover { background: #f8fafc; border-color: #cbd5e1; }
    .btn-danger { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
    .btn-danger:hover { background: #fee2e2; }
    .btn-icon { padding: 6px 10px; font-size: 12px; }

    .tag {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 3px 9px; border-radius: 6px;
      font-size: 11px; font-family: 'DM Mono', monospace; font-weight: 500;
      background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;
    }

    [style*="cursor: pointer"]:active, [style*="cursor:pointer"]:active { transform: scale(0.98); transition: transform 0.05s; }

    .mobile-menu-btn {
      display: none; background: transparent; border: none; cursor: pointer;
      width: 40px; height: 40px; border-radius: 8px; padding: 0;
      align-items: center; justify-content: center; font-size: 20px;
      color: #0f172a; -webkit-tap-highlight-color: transparent;
    }
    .mobile-menu-btn:hover { background: #f1f5f9; }
    .mobile-menu-btn:active { transform: scale(0.94); }

    .mobile-backdrop { display: none; }

    /* ─── MOBILE BREAKPOINT (≤768px) ─── */
    @media (max-width: 768px) {
      .mobile-menu-btn { display: inline-flex; }
      .mobile-backdrop {
        display: block; position: fixed; inset: 0;
        background: rgba(15,23,42,0.45); z-index: 90;
        animation: fadeIn 0.2s ease;
      }

      .sidebar {
        position: fixed !important;
        top: 0; left: 0; bottom: 0;
        z-index: 100;
        transform: translateX(-100%);
        transition: transform 0.25s cubic-bezier(0.16,1,0.3,1);
        box-shadow: 4px 0 24px rgba(15,23,42,0.15);
      }
      .sidebar.open { transform: translateX(0); }

      [style*="grid-template-columns: repeat(5"],
      [style*="grid-template-columns: repeat(4"],
      [style*="grid-template-columns: repeat(3"] {
        grid-template-columns: 1fr !important;
      }
      [style*="grid-template-columns: 1fr 1fr 1fr"],
      [style*="grid-template-columns: 1fr 1fr"] {
        grid-template-columns: 1fr !important;
      }

      .btn { padding: 11px 16px; font-size: 14px; min-height: 44px; }
      .btn-icon { padding: 8px 12px; min-height: 36px; }

      header { padding: 12px 16px !important; }

      table { display: block; overflow-x: auto; white-space: nowrap; }
    }

    @media (max-width: 480px) {
      .btn { font-size: 13px; }
    }

    button:focus-visible, a:focus-visible { outline: 2px solid #0891b2; outline-offset: 2px; }
  `;

  return (
    <>
      <style dangerouslySetInnerHTML={{ __html: css }} />
      <div style={{ display: "flex", height: "100vh", overflow: "hidden", fontFamily: "'Inter', sans-serif" }}>
        {/* SIDEBAR */}
        <aside className={`sidebar ${mobileMenuOpen ? 'open' : ''}`} style={{ width: 220, background: "#ffffff", borderRight: "1px solid #e2e8f0", display: "flex", flexDirection: "column", flexShrink: 0 }}>
          <div style={{ padding: "20px 20px 16px", borderBottom: "1px solid #e2e8f0" }}>
            <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
              <div style={{ width: 34, height: 34, borderRadius: 10, background: "linear-gradient(135deg, #0891b2, #06748f)", display: "flex", alignItems: "center", justifyContent: "center", fontSize: 16, fontWeight: 800, color: "#ffffff", fontFamily: "'Syne', sans-serif" }}>TF</div>
              <div>
                <div style={{ fontFamily: "'Syne', sans-serif", fontWeight: 800, fontSize: 15, letterSpacing: "-0.3px" }}>TaskFlow</div>
                <div style={{ fontSize: 10, color: "#0891b2", fontFamily: "'DM Mono', monospace" }}>WhatsApp OS</div>
              </div>
            </div>
          </div>
          <div style={{ margin: "12px 12px 4px", background: "rgba(8,145,178,0.08)", border: "1px solid rgba(8,145,178,0.15)", borderRadius: 8, padding: "8px 12px" }}>
            <div style={{ fontSize: 10, color: "#0891b2", fontFamily: "'DM Mono', monospace", marginBottom: 2 }}>TENANT</div>
            <div style={{ fontSize: 12, fontWeight: 600 }}>UIC Group</div>
            <div style={{ fontSize: 10, color: "rgba(15,23,42,0.5)" }}>{activeUsersCount} active users</div>
          </div>
          <nav style={{ padding: "8px", flex: 1 }}>
            {NAV.map(item => (
              <button key={item.id} onClick={() => { setActiveView(item.id); setMobileMenuOpen(false); }} style={{
                width: "100%", display: "flex", alignItems: "center", gap: 10, padding: "9px 12px",
                borderRadius: 8, border: "none", cursor: "pointer", marginBottom: 2,
                background: activeView === item.id ? "#0f172a" : "transparent",
                color: activeView === item.id ? "#ffffff" : "rgba(15,23,42,0.65)",
                fontSize: 13, fontWeight: activeView === item.id ? 600 : 400, textAlign: "left",
                transition: "background 0.15s, color 0.15s",
              }}>
                <span style={{ fontSize: 14, width: 18, textAlign: "center" }}>{item.icon}</span>
                {item.label}
                {item.badge ? <span style={{ marginLeft: "auto", background: item.id === "ai" ? "#ef4444" : "#0891b2", color: "#ffffff", borderRadius: 4, fontSize: 9, padding: "1px 5px", fontFamily: "'DM Mono', monospace" }}>{item.badge}</span> : null}
              </button>
            ))}
          </nav>
          <div style={{ padding: 12, borderTop: "1px solid #e2e8f0" }}>
            <div style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 8 }}>
              <div style={{ width: 32, height: 32, borderRadius: "50%", background: "linear-gradient(135deg, #10b981, #059669)", display: "flex", alignItems: "center", justifyContent: "center", fontSize: 12, fontWeight: 700, color: "#ffffff" }}>{user?.name?.charAt(0)}</div>
              <div style={{ minWidth: 0 }}>
                <div style={{ fontSize: 12, fontWeight: 600, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{user?.name}</div>
                <div style={{ fontSize: 10, color: "rgba(15,23,42,0.5)" }}>{user?.role}</div>
              </div>
            </div>
            <button className="btn btn-ghost" onClick={handleLogout} style={{ width: "100%", justifyContent: "center", fontSize: 11, padding: "5px 10px" }}>Logout</button>
          </div>
        </aside>

        {/* MOBILE BACKDROP — only visible on small screens when sidebar is open */}
        {mobileMenuOpen && <div className="mobile-backdrop" onClick={() => setMobileMenuOpen(false)} />}

        {/* MAIN */}
        <main style={{ flex: 1, overflow: "auto", background: "#f8fafc" }}>
          <header style={{ padding: "14px 24px", borderBottom: "1px solid #e2e8f0", display: "flex", alignItems: "center", justifyContent: "space-between", position: "sticky", top: 0, background: "rgba(255,255,255,0.92)", backdropFilter: "blur(12px)", zIndex: 10 }}>
            <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
              <button className="mobile-menu-btn" onClick={() => setMobileMenuOpen(true)} aria-label="Open menu">☰</button>
              <div>
                <h1 style={{ fontFamily: "'Syne', sans-serif", fontSize: 18, fontWeight: 700, letterSpacing: "-0.3px" }}>
                  {activeView === "overview" && "Dashboard Overview"}
                  {activeView === "tasks" && "Task Management"}
                  {activeView === "people" && "People & Performance"}
                  {activeView === "tree" && "Organization Tree"}
                  {activeView === "ai" && "AI Insights & Reports"}
                  {activeView === "reports" && "Analytics Reports"}
                  {activeView === "logs" && "Live Activity Logs"}
                  {activeView === "settings" && "Settings"}
                </h1>
                <div style={{ fontSize: 11, color: "rgba(15,23,42,0.5)", fontFamily: "'DM Mono', monospace", marginTop: 2 }}>
                  {liveTime.toLocaleDateString("en-IN", { weekday: "short", day: "2-digit", month: "short", year: "numeric" })} · {liveTime.toLocaleTimeString()}
                </div>
              </div>
            </div>
            <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
              <div style={{ display: "flex", alignItems: "center", gap: 6, background: "rgba(16,185,129,0.1)", border: "1px solid rgba(16,185,129,0.2)", borderRadius: 8, padding: "5px 10px" }}>
                <span className="pulse" style={{ width: 6, height: 6, borderRadius: "50%", background: "#10b981", display: "block" }} />
                <span style={{ fontSize: 11, color: "#10b981", fontFamily: "'DM Mono', monospace" }}>{activeUsersCount} online</span>
              </div>
              <button className="btn btn-ghost" onClick={loadData}>↻</button>
              {(activeView === "people" || activeView === "tree") && <button className="btn btn-primary" onClick={() => setShowAddUser(true)}>+ Add User</button>}
              {activeView === "tasks" && <button className="btn btn-primary" onClick={() => setShowNewTask(true)}>+ New Task</button>}
              {activeView === "logs" && <button className="btn btn-danger" onClick={handleClearLogs}>🗑 Clear</button>}
            </div>
          </header>

          <div className="fade-in" style={{ padding: 24 }}>

            {/* ─── OVERVIEW ─── */}
            {activeView === "overview" && overview && (
              <div style={{ display: "flex", flexDirection: "column", gap: 20 }}>
                {/* KPI Cards */}
                <div style={{ display: "grid", gridTemplateColumns: "repeat(5, 1fr)", gap: 12 }}>
                  {[
                    { label: "Total Tasks", value: overview.total_tasks, icon: "✓", color: "#0891b2", sub: "All time" },
                    { label: "Open Tasks", value: overview.open_tasks, icon: "◐", color: "#3b82f6", sub: "Active now" },
                    { label: "Completed", value: overview.completed_tasks, icon: "●", color: "#10b981", sub: `+${overview.completed_today} today` },
                    { label: "Overdue", value: overview.overdue_tasks, icon: "!", color: "#ef4444", sub: "Needs action" },
                    { label: "Team APIX", value: `${overview.team_apix}%`, icon: "✦", color: "#f59e0b", sub: `${overview.apix_delta >= 0 ? '+' : ''}${overview.apix_delta} vs last wk` },
                  ].map((kpi, i) => (
                    <div key={i} style={{ background: "#ffffff", border: "1px solid #e2e8f0", borderRadius: 12, padding: 16, position: "relative" }}>
                      <div style={{ position: "absolute", top: 12, right: 14, width: 32, height: 32, borderRadius: 8, background: `${kpi.color}15`, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 14, color: kpi.color }}>{kpi.icon}</div>
                      <div style={{ fontSize: 10, color: "rgba(15,23,42,0.5)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase", letterSpacing: 1 }}>{kpi.label}</div>
                      <div style={{ fontSize: 28, fontWeight: 800, color: kpi.color, fontFamily: "'Syne', sans-serif", marginTop: 6, letterSpacing: "-1px" }}>{kpi.value}</div>
                      <div style={{ fontSize: 10, color: "rgba(15,23,42,0.5)", marginTop: 4 }}>{kpi.sub}</div>
                    </div>
                  ))}
                </div>

                {/* Charts row */}
                <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 16 }}>
                  <div style={{ background: "#ffffff", border: "1px solid #e2e8f0", borderRadius: 12, padding: 20 }}>
                    <div style={{ fontSize: 11, color: "rgba(15,23,42,0.5)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase", letterSpacing: 1, marginBottom: 12 }}>Weekly Productivity</div>
                    <div style={{ marginBottom: 8 }}>
                      <span style={{ fontSize: 24, fontWeight: 800, color: "#0891b2", fontFamily: "'Syne', sans-serif" }}>{overview.team_apix}%</span>
                      <span style={{ fontSize: 11, color: overview.apix_delta >= 0 ? "#10b981" : "#ef4444", marginLeft: 6 }}>{overview.apix_delta >= 0 ? '↑' : '↓'} {Math.abs(overview.apix_delta)}</span>
                    </div>
                    <BarChart data={overview.weekly_trend} />
                  </div>

                  <div style={{ background: "#ffffff", border: "1px solid #e2e8f0", borderRadius: 12, padding: 20 }}>
                    <div style={{ fontSize: 11, color: "rgba(15,23,42,0.5)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase", letterSpacing: 1, marginBottom: 14 }}>Top Performers</div>
                    {leaderboard.length === 0 ? (
                      <div style={{ fontSize: 12, color: "rgba(15,23,42,0.55)", textAlign: "center", padding: 20 }}>No data yet</div>
                    ) : leaderboard.slice(0, 4).map((emp, i) => {
                      const band = APIX_BAND(emp.apix);
                      return (
                        <div key={emp.id} style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 10 }}>
                          <span style={{ fontSize: 10, color: "rgba(15,23,42,0.5)", fontFamily: "'DM Mono', monospace", width: 14 }}>#{i + 1}</span>
                          <div style={{ width: 26, height: 26, borderRadius: "50%", background: `linear-gradient(135deg, ${band.color}66, ${band.color}33)`, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 9, fontWeight: 700, color: band.color }}>{emp.initials}</div>
                          <div style={{ flex: 1, minWidth: 0 }}>
                            <div style={{ fontSize: 12, fontWeight: 600 }}>{emp.first_name}</div>
                            <div style={{ height: 3, background: "#e2e8f0", borderRadius: 2, marginTop: 3 }}>
                              <div style={{ height: "100%", width: `${emp.apix}%`, background: band.color, borderRadius: 2 }} />
                            </div>
                          </div>
                          <span style={{ fontSize: 11, fontWeight: 700, color: band.color, fontFamily: "'DM Mono', monospace" }}>{emp.apix}</span>
                        </div>
                      );
                    })}
                  </div>

                  <div style={{ background: "#ffffff", border: "1px solid #e2e8f0", borderRadius: 12, padding: 20 }}>
                    <div style={{ fontSize: 11, color: "rgba(15,23,42,0.5)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase", letterSpacing: 1, marginBottom: 14 }}>Task Status Breakdown</div>
                    {[
                      { label: "In Progress", count: overview.status_breakdown.in_progress, color: "#f59e0b" },
                      { label: "Completed", count: overview.status_breakdown.completed, color: "#10b981" },
                      { label: "Overdue", count: overview.status_breakdown.overdue, color: "#ef4444" },
                      { label: "Assigned", count: overview.status_breakdown.assigned, color: "#64748b" },
                    ].map((s, i) => {
                      const totalForPct = Math.max(overview.total_tasks, 1);
                      const pct = (s.count / totalForPct) * 100;
                      return (
                        <div key={i} style={{ marginBottom: 10 }}>
                          <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 3 }}>
                            <span style={{ fontSize: 11, color: "rgba(15,23,42,0.65)" }}>{s.label}</span>
                            <span style={{ fontSize: 11, fontFamily: "'DM Mono', monospace", color: s.color }}>{s.count}</span>
                          </div>
                          <div style={{ height: 4, background: "#e2e8f0", borderRadius: 2 }}>
                            <div style={{ height: "100%", width: `${Math.min(pct, 100)}%`, background: s.color, borderRadius: 2, transition: "width 1s ease" }} />
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </div>

                {/* Recent Activity */}
                <div style={{ background: "#ffffff", border: "1px solid #e2e8f0", borderRadius: 12, padding: 20 }}>
                  <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 16 }}>
                    <div style={{ fontSize: 11, color: "rgba(15,23,42,0.5)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase", letterSpacing: 1 }}>Recent WhatsApp Activity</div>
                    <span style={{ fontSize: 10, color: "#0891b2", cursor: "pointer" }} onClick={() => setActiveView("logs")}>View all →</span>
                  </div>
                  {overview.recent_activity.length === 0 ? (
                    <div style={{ fontSize: 12, color: "rgba(15,23,42,0.55)", textAlign: "center", padding: 20 }}>No activity yet — wait for WhatsApp messages</div>
                  ) : (
                    <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
                      {overview.recent_activity.map((act, i) => (
                        <div key={i} style={{ display: "flex", alignItems: "center", gap: 12, padding: "10px 14px", background: "#fafbfc", borderRadius: 8, border: "1px solid #e2e8f0" }}>
                          <div style={{ width: 30, height: 30, borderRadius: "50%", background: `${act.color}22`, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 10, fontWeight: 700, color: act.color, flexShrink: 0 }}>{act.user_initials}</div>
                          <div style={{ flex: 1, minWidth: 0 }}>
                            <span style={{ fontSize: 12, fontWeight: 600 }}>{act.user_name}</span>
                            <span style={{ fontSize: 12, color: "rgba(15,23,42,0.5)" }}> {act.action} </span>
                            <span style={{ fontSize: 12, color: act.color }}>{act.task_title}</span>
                          </div>
                          <span style={{ fontSize: 10, color: "rgba(15,23,42,0.5)", fontFamily: "'DM Mono', monospace", whiteSpace: "nowrap" }}>{act.time}</span>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              </div>
            )}

            {/* ─── TASKS ─── */}
            {activeView === "tasks" && (
              <div style={{ display: "flex", flexDirection: "column", gap: 16 }}>
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                  {["all", "in_progress", "assigned", "completed", "overdue", "escalated"].map(f => (
                    <button key={f} onClick={() => setTaskFilter(f)} className="tag" style={{
                      cursor: "pointer", border: `1px solid ${taskFilter === f ? "#0891b2" : "#cbd5e1"}`,
                      background: taskFilter === f ? "rgba(8,145,178,0.1)" : "#fafbfc",
                      color: taskFilter === f ? "#0891b2" : "rgba(15,23,42,0.65)",
                      padding: "6px 14px", fontSize: 12,
                    }}>
                      {f === "all" ? "All" : STATUS_CONFIG[f]?.label || f}
                      {f === "all" && <span style={{ marginLeft: 4, color: "rgba(15,23,42,0.5)" }}>{tasks.length}</span>}
                    </button>
                  ))}
                </div>
                <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
                  {filteredTasks.length === 0 ? (
                    <div style={{ textAlign: "center", padding: 60, color: "rgba(15,23,42,0.6)", background: "#ffffff", borderRadius: 12, border: "1px solid #e2e8f0" }}>
                      No tasks
                    </div>
                  ) : filteredTasks.map(task => {
                    const sc = STATUS_CONFIG[task.status] || STATUS_CONFIG.assigned;
                    return (
                      <div key={task.id} style={{ background: "#ffffff", border: "1px solid #e2e8f0", borderRadius: 12, padding: "14px 18px", display: "flex", alignItems: "center", gap: 16 }}>
                        <span className="tag" style={{ background: sc.bg, color: sc.color, border: `1px solid ${sc.color}40`, width: 88, justifyContent: "center" }}>{sc.label}</span>
                        <div style={{ flex: 1, minWidth: 0 }}>
                          <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 2 }}>{task.title}</div>
                          <div style={{ fontSize: 11, color: "rgba(15,23,42,0.5)" }}>{task.assigned_to?.name || "Unassigned"} · From {task.assigned_by?.name || "—"} · T-{task.id.substring(0, 6)}</div>
                        </div>
                        <div style={{ fontSize: 12, color: "#f59e0b", fontFamily: "'DM Mono', monospace" }}>⭐ {task.reward_points}</div>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}

            {/* ─── PEOPLE ─── */}
            {activeView === "people" && (
              <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: 14 }}>
                {allUsers.map(u => {
                  const role = ROLE_CONFIG[u.role] || ROLE_CONFIG.employee;
                  const lb = leaderboard.find(l => l.id === u.id);
                  const apix = lb?.apix || 0;
                  const band = APIX_BAND(apix);
                  return (
                    <div key={u.id} style={{ background: "#ffffff", border: "1px solid #e2e8f0", borderRadius: 14, padding: 18 }}>
                      <div style={{ display: "flex", gap: 12, alignItems: "flex-start" }}>
                        <div style={{ width: 44, height: 44, borderRadius: "50%", background: `linear-gradient(135deg, ${role.color}44, ${role.color}22)`, border: `2px solid ${role.color}55`, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 16 }}>{role.icon}</div>
                        <div style={{ flex: 1, minWidth: 0 }}>
                          <div style={{ fontSize: 13, fontWeight: 700 }}>{u.name}</div>
                          <div style={{ fontSize: 11, color: "rgba(15,23,42,0.5)", marginTop: 1 }}>{u.designation || ucfirst(u.role)}</div>
                          <div style={{ fontSize: 10, color: "rgba(15,23,42,0.5)", marginTop: 2, fontFamily: "'DM Mono', monospace" }}>{u.phone}</div>
                        </div>
                        {apix > 0 && <APIXRing score={apix} size={54} />}
                      </div>
                      <div style={{ marginTop: 14, display: "flex", gap: 6, alignItems: "center" }}>
                        <span className="tag" style={{ background: `${band.color}15`, color: band.color, border: `1px solid ${band.color}30` }}>{band.label}</span>
                        {u.stats && <span style={{ fontSize: 10, color: "rgba(15,23,42,0.5)", fontFamily: "'DM Mono', monospace", marginLeft: "auto" }}>{u.stats.completed}/{u.stats.total_assigned} tasks</span>}
                      </div>
                      <div style={{ marginTop: 10, display: "flex", gap: 6 }}>
                        <button className="btn btn-ghost btn-icon" onClick={() => setEditUser(u)} style={{ flex: 1, justifyContent: "center" }}>✎ Edit</button>
                        {u.role !== 'admin' && <button className="btn btn-danger btn-icon" onClick={() => handleDeleteUser(u)} style={{ flex: 1, justifyContent: "center" }}>🗑 Delete</button>}
                      </div>
                    </div>
                  );
                })}
              </div>
            )}

            {/* ─── ORG TREE ─── */}
            {activeView === "tree" && (
              <div>
                <div style={{ background: "rgba(8,145,178,0.05)", border: "1px solid rgba(8,145,178,0.2)", borderRadius: 10, padding: 14, marginBottom: 16 }}>
                  <div style={{ fontSize: 13, color: "#0891b2", fontWeight: 600, marginBottom: 4 }}>🌳 Organization Hierarchy</div>
                  <div style={{ fontSize: 12, color: "rgba(226,232,240,0.7)" }}>Managers can only assign tasks to their sub-tree. Click ✎ to change reporting.</div>
                </div>
                {tree.length === 0 ? (
                  <div style={{ textAlign: "center", padding: 60, color: "rgba(15,23,42,0.6)", background: "#ffffff", borderRadius: 12, border: "1px solid #e2e8f0" }}>No tree yet</div>
                ) : (
                  <div style={{ background: "#ffffff", border: "1px solid #e2e8f0", borderRadius: 12, padding: 20 }}>
                    {tree.map(node => <TreeNodeView key={node.id} node={node} depth={0} onEdit={u => setEditUser(u)} onDelete={u => handleDeleteUser(u)} />)}
                  </div>
                )}
              </div>
            )}

            {/* ─── AI INSIGHTS ─── */}
            {activeView === "ai" && (
              <div style={{ display: "flex", flexDirection: "column", gap: 20 }}>
                <div style={{ display: "grid", gridTemplateColumns: "repeat(2, 1fr)", gap: 14 }}>
                  {aiInsights.length === 0 ? (
                    <div style={{ gridColumn: "span 2", textAlign: "center", padding: 40, color: "rgba(15,23,42,0.6)", background: "#ffffff", borderRadius: 12 }}>No insights yet</div>
                  ) : aiInsights.map((ins, i) => {
                    const colors: any = { alert: "#f59e0b", risk: "#ef4444", star: "#10b981", insight: "#3b82f6" };
                    const c = colors[ins.type] || "#3b82f6";
                    return (
                      <div key={i} style={{ background: "#ffffff", border: `1px solid ${c}22`, borderLeft: `3px solid ${c}`, borderRadius: "0 12px 12px 0", padding: 18, display: "flex", gap: 14 }}>
                        <span style={{ fontSize: 22 }}>{ins.icon}</span>
                        <div style={{ flex: 1 }}>
                          <div style={{ fontSize: 13, fontWeight: 700, marginBottom: 4 }}>{ins.title}</div>
                          <div style={{ fontSize: 11, color: "rgba(15,23,42,0.5)", lineHeight: 1.5, marginBottom: 10 }}>{ins.desc}</div>
                          <button className="btn" onClick={() => showNotif(`Action: ${ins.action}`)} style={{ fontSize: 11, padding: "5px 12px", background: `${c}15`, color: c, border: `1px solid ${c}30` }}>{ins.action}</button>
                        </div>
                      </div>
                    );
                  })}
                </div>

                {/* APIX Formula */}
                <div style={{ background: "#ffffff", border: "1px solid #e2e8f0", borderRadius: 12, padding: 20 }}>
                  <div style={{ fontSize: 11, color: "rgba(15,23,42,0.5)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase", letterSpacing: 1, marginBottom: 16 }}>APIX Score Formula</div>
                  <div style={{ display: "grid", gridTemplateColumns: "repeat(5, 1fr)", gap: 12 }}>
                    {[
                      { label: "Completion\nRate", weight: "30%", formula: "Done/Assigned × 100", color: "#0891b2" },
                      { label: "Timeliness", weight: "25%", formula: "Avg per-task timing", color: "#10b981" },
                      { label: "AI Quality", weight: "20%", formula: "AI evaluates updates", color: "#a78bfa" },
                      { label: "Consistency", weight: "15%", formula: "Active days / working", color: "#f59e0b" },
                      { label: "Manager\nRating", weight: "10%", formula: "Direct rating", color: "#f97316" },
                    ].map((c, i) => (
                      <div key={i} style={{ textAlign: "center", background: `${c.color}08`, border: `1px solid ${c.color}20`, borderRadius: 10, padding: 14 }}>
                        <div style={{ fontSize: 20, fontWeight: 800, color: c.color, fontFamily: "'Syne', sans-serif" }}>{c.weight}</div>
                        <div style={{ fontSize: 10, fontWeight: 600, marginTop: 4, whiteSpace: "pre-line" }}>{c.label}</div>
                        <div style={{ fontSize: 9, color: "rgba(15,23,42,0.5)", marginTop: 6, lineHeight: 1.4 }}>{c.formula}</div>
                      </div>
                    ))}
                  </div>
                  <div style={{ marginTop: 16, padding: "12px 16px", background: "#fafbfc", borderRadius: 8 }}>
                    <code style={{ fontSize: 12, color: "#a78bfa", fontFamily: "'DM Mono', monospace" }}>
                      APIX = (CR × 0.30) + (TS × 0.25) + (QS × 0.20) + (CS × 0.15) + (MR × 0.10)
                    </code>
                  </div>
                </div>

                {/* Today's AI Report */}
                {aiReport && (
                  <div style={{ background: "#ffffff", border: "1px solid #e2e8f0", borderRadius: 12, padding: 20 }}>
                    <div style={{ fontSize: 11, color: "rgba(15,23,42,0.5)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase", letterSpacing: 1, marginBottom: 16 }}>Today's AI Report · {new Date().toLocaleDateString("en-IN", { day: "2-digit", month: "short" })}</div>
                    <div style={{ fontSize: 12, color: "rgba(15,23,42,0.65)", lineHeight: 2 }}>
                      <div><span style={{ color: "#0891b2", fontFamily: "'DM Mono', monospace" }}>TASKS:</span> {aiReport.tasks_line}</div>
                      <div><span style={{ color: "#10b981", fontFamily: "'DM Mono', monospace" }}>TOP PERFORMER:</span> {aiReport.top_performer}</div>
                      <div><span style={{ color: "#f59e0b", fontFamily: "'DM Mono', monospace" }}>AT RISK:</span> {aiReport.at_risk}</div>
                      <div><span style={{ color: "#ef4444", fontFamily: "'DM Mono', monospace" }}>ACTION NEEDED:</span> {aiReport.action_needed}</div>
                      <div><span style={{ color: "#a78bfa", fontFamily: "'DM Mono', monospace" }}>SUGGESTION:</span> {aiReport.suggestion}</div>
                    </div>
                  </div>
                )}
              </div>
            )}

            {/* ─── REPORTS ─── */}
            {activeView === "reports" && (
              <div style={{ display: "flex", flexDirection: "column", gap: 20 }}>
                <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: 14 }}>
                  {[
                    { type: "Daily", data: dailyReport, icon: "📋" },
                    { type: "Weekly", data: weeklyReport, icon: "📊" },
                    { type: "Monthly", data: monthlyReport, icon: "📈" },
                  ].map((r, i) => (
                    <div key={i} style={{ background: "#ffffff", border: "1px solid #e2e8f0", borderRadius: 12, padding: 20 }}>
                      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", marginBottom: 16 }}>
                        <div>
                          <div style={{ fontSize: 11, color: "rgba(15,23,42,0.5)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase" }}>{r.type} Report</div>
                          <div style={{ fontSize: 13, fontWeight: 700, marginTop: 4 }}>{r.data?.label || "—"}</div>
                        </div>
                        <span style={{ fontSize: 20 }}>{r.icon}</span>
                      </div>
                      <div style={{ display: "flex", flexDirection: "column", gap: 6, marginBottom: 16 }}>
                        {r.data?.stats && Object.entries(r.data.stats).map(([key, value]) => (
                          <div key={key} style={{ display: "flex", justifyContent: "space-between" }}>
                            <span style={{ fontSize: 11, color: "rgba(15,23,42,0.5)", textTransform: "capitalize" }}>{key.replace(/_/g, ' ')}</span>
                            <span style={{ fontSize: 11, fontFamily: "'DM Mono', monospace" }}>{String(value)}</span>
                          </div>
                        ))}
                      </div>
                      <button className="btn btn-ghost" style={{ width: "100%", justifyContent: "center" }} onClick={() => showNotif(`${r.type} report generated`)}>Generate & Share</button>
                    </div>
                  ))}
                </div>

                {/* APIX Trend */}
                {apixTrend.length > 0 && (
                  <div style={{ background: "#ffffff", border: "1px solid #e2e8f0", borderRadius: 12, padding: 20 }}>
                    <div style={{ fontSize: 11, color: "rgba(15,23,42,0.5)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase", letterSpacing: 1, marginBottom: 16 }}>Team APIX Trend — Last 7 Days</div>
                    <div style={{ display: "flex", gap: 24 }}>
                      {apixTrend.map((t, i) => {
                        const colors = ["#0891b2", "#10b981", "#f59e0b"];
                        return (
                          <div key={i} style={{ flex: 1 }}>
                            <div style={{ fontSize: 11, color: colors[i], marginBottom: 6, fontFamily: "'DM Mono', monospace" }}>{t.first_name}</div>
                            <SparkLine data={t.history} color={colors[i]} />
                          </div>
                        );
                      })}
                    </div>
                  </div>
                )}
              </div>
            )}

            {/* ─── LOGS ─── */}
            {activeView === "logs" && (
              <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                {logs.length === 0 ? (
                  <div style={{ textAlign: "center", padding: 60, color: "rgba(15,23,42,0.6)" }}>No activity yet</div>
                ) : logs.map(log => {
                  const color = LOG_STATUS_COLOR(log.status);
                  return (
                    <div key={log.id} style={{ background: "#ffffff", borderLeft: `3px solid ${color}`, border: "1px solid #e2e8f0", borderRadius: 8, padding: "12px 16px" }}>
                      <div style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 4 }}>
                        <span>{LOG_ICON(log.type, log.status)}</span>
                        <span style={{ fontSize: 10, padding: "2px 6px", background: `${color}20`, color, borderRadius: 4, fontFamily: "'DM Mono', monospace", textTransform: "uppercase" }}>{log.type}</span>
                        <span style={{ marginLeft: "auto", fontSize: 10, color: "rgba(15,23,42,0.55)", fontFamily: "'DM Mono', monospace" }}>{formatTime(log.created_at)}</span>
                      </div>
                      <div style={{ fontSize: 13, marginLeft: 24 }}>{log.message}</div>
                      {log.phone && <div style={{ fontSize: 11, color: "rgba(15,23,42,0.6)", marginLeft: 24, marginTop: 2, fontFamily: "'DM Mono', monospace" }}>📱 {log.phone}</div>}
                    </div>
                  );
                })}
              </div>
            )}

            {/* SETTINGS */}
            {activeView === "settings" && (
              <div style={{ maxWidth: 600 }}>
                <div style={{ background: "#ffffff", border: "1px solid #e2e8f0", borderRadius: 12, padding: 24 }}>
                  <h2 style={{ fontFamily: "'Syne', sans-serif", fontSize: 16, fontWeight: 700, marginBottom: 4 }}>Your Profile</h2>
                  <div style={{ fontSize: 11, color: "rgba(15,23,42,0.55)", marginBottom: 20 }}>Update phone for WhatsApp</div>
                  {user && <button className="btn btn-primary" onClick={() => { const c = allUsers.find(u => u.email === user.email); if (c) setEditUser(c); }}>✎ Edit My Profile</button>}
                </div>
              </div>
            )}
          </div>
        </main>
      </div>

      {showAddUser && <UserModal allUsers={allUsers} onClose={() => setShowAddUser(false)} onSuccess={() => { showNotif("✅ Added!"); loadData(); }} />}
      {editUser && <UserModal user={editUser} allUsers={allUsers} onClose={() => setEditUser(null)} onSuccess={() => { showNotif("✅ Updated!"); loadData(); }} />}
      {showNewTask && <NewTaskModal employees={allUsers} onClose={() => setShowNewTask(false)} onSuccess={() => { showNotif("✅ Assigned!"); loadData(); }} />}

      {notification && (
        <div style={{ position: "fixed", bottom: 24, right: 24, zIndex: 200, background: notification.type === 'success' ? "rgba(16,185,129,0.15)" : "rgba(239,68,68,0.15)", border: notification.type === 'success' ? "1px solid rgba(16,185,129,0.3)" : "1px solid rgba(239,68,68,0.3)", borderRadius: 10, padding: "12px 18px", fontSize: 13, backdropFilter: "blur(12px)" }}>{notification.msg}</div>
      )}
    </>
  );
}

function ucfirst(s: string) { return s.charAt(0).toUpperCase() + s.slice(1); }

function TreeNodeView({ node, depth, onEdit, onDelete }: { node: TreeNode; depth: number; onEdit: (u: User) => void; onDelete: (u: User) => void }) {
  const [expanded, setExpanded] = useState(true);
  const role = ROLE_CONFIG[node.role] || ROLE_CONFIG.employee;
  const hasChildren = node.children && node.children.length > 0;

  return (
    <div style={{ marginLeft: depth > 0 ? 24 : 0, marginBottom: 4 }}>
      <div style={{ display: "flex", alignItems: "center", gap: 12, padding: "10px 14px", background: depth === 0 ? "rgba(8,145,178,0.05)" : "#fafbfc", borderRadius: 10, border: `1px solid ${depth === 0 ? "rgba(8,145,178,0.2)" : "#e2e8f0"}` }}>
        {hasChildren ? (
          <button onClick={() => setExpanded(!expanded)} style={{ background: "transparent", border: "none", color: "rgba(15,23,42,0.65)", cursor: "pointer", fontSize: 12, width: 18 }}>{expanded ? '▼' : '▶'}</button>
        ) : (
          <span style={{ width: 18, fontSize: 10, color: "rgba(226,232,240,0.2)", textAlign: "center" }}>·</span>
        )}
        <div style={{ width: 32, height: 32, borderRadius: "50%", background: `${role.color}20`, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 14, flexShrink: 0 }}>{role.icon}</div>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
            <span style={{ fontWeight: 600, fontSize: 13 }}>{node.name}</span>
            <span style={{ padding: "1px 6px", borderRadius: 3, fontSize: 9, background: `${role.color}15`, color: role.color, fontFamily: "'DM Mono', monospace" }}>{role.label}</span>
          </div>
          <div style={{ fontSize: 10, color: "rgba(15,23,42,0.55)", fontFamily: "'DM Mono', monospace", marginTop: 2 }}>{node.phone}</div>
        </div>
        <div style={{ display: "flex", gap: 16, fontSize: 11, fontFamily: "'DM Mono', monospace" }}>
          <div style={{ textAlign: "center" }}>
            <div style={{ color: "rgba(15,23,42,0.55)", fontSize: 9 }}>DONE</div>
            <div style={{ color: "#10b981", fontWeight: 600 }}>{node.stats.completed}</div>
          </div>
          <div style={{ textAlign: "center" }}>
            <div style={{ color: "rgba(15,23,42,0.55)", fontSize: 9 }}>PENDING</div>
            <div style={{ color: "#f59e0b", fontWeight: 600 }}>{node.stats.pending}</div>
          </div>
          {node.stats.overdue > 0 && (
            <div style={{ textAlign: "center" }}>
              <div style={{ color: "rgba(15,23,42,0.55)", fontSize: 9 }}>OVERDUE</div>
              <div style={{ color: "#ef4444", fontWeight: 600 }}>{node.stats.overdue}</div>
            </div>
          )}
        </div>
        <div style={{ display: "flex", gap: 4 }}>
          <button className="btn btn-ghost btn-icon" onClick={() => onEdit(node)}>✎</button>
          {node.role !== 'admin' && <button className="btn btn-danger btn-icon" onClick={() => onDelete(node)}>🗑</button>}
        </div>
      </div>
      {expanded && hasChildren && (
        <div style={{ marginTop: 4, paddingLeft: 8, borderLeft: "1px dashed #e2e8f0" }}>
          {node.children.map(child => <TreeNodeView key={child.id} node={child} depth={depth + 1} onEdit={onEdit} onDelete={onDelete} />)}
        </div>
      )}
    </div>
  );
}

function UserModal({ user, allUsers, onClose, onSuccess }: { user?: User; allUsers: User[]; onClose: () => void; onSuccess: () => void }) {
  const isEdit = !!user;
  const [name, setName] = useState(user?.name || "");
  const [phone, setPhone] = useState(user?.phone || "+91");
  const [designation, setDesignation] = useState(user?.designation || "");
  const [email, setEmail] = useState(user?.email || "");
  const [department, setDepartment] = useState(user?.department || "");
  const [role, setRole] = useState(user?.role || "employee");
  const [reportsTo, setReportsTo] = useState(user?.reports_to || "");
  const [isActive, setIsActive] = useState(user?.is_active ?? true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const possibleManagers = allUsers.filter(u => (u.role === 'admin' || u.role === 'manager') && u.is_active && u.id !== user?.id);

  const handleSubmit = async () => {
    setError("");
    if (!name.trim() || !phone.trim()) { setError("Name and phone required"); return; }
    let cleanPhone = phone.replace(/[\s\-()]/g, '');
    if (!cleanPhone.startsWith('+')) {
      if (cleanPhone.length === 10) cleanPhone = '+91' + cleanPhone;
      else if (cleanPhone.startsWith('91') && cleanPhone.length === 12) cleanPhone = '+' + cleanPhone;
    }
    setSaving(true);
    try {
      const payload: any = { name: name.trim(), phone: cleanPhone, role };
      if (designation.trim()) payload.designation = designation.trim();
      if (department.trim()) payload.department = department.trim();
      if (email.trim()) payload.email = email.trim();
      payload.reports_to = reportsTo || null;
      if (isEdit) payload.is_active = isActive;
      if (isEdit) await api.put(`/users/${user!.id}`, payload);
      else await api.post("/users", payload);
      onSuccess(); onClose();
    } catch (err: any) {
      let m = isEdit ? "Failed to update" : "Failed to add";
      if (err.response?.data?.message) m = err.response.data.message;
      setError(m);
    } finally { setSaving(false); }
  };

  return (
    <div style={{ position: "fixed", inset: 0, background: "rgba(0,0,0,0.7)", display: "flex", alignItems: "center", justifyContent: "center", zIndex: 100, backdropFilter: "blur(4px)" }} onClick={onClose}>
      <div style={{ background: "#f1f5f9", border: "1px solid #cbd5e1", borderRadius: 16, padding: 28, width: 480, maxHeight: "90vh", overflow: "auto" }} onClick={e => e.stopPropagation()}>
        <h2 style={{ fontFamily: "'Syne', sans-serif", fontSize: 18, fontWeight: 700, marginBottom: 4 }}>{isEdit ? `Edit ${user.name}` : "Add User"}</h2>
        <div style={{ fontSize: 11, color: "rgba(15,23,42,0.5)", marginBottom: 20 }}>{isEdit ? "Update details" : "WhatsApp welcome will be sent"}</div>
        <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
          <input placeholder="Full Name *" value={name} onChange={e => setName(e.target.value)} />
          <input placeholder="+919876543210 *" value={phone} onChange={e => setPhone(e.target.value)} />
          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12 }}>
            <select value={role} onChange={e => setRole(e.target.value)}>
              <option value="employee">Employee</option><option value="manager">Manager</option><option value="admin">Admin</option>
            </select>
            {isEdit && <select value={isActive ? "active" : "inactive"} onChange={e => setIsActive(e.target.value === "active")}><option value="active">Active</option><option value="inactive">Inactive</option></select>}
          </div>
          <div>
            <label style={{ fontSize: 10, color: "rgba(15,23,42,0.6)", display: "block", marginBottom: 4, fontFamily: "'DM Mono', monospace", textTransform: "uppercase" }}>Reports To</label>
            <select value={reportsTo || ""} onChange={e => setReportsTo(e.target.value)}>
              <option value="">— Root —</option>
              {possibleManagers.map(m => <option key={m.id} value={m.id}>{m.name} ({m.role})</option>)}
            </select>
          </div>
          <input placeholder="Designation" value={designation} onChange={e => setDesignation(e.target.value)} />
          <input placeholder="Department" value={department} onChange={e => setDepartment(e.target.value)} />
          <input placeholder="Email (optional)" value={email} onChange={e => setEmail(e.target.value)} />
          {error && <div style={{ fontSize: 12, color: "#ef4444", padding: "8px 12px", background: "rgba(239,68,68,0.1)", borderRadius: 6 }}>⚠️ {error}</div>}
          <div style={{ display: "flex", gap: 8 }}>
            <button className="btn btn-ghost" style={{ flex: 1, justifyContent: "center" }} onClick={onClose}>Cancel</button>
            <button className="btn btn-primary" style={{ flex: 2, justifyContent: "center" }} onClick={handleSubmit} disabled={saving}>{saving ? "Saving..." : (isEdit ? "Update" : "Add")}</button>
          </div>
        </div>
      </div>
    </div>
  );
}

function NewTaskModal({ employees, onClose, onSuccess }: { employees: User[]; onClose: () => void; onSuccess: () => void }) {
  const [title, setTitle] = useState("");
  const [assignedTo, setAssignedTo] = useState("");
  const [priority, setPriority] = useState("medium");
  const [dueDate, setDueDate] = useState("");
  const [points, setPoints] = useState(50);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const handleSubmit = async () => {
    setError(""); setSaving(true);
    try {
      await api.post("/tasks", { title, assigned_to: assignedTo, priority, due_date: dueDate || null, reward_points: points });
      onSuccess(); onClose();
    } catch (err: any) { setError(err.response?.data?.message || "Failed"); }
    finally { setSaving(false); }
  };

  return (
    <div style={{ position: "fixed", inset: 0, background: "rgba(0,0,0,0.7)", display: "flex", alignItems: "center", justifyContent: "center", zIndex: 100, backdropFilter: "blur(4px)" }} onClick={onClose}>
      <div style={{ background: "#f1f5f9", border: "1px solid #cbd5e1", borderRadius: 16, padding: 28, width: 440 }} onClick={e => e.stopPropagation()}>
        <h2 style={{ fontFamily: "'Syne', sans-serif", fontSize: 18, fontWeight: 700, marginBottom: 4 }}>New Task</h2>
        <div style={{ fontSize: 11, color: "rgba(15,23,42,0.5)", marginBottom: 20 }}>WhatsApp will go to assignee</div>
        <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
          <input placeholder="Task title" value={title} onChange={e => setTitle(e.target.value)} />
          <select value={assignedTo} onChange={e => setAssignedTo(e.target.value)}>
            <option value="">Select user</option>
            {employees.map(e => <option key={e.id} value={e.id}>{e.name}</option>)}
          </select>
          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12 }}>
            <select value={priority} onChange={e => setPriority(e.target.value)}>
              <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option><option value="critical">Critical</option>
            </select>
            <input type="number" value={points} onChange={e => setPoints(parseInt(e.target.value) || 50)} placeholder="Points" />
          </div>
          <input type="datetime-local" value={dueDate} onChange={e => setDueDate(e.target.value)} />
          {error && <div style={{ fontSize: 12, color: "#ef4444", padding: "8px 12px", background: "rgba(239,68,68,0.1)", borderRadius: 6 }}>{error}</div>}
          <div style={{ display: "flex", gap: 8 }}>
            <button className="btn btn-ghost" style={{ flex: 1, justifyContent: "center" }} onClick={onClose}>Cancel</button>
            <button className="btn btn-primary" style={{ flex: 2, justifyContent: "center" }} onClick={handleSubmit} disabled={saving || !title || !assignedTo}>{saving ? "..." : "Assign + WhatsApp"}</button>
          </div>
        </div>
      </div>
    </div>
  );
}

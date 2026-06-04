import { useState, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import { useAuthStore } from "../store/auth";
import api from "../shared/api/client";

interface Overview {
  total_tasks: number;
  open_tasks: number;
  completed_tasks: number;
  overdue_tasks: number;
  team_productivity: number;
  active_employees: number;
}

interface User {
  id: string;
  name: string;
  email: string;
  phone: string;
  role: string;
  department?: string;
  designation?: string;
  is_active: boolean;
  last_seen_at?: string;
}

interface Task {
  id: string;
  title: string;
  description?: string;
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
  meta?: any;
  phone?: string;
  created_at: string;
}

const STATUS_CONFIG: Record<string, { label: string; color: string; bg: string }> = {
  assigned:    { label: "Assigned",    color: "#64748b", bg: "rgba(100,116,139,0.12)" },
  accepted:    { label: "Accepted",    color: "#3b82f6", bg: "rgba(59,130,246,0.12)" },
  in_progress: { label: "In Progress", color: "#f59e0b", bg: "rgba(245,158,11,0.12)" },
  waiting:     { label: "Waiting",     color: "#8b5cf6", bg: "rgba(139,92,246,0.12)" },
  completed:   { label: "Completed",   color: "#10b981", bg: "rgba(16,185,129,0.12)" },
  verified:    { label: "Verified",    color: "#06b6d4", bg: "rgba(6,182,212,0.12)" },
  rejected:    { label: "Rejected",    color: "#ef4444", bg: "rgba(239,68,68,0.12)" },
  escalated:   { label: "Escalated",   color: "#f97316", bg: "rgba(249,115,22,0.12)" },
};

const ROLE_CONFIG: Record<string, { label: string; color: string }> = {
  admin:    { label: "Admin",    color: "#22d3ee" },
  manager:  { label: "Manager",  color: "#f59e0b" },
  employee: { label: "Employee", color: "#10b981" },
};

const LOG_STATUS_COLOR = (status: string) => {
  if (status === 'success') return '#10b981';
  if (status === 'failed') return '#ef4444';
  return '#f59e0b';
};

const LOG_ICON = (type: string, status: string) => {
  if (status === 'failed') return '❌';
  if (type === 'whatsapp_out' && status === 'success') return '✅';
  if (type === 'whatsapp_in') return '📩';
  if (type === 'task') return '📋';
  if (type === 'user') return '👤';
  return 'ℹ️';
};

export default function Dashboard() {
  const [activeView, setActiveView] = useState<"overview" | "tasks" | "employees" | "settings" | "logs">("overview");
  const [overview, setOverview] = useState<Overview | null>(null);
  const [tasks, setTasks] = useState<Task[]>([]);
  const [allUsers, setAllUsers] = useState<User[]>([]);
  const [logs, setLogs] = useState<ActivityLogEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [showAddUser, setShowAddUser] = useState(false);
  const [showNewTask, setShowNewTask] = useState(false);
  const [editUser, setEditUser] = useState<User | null>(null);
  const [notification, setNotification] = useState<{ msg: string; type: 'success' | 'error' } | null>(null);
  const [logsPanelOpen, setLogsPanelOpen] = useState(true);

  const { user, logout } = useAuthStore();
  const navigate = useNavigate();

  const showNotif = (msg: string, type: 'success' | 'error' = 'success') => {
    setNotification({ msg, type });
    setTimeout(() => setNotification(null), 3000);
  };

  const employees = allUsers.filter(u => u.role === "employee");
  const managers = allUsers.filter(u => u.role === "manager" || u.role === "admin");

  const loadData = async () => {
    try {
      setLoading(true);
      const [ovRes, tasksRes, usersRes, logsRes] = await Promise.all([
        api.get("/analytics/overview").catch(() => ({ data: null })),
        api.get("/tasks").catch(() => ({ data: { data: [] } })),
        api.get("/users").catch(() => ({ data: [] })),
        api.get("/logs").catch(() => ({ data: [] })),
      ]);
      if (ovRes.data) setOverview(ovRes.data);
      setTasks(tasksRes.data?.data || tasksRes.data || []);
      setAllUsers(usersRes.data || []);
      setLogs(logsRes.data || []);
    } catch (err) {
      console.error("Failed to load data", err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadData();
    const interval = setInterval(loadData, 10000); // 10s for faster log updates
    return () => clearInterval(interval);
  }, []);

  const handleLogout = () => {
    logout();
    navigate("/login");
  };

  const handleDeleteUser = async (userToDelete: User) => {
    if (userToDelete.role === 'admin') {
      showNotif("Admin user can't be deleted. Edit instead to deactivate.", 'error');
      return;
    }
    if (!confirm(`Delete ${userToDelete.name}? They'll be deactivated.`)) return;
    
    try {
      await api.delete(`/users/${userToDelete.id}`);
      showNotif(`${userToDelete.name} deactivated`);
      loadData();
    } catch (err: any) {
      showNotif(err.response?.data?.message || "Failed to delete", 'error');
    }
  };

  const handleClearLogs = async () => {
    if (!confirm("Clear all activity logs?")) return;
    try {
      await api.delete("/logs");
      showNotif("Logs cleared");
      setLogs([]);
    } catch (err: any) {
      showNotif("Failed to clear", 'error');
    }
  };

  const formatTime = (iso: string) => {
    const d = new Date(iso);
    const now = new Date();
    const diffSec = Math.floor((now.getTime() - d.getTime()) / 1000);
    if (diffSec < 60) return `${diffSec}s ago`;
    if (diffSec < 3600) return `${Math.floor(diffSec / 60)}m ago`;
    if (diffSec < 86400) return `${Math.floor(diffSec / 3600)}h ago`;
    return d.toLocaleDateString();
  };

  const css = `
    @import url('https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&family=Inter:wght@300;400;500;600&display=swap');
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #080c14; color: #e2e8f0; font-family: 'Inter', sans-serif; }
    ::-webkit-scrollbar { width: 4px; height: 4px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 2px; }
    .fade-in { animation: fadeIn 0.3s ease; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    input, select, textarea {
      background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.10);
      border-radius: 8px; color: #e2e8f0; font-family: 'Inter', sans-serif;
      font-size: 13px; padding: 8px 12px; outline: none; width: 100%;
    }
    input:focus, select:focus, textarea:focus { border-color: #22d3ee; }
    select option { background: #1a2035; }
    .btn { padding: 7px 14px; border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer; border: none; transition: opacity 0.2s; }
    .btn:hover { opacity: 0.85; }
    .btn-primary { background: #22d3ee; color: #080c14; }
    .btn-ghost { background: rgba(255,255,255,0.06); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.10); }
    .btn-danger { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }
    .btn-icon { padding: 4px 8px; font-size: 11px; }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
    .pulse-dot { animation: pulse 2s infinite; }
  `;

  return (
    <>
      <style dangerouslySetInnerHTML={{ __html: css }} />
      <div style={{ display: "flex", height: "100vh", overflow: "hidden", fontFamily: "'Inter', sans-serif" }}>
        <aside style={{
          width: 220, background: "#0e1420", borderRight: "1px solid rgba(255,255,255,0.06)",
          display: "flex", flexDirection: "column", flexShrink: 0,
        }}>
          <div style={{ padding: "20px 20px 16px", borderBottom: "1px solid rgba(255,255,255,0.06)" }}>
            <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
              <div style={{
                width: 34, height: 34, borderRadius: 10,
                background: "linear-gradient(135deg, #22d3ee, #0891b2)",
                display: "flex", alignItems: "center", justifyContent: "center",
                fontSize: 16, fontWeight: 800, color: "#080c14", fontFamily: "'Syne', sans-serif",
              }}>TF</div>
              <div>
                <div style={{ fontFamily: "'Syne', sans-serif", fontWeight: 800, fontSize: 15 }}>TaskFlow</div>
                <div style={{ fontSize: 10, color: "#22d3ee", fontFamily: "'DM Mono', monospace" }}>WhatsApp OS</div>
              </div>
            </div>
          </div>
          <nav style={{ padding: "8px", flex: 1 }}>
            {[
              { id: "overview" as const,  icon: "⊞", label: "Overview" },
              { id: "tasks" as const,     icon: "✓", label: "Tasks" },
              { id: "employees" as const, icon: "◉", label: "People" },
              { id: "logs" as const,      icon: "📊", label: "Live Logs" },
              { id: "settings" as const,  icon: "⚙", label: "Settings" },
            ].map(item => (
              <button key={item.id} onClick={() => setActiveView(item.id)} style={{
                width: "100%", display: "flex", alignItems: "center", gap: 10, padding: "9px 12px",
                borderRadius: 8, border: "none", cursor: "pointer", marginBottom: 2,
                background: activeView === item.id ? "rgba(34,211,238,0.1)" : "transparent",
                color: activeView === item.id ? "#22d3ee" : "rgba(226,232,240,0.6)",
                fontSize: 13, fontWeight: activeView === item.id ? 600 : 400, textAlign: "left",
                fontFamily: "'Inter', sans-serif",
              }}>
                <span style={{ fontSize: 14, width: 18, textAlign: "center" }}>{item.icon}</span>
                {item.label}
                {item.id === "logs" && logs.length > 0 && (
                  <span style={{ marginLeft: "auto", fontSize: 10, padding: "2px 6px", background: "rgba(34,211,238,0.2)", color: "#22d3ee", borderRadius: 4 }}>{logs.length}</span>
                )}
              </button>
            ))}
          </nav>
          <div style={{ padding: 12, borderTop: "1px solid rgba(255,255,255,0.06)" }}>
            <div style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 8 }}>
              <div style={{
                width: 32, height: 32, borderRadius: "50%",
                background: "linear-gradient(135deg, #10b981, #059669)",
                display: "flex", alignItems: "center", justifyContent: "center",
                fontSize: 12, fontWeight: 700, color: "#fff"
              }}>{user?.name?.charAt(0) || "A"}</div>
              <div style={{ minWidth: 0 }}>
                <div style={{ fontSize: 12, fontWeight: 600, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{user?.name}</div>
                <div style={{ fontSize: 10, color: "rgba(226,232,240,0.35)" }}>{user?.role}</div>
              </div>
            </div>
            <button className="btn btn-ghost" onClick={handleLogout} style={{ width: "100%", fontSize: 11, padding: "5px 10px" }}>
              Logout
            </button>
          </div>
        </aside>

        <main style={{ flex: 1, overflow: "auto", background: "#080c14" }}>
          <header style={{
            padding: "14px 24px", borderBottom: "1px solid rgba(255,255,255,0.06)",
            display: "flex", alignItems: "center", justifyContent: "space-between",
            position: "sticky", top: 0, background: "rgba(8,12,20,0.92)", backdropFilter: "blur(12px)", zIndex: 10,
          }}>
            <div>
              <h1 style={{ fontFamily: "'Syne', sans-serif", fontSize: 18, fontWeight: 700 }}>
                {activeView === "overview" && "Dashboard Overview"}
                {activeView === "tasks" && "Task Management"}
                {activeView === "employees" && "People Management"}
                {activeView === "logs" && "Live Activity Logs"}
                {activeView === "settings" && "Settings & Admin"}
              </h1>
              <div style={{ fontSize: 11, color: "rgba(226,232,240,0.35)", fontFamily: "'DM Mono', monospace", marginTop: 2, display: "flex", alignItems: "center", gap: 6 }}>
                <span className="pulse-dot" style={{ display: "inline-block", width: 6, height: 6, background: "#10b981", borderRadius: "50%" }}></span>
                Live · Refresh every 10s
              </div>
            </div>
            <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
              <button className="btn btn-ghost" onClick={loadData}>↻ Refresh</button>
              {activeView === "employees" && (
                <button className="btn btn-primary" onClick={() => setShowAddUser(true)}>+ Add User</button>
              )}
              {activeView === "tasks" && (
                <button className="btn btn-primary" onClick={() => setShowNewTask(true)}>+ New Task</button>
              )}
              {activeView === "logs" && (
                <button className="btn btn-danger" onClick={handleClearLogs}>🗑 Clear Logs</button>
              )}
            </div>
          </header>

          <div className="fade-in" style={{ padding: 24 }}>
            {loading && !overview && (
              <div style={{ textAlign: "center", padding: 40, color: "rgba(226,232,240,0.5)" }}>
                Loading data...
              </div>
            )}

            {/* OVERVIEW */}
            {activeView === "overview" && overview && (
              <div style={{ display: "flex", flexDirection: "column", gap: 20 }}>
                <div style={{ display: "grid", gridTemplateColumns: "repeat(5, 1fr)", gap: 12 }}>
                  {[
                    { label: "Total Tasks",  value: overview.total_tasks || 0,        color: "#22d3ee" },
                    { label: "Open Tasks",   value: overview.open_tasks || 0,         color: "#3b82f6" },
                    { label: "Completed",    value: overview.completed_tasks || 0,    color: "#10b981" },
                    { label: "Overdue",      value: overview.overdue_tasks || 0,      color: "#ef4444" },
                    { label: "Active Users", value: allUsers.filter(u => u.is_active).length, color: "#f59e0b" },
                  ].map((kpi, i) => (
                    <div key={i} style={{
                      background: "#0e1420", border: "1px solid rgba(255,255,255,0.06)", borderRadius: 12, padding: 16,
                    }}>
                      <div style={{ fontSize: 10, color: "rgba(226,232,240,0.35)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase", letterSpacing: 1 }}>{kpi.label}</div>
                      <div style={{ fontSize: 28, fontWeight: 800, color: kpi.color, fontFamily: "'Syne', sans-serif", marginTop: 6 }}>{kpi.value}</div>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* LOGS — Live Activity Stream */}
            {activeView === "logs" && (
              <div>
                <div style={{ background: "rgba(34,211,238,0.05)", border: "1px solid rgba(34,211,238,0.2)", borderRadius: 10, padding: 14, marginBottom: 16 }}>
                  <div style={{ fontSize: 13, color: "#22d3ee", fontWeight: 600, marginBottom: 4 }}>📊 Live System Activity</div>
                  <div style={{ fontSize: 12, color: "rgba(226,232,240,0.7)" }}>
                    All actions tracked live — WhatsApp sends, task creates, errors. Auto-refresh every 10s.
                    {logs.length > 0 && ` Showing latest ${logs.length} events.`}
                  </div>
                </div>

                {logs.length === 0 ? (
                  <div style={{ textAlign: "center", padding: 60, color: "rgba(226,232,240,0.5)", background: "#0e1420", borderRadius: 12, border: "1px solid rgba(255,255,255,0.06)" }}>
                    <div style={{ fontSize: 14, marginBottom: 8 }}>No activity yet</div>
                    <div style={{ fontSize: 12 }}>Create a task or trigger an event to see logs here</div>
                  </div>
                ) : (
                  <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                    {logs.map(log => {
                      const color = LOG_STATUS_COLOR(log.status);
                      return (
                        <div key={log.id} style={{
                          background: "#0e1420",
                          borderLeft: `3px solid ${color}`,
                          border: "1px solid rgba(255,255,255,0.06)",
                          borderRadius: 8,
                          padding: "12px 16px",
                        }}>
                          <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 12, marginBottom: 4 }}>
                            <div style={{ display: "flex", alignItems: "center", gap: 8, flex: 1, minWidth: 0 }}>
                              <span style={{ fontSize: 16 }}>{LOG_ICON(log.type, log.status)}</span>
                              <span style={{ fontSize: 10, padding: "2px 6px", background: `${color}20`, color: color, borderRadius: 4, fontFamily: "'DM Mono', monospace", textTransform: "uppercase" }}>{log.type}</span>
                              <span style={{ fontSize: 10, color: "rgba(226,232,240,0.4)", fontFamily: "'DM Mono', monospace" }}>{log.action}</span>
                            </div>
                            <span style={{ fontSize: 10, color: "rgba(226,232,240,0.4)", fontFamily: "'DM Mono', monospace", flexShrink: 0 }}>{formatTime(log.created_at)}</span>
                          </div>
                          <div style={{ fontSize: 13, color: "#e2e8f0", marginLeft: 24 }}>{log.message}</div>
                          {log.phone && (
                            <div style={{ fontSize: 11, color: "rgba(226,232,240,0.5)", marginLeft: 24, marginTop: 2, fontFamily: "'DM Mono', monospace" }}>📱 {log.phone}</div>
                          )}
                          {log.meta && Object.keys(log.meta).length > 0 && (
                            <details style={{ marginLeft: 24, marginTop: 6 }}>
                              <summary style={{ fontSize: 10, color: "rgba(226,232,240,0.4)", cursor: "pointer", fontFamily: "'DM Mono', monospace" }}>View details</summary>
                              <pre style={{ fontSize: 10, color: "rgba(226,232,240,0.6)", background: "rgba(0,0,0,0.3)", padding: 8, borderRadius: 4, marginTop: 4, overflow: "auto", maxHeight: 200 }}>
                                {JSON.stringify(log.meta, null, 2)}
                              </pre>
                            </details>
                          )}
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>
            )}

            {/* TASKS */}
            {activeView === "tasks" && (
              <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
                {tasks.length === 0 ? (
                  <div style={{ textAlign: "center", padding: 60, color: "rgba(226,232,240,0.5)", background: "#0e1420", borderRadius: 12, border: "1px solid rgba(255,255,255,0.06)" }}>
                    <div style={{ fontSize: 14, marginBottom: 8 }}>No tasks yet</div>
                  </div>
                ) : tasks.map(task => {
                  const sc = STATUS_CONFIG[task.status] || STATUS_CONFIG.assigned;
                  return (
                    <div key={task.id} style={{
                      background: "#0e1420", border: "1px solid rgba(255,255,255,0.06)", borderRadius: 12,
                      padding: "14px 18px", display: "flex", alignItems: "center", gap: 16,
                    }}>
                      <span style={{ padding: "2px 8px", borderRadius: 4, fontSize: 11, fontFamily: "'DM Mono', monospace", background: sc.bg, color: sc.color, border: `1px solid ${sc.color}40`, minWidth: 88, textAlign: "center" }}>
                        {sc.label}
                      </span>
                      <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 2 }}>{task.title}</div>
                        <div style={{ fontSize: 11, color: "rgba(226,232,240,0.35)" }}>
                          {task.assigned_to?.name || "Unassigned"} · ID: T-{task.id.substring(0, 6)}
                        </div>
                      </div>
                      <div style={{ fontSize: 12, color: "#f59e0b", fontFamily: "'DM Mono', monospace" }}>⭐ {task.reward_points}</div>
                    </div>
                  );
                })}
              </div>
            )}

            {/* PEOPLE */}
            {activeView === "employees" && (
              <div>
                <div style={{ display: "flex", gap: 8, marginBottom: 16, padding: "0 4px" }}>
                  <div style={{ fontSize: 12, color: "rgba(226,232,240,0.5)", padding: "6px 0" }}>
                    Total: <strong style={{ color: "#22d3ee" }}>{allUsers.length}</strong> · 
                    Admins/Managers: <strong style={{ color: "#f59e0b" }}>{managers.length}</strong> · 
                    Employees: <strong style={{ color: "#10b981" }}>{employees.length}</strong>
                  </div>
                </div>
                {allUsers.length === 0 ? (
                  <div style={{ textAlign: "center", padding: 60, color: "rgba(226,232,240,0.5)", background: "#0e1420", borderRadius: 12, border: "1px solid rgba(255,255,255,0.06)" }}>
                    <div style={{ fontSize: 14, marginBottom: 8 }}>No users yet</div>
                  </div>
                ) : (
                  <div style={{ background: "#0e1420", border: "1px solid rgba(255,255,255,0.06)", borderRadius: 12, overflow: "hidden" }}>
                    <div style={{ display: "grid", gridTemplateColumns: "2fr 1.5fr 1.5fr 1fr 1fr 100px", padding: "12px 18px", gap: 12, borderBottom: "1px solid rgba(255,255,255,0.06)", background: "rgba(255,255,255,0.02)", fontSize: 10, color: "rgba(226,232,240,0.5)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase", letterSpacing: 1 }}>
                      <div>Name</div>
                      <div>Phone</div>
                      <div>Designation</div>
                      <div>Role</div>
                      <div>Status</div>
                      <div style={{ textAlign: "right" }}>Actions</div>
                    </div>
                    {allUsers.map(u => {
                      const role = ROLE_CONFIG[u.role] || ROLE_CONFIG.employee;
                      return (
                        <div key={u.id} style={{ display: "grid", gridTemplateColumns: "2fr 1.5fr 1.5fr 1fr 1fr 100px", padding: "14px 18px", gap: 12, borderBottom: "1px solid rgba(255,255,255,0.04)", alignItems: "center", fontSize: 13 }}>
                          <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
                            <div style={{ width: 32, height: 32, borderRadius: "50%", background: `${role.color}20`, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 12, fontWeight: 700, color: role.color, flexShrink: 0 }}>{u.name.charAt(0)}</div>
                            <div style={{ minWidth: 0 }}>
                              <div style={{ fontWeight: 600 }}>{u.name}</div>
                              <div style={{ fontSize: 10, color: "rgba(226,232,240,0.4)" }}>{u.email}</div>
                            </div>
                          </div>
                          <div style={{ fontSize: 12, fontFamily: "'DM Mono', monospace", color: "rgba(226,232,240,0.7)" }}>{u.phone}</div>
                          <div style={{ fontSize: 12, color: "rgba(226,232,240,0.7)" }}>{u.designation || '—'}</div>
                          <div>
                            <span style={{ padding: "2px 8px", borderRadius: 4, fontSize: 10, background: `${role.color}15`, color: role.color, fontFamily: "'DM Mono', monospace", border: `1px solid ${role.color}30` }}>{role.label}</span>
                          </div>
                          <div>
                            <span style={{ padding: "2px 8px", borderRadius: 4, fontSize: 10, background: u.is_active ? "rgba(16,185,129,0.15)" : "rgba(100,116,139,0.15)", color: u.is_active ? "#10b981" : "#64748b", fontFamily: "'DM Mono', monospace" }}>{u.is_active ? "Active" : "Inactive"}</span>
                          </div>
                          <div style={{ display: "flex", gap: 4, justifyContent: "flex-end" }}>
                            <button className="btn btn-ghost btn-icon" onClick={() => setEditUser(u)} title="Edit">✎</button>
                            {u.role !== 'admin' && (
                              <button className="btn btn-danger btn-icon" onClick={() => handleDeleteUser(u)} title="Delete">🗑</button>
                            )}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>
            )}

            {/* SETTINGS */}
            {activeView === "settings" && (
              <div style={{ maxWidth: 600 }}>
                <div style={{ background: "#0e1420", border: "1px solid rgba(255,255,255,0.06)", borderRadius: 12, padding: 24 }}>
                  <h2 style={{ fontFamily: "'Syne', sans-serif", fontSize: 16, fontWeight: 700, marginBottom: 4 }}>Your Profile</h2>
                  <div style={{ fontSize: 11, color: "rgba(226,232,240,0.4)", marginBottom: 20 }}>Update phone for WhatsApp commands</div>
                  {user && (
                    <button className="btn btn-primary" onClick={() => { const current = allUsers.find(u => u.email === user.email); if (current) setEditUser(current); }}>
                      ✎ Edit My Profile
                    </button>
                  )}
                </div>
              </div>
            )}
          </div>
        </main>

        {/* Logs side panel — visible on all views except logs tab */}
        {activeView !== "logs" && logsPanelOpen && (
          <aside style={{
            width: 340, background: "#0a0f18", borderLeft: "1px solid rgba(255,255,255,0.06)",
            display: "flex", flexDirection: "column", flexShrink: 0,
          }}>
            <div style={{ padding: "14px 16px", borderBottom: "1px solid rgba(255,255,255,0.06)", display: "flex", alignItems: "center", justifyContent: "space-between" }}>
              <div>
                <div style={{ fontSize: 13, fontWeight: 600, fontFamily: "'Syne', sans-serif" }}>📊 Live Logs</div>
                <div style={{ fontSize: 9, color: "rgba(226,232,240,0.4)", fontFamily: "'DM Mono', monospace", marginTop: 2, display: "flex", alignItems: "center", gap: 4 }}>
                  <span className="pulse-dot" style={{ display: "inline-block", width: 5, height: 5, background: "#10b981", borderRadius: "50%" }}></span>
                  Auto-refresh 10s · {logs.length} events
                </div>
              </div>
              <button onClick={() => setLogsPanelOpen(false)} style={{ background: "transparent", border: "none", color: "rgba(226,232,240,0.5)", cursor: "pointer", fontSize: 16 }}>×</button>
            </div>
            <div style={{ flex: 1, overflow: "auto", padding: 8 }}>
              {logs.length === 0 ? (
                <div style={{ padding: 20, textAlign: "center", fontSize: 11, color: "rgba(226,232,240,0.4)" }}>
                  No activity yet.<br/>Create a task to see logs.
                </div>
              ) : logs.slice(0, 30).map(log => {
                const color = LOG_STATUS_COLOR(log.status);
                return (
                  <div key={log.id} style={{
                    padding: "8px 10px",
                    marginBottom: 4,
                    borderLeft: `2px solid ${color}`,
                    background: "rgba(255,255,255,0.02)",
                    borderRadius: 4,
                  }}>
                    <div style={{ display: "flex", alignItems: "center", gap: 6, marginBottom: 3 }}>
                      <span style={{ fontSize: 12 }}>{LOG_ICON(log.type, log.status)}</span>
                      <span style={{ fontSize: 9, padding: "1px 5px", background: `${color}20`, color: color, borderRadius: 3, fontFamily: "'DM Mono', monospace" }}>{log.type.replace('whatsapp_', 'wa_')}</span>
                      <span style={{ fontSize: 9, color: "rgba(226,232,240,0.4)", marginLeft: "auto", fontFamily: "'DM Mono', monospace" }}>{formatTime(log.created_at)}</span>
                    </div>
                    <div style={{ fontSize: 11, color: "#e2e8f0", lineHeight: 1.4 }}>{log.message}</div>
                    {log.phone && (
                      <div style={{ fontSize: 10, color: "rgba(226,232,240,0.4)", marginTop: 2, fontFamily: "'DM Mono', monospace" }}>{log.phone}</div>
                    )}
                  </div>
                );
              })}
            </div>
            <div style={{ padding: 10, borderTop: "1px solid rgba(255,255,255,0.06)" }}>
              <button className="btn btn-ghost" onClick={() => setActiveView("logs")} style={{ width: "100%", fontSize: 11 }}>
                View All Logs →
              </button>
            </div>
          </aside>
        )}

        {/* Reopen logs panel button */}
        {activeView !== "logs" && !logsPanelOpen && (
          <button
            onClick={() => setLogsPanelOpen(true)}
            style={{
              position: "fixed", right: 16, top: 80, background: "#22d3ee", color: "#080c14",
              border: "none", borderRadius: 8, padding: "8px 12px", cursor: "pointer",
              fontSize: 12, fontWeight: 600, zIndex: 50,
            }}
          >
            📊 Show Logs ({logs.length})
          </button>
        )}
      </div>

      {showAddUser && (
        <UserModal onClose={() => setShowAddUser(false)} onSuccess={() => { showNotif("✅ User added!"); loadData(); }} showNotif={showNotif} />
      )}
      {editUser && (
        <UserModal user={editUser} onClose={() => setEditUser(null)} onSuccess={() => { showNotif("✅ User updated!"); loadData(); }} showNotif={showNotif} />
      )}
      {showNewTask && (
        <NewTaskModal employees={[...employees, ...managers]} onClose={() => setShowNewTask(false)} onSuccess={() => { showNotif("✅ Task assigned!"); loadData(); }} />
      )}
      {notification && (
        <div style={{ position: "fixed", bottom: 24, right: 24, zIndex: 200, background: notification.type === 'success' ? "rgba(16,185,129,0.15)" : "rgba(239,68,68,0.15)", border: notification.type === 'success' ? "1px solid rgba(16,185,129,0.3)" : "1px solid rgba(239,68,68,0.3)", borderRadius: 10, padding: "12px 18px", fontSize: 13, color: "#e2e8f0", backdropFilter: "blur(12px)", maxWidth: 380 }}>{notification.msg}</div>
      )}
    </>
  );
}

function UserModal({ user, onClose, onSuccess, showNotif }: { user?: User; onClose: () => void; onSuccess: () => void; showNotif: (msg: string, type?: 'success'|'error') => void }) {
  const isEdit = !!user;
  const [name, setName] = useState(user?.name || "");
  const [phone, setPhone] = useState(user?.phone || "+91");
  const [designation, setDesignation] = useState(user?.designation || "");
  const [email, setEmail] = useState(user?.email || "");
  const [department, setDepartment] = useState(user?.department || "");
  const [role, setRole] = useState(user?.role || "employee");
  const [isActive, setIsActive] = useState(user?.is_active ?? true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const handleSubmit = async () => {
    setError("");
    if (!name.trim()) { setError("Name is required"); return; }
    if (!phone.trim()) { setError("Phone is required"); return; }

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
      if (isEdit) payload.is_active = isActive;

      if (isEdit) await api.put(`/users/${user!.id}`, payload);
      else await api.post("/users", payload);
      onSuccess();
      onClose();
    } catch (err: any) {
      let errorMsg = isEdit ? "Failed to update" : "Failed to add";
      if (err.response?.data?.message) errorMsg = err.response.data.message;
      else if (err.response?.data?.errors) {
        const firstError = Object.values(err.response.data.errors)[0];
        errorMsg = Array.isArray(firstError) ? firstError[0] : String(firstError);
      }
      setError(errorMsg);
    } finally { setSaving(false); }
  };

  return (
    <div style={{ position: "fixed", inset: 0, background: "rgba(0,0,0,0.7)", display: "flex", alignItems: "center", justifyContent: "center", zIndex: 100, backdropFilter: "blur(4px)" }} onClick={onClose}>
      <div style={{ background: "#131928", border: "1px solid rgba(255,255,255,0.10)", borderRadius: 16, padding: 28, width: 480, maxHeight: "90vh", overflow: "auto" }} onClick={(e) => e.stopPropagation()}>
        <h2 style={{ fontFamily: "'Syne', sans-serif", fontSize: 18, fontWeight: 700, marginBottom: 4 }}>{isEdit ? `Edit ${user.name}` : "Add User"}</h2>
        <div style={{ fontSize: 11, color: "rgba(226,232,240,0.35)", marginBottom: 20 }}>{isEdit ? "Update user details" : "New user will receive WhatsApp welcome"}</div>
        <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
          <input placeholder="Full Name" value={name} onChange={(e) => setName(e.target.value)} />
          <input placeholder="+919876543210" value={phone} onChange={(e) => setPhone(e.target.value)} />
          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12 }}>
            <select value={role} onChange={(e) => setRole(e.target.value)}>
              <option value="employee">Employee</option>
              <option value="manager">Manager</option>
              <option value="admin">Admin</option>
            </select>
            {isEdit && (
              <select value={isActive ? "active" : "inactive"} onChange={(e) => setIsActive(e.target.value === "active")}>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            )}
          </div>
          <input placeholder="Designation" value={designation} onChange={(e) => setDesignation(e.target.value)} />
          <input placeholder="Department" value={department} onChange={(e) => setDepartment(e.target.value)} />
          <input placeholder="Email (optional)" value={email} onChange={(e) => setEmail(e.target.value)} />
          {error && <div style={{ fontSize: 12, color: "#ef4444", padding: "8px 12px", background: "rgba(239,68,68,0.1)", borderRadius: 6 }}>⚠️ {error}</div>}
          <div style={{ display: "flex", gap: 8 }}>
            <button className="btn btn-ghost" style={{ flex: 1 }} onClick={onClose}>Cancel</button>
            <button className="btn btn-primary" style={{ flex: 2 }} onClick={handleSubmit} disabled={saving || !name || !phone}>{saving ? "Saving..." : (isEdit ? "Update" : "Add")}</button>
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
    setError("");
    setSaving(true);
    try {
      await api.post("/tasks", { title, assigned_to: assignedTo, priority, due_date: dueDate || null, reward_points: points });
      onSuccess();
      onClose();
    } catch (err: any) { setError(err.response?.data?.message || "Failed"); }
    finally { setSaving(false); }
  };

  return (
    <div style={{ position: "fixed", inset: 0, background: "rgba(0,0,0,0.7)", display: "flex", alignItems: "center", justifyContent: "center", zIndex: 100, backdropFilter: "blur(4px)" }} onClick={onClose}>
      <div style={{ background: "#131928", border: "1px solid rgba(255,255,255,0.10)", borderRadius: 16, padding: 28, width: 440 }} onClick={(e) => e.stopPropagation()}>
        <h2 style={{ fontFamily: "'Syne', sans-serif", fontSize: 18, fontWeight: 700, marginBottom: 4 }}>New Task</h2>
        <div style={{ fontSize: 11, color: "rgba(226,232,240,0.35)", marginBottom: 20 }}>Will trigger WhatsApp send — see logs panel</div>
        <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
          <input placeholder="Task title" value={title} onChange={(e) => setTitle(e.target.value)} />
          <select value={assignedTo} onChange={(e) => setAssignedTo(e.target.value)}>
            <option value="">Select user</option>
            {employees.map(e => <option key={e.id} value={e.id}>{e.name} ({e.role})</option>)}
          </select>
          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12 }}>
            <select value={priority} onChange={(e) => setPriority(e.target.value)}>
              <option value="low">Low</option>
              <option value="medium">Medium</option>
              <option value="high">High</option>
              <option value="critical">Critical</option>
            </select>
            <input type="number" value={points} onChange={(e) => setPoints(parseInt(e.target.value) || 50)} placeholder="Points" />
          </div>
          <input type="datetime-local" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
          {error && <div style={{ fontSize: 12, color: "#ef4444", padding: "8px 12px", background: "rgba(239,68,68,0.1)", borderRadius: 6 }}>⚠️ {error}</div>}
          <div style={{ display: "flex", gap: 8 }}>
            <button className="btn btn-ghost" style={{ flex: 1 }} onClick={onClose}>Cancel</button>
            <button className="btn btn-primary" style={{ flex: 2 }} onClick={handleSubmit} disabled={saving || !title || !assignedTo}>{saving ? "Assigning..." : "Assign + WhatsApp"}</button>
          </div>
        </div>
      </div>
    </div>
  );
}

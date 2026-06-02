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
  assigned_to_id?: string;
  assigned_by_id?: string;
  assigned_to?: User;
  assigned_by?: User;
  created_at: string;
  completed_at?: string;
}

interface LeaderboardEntry {
  id: string;
  name: string;
  department?: string;
  avg_score: number;
  total_completed: number;
  rank: number;
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

const PRIORITY_CONFIG: Record<string, { label: string; color: string }> = {
  low:      { color: "#64748b", label: "Low" },
  medium:   { color: "#3b82f6", label: "Med" },
  high:     { color: "#f59e0b", label: "High" },
  critical: { color: "#ef4444", label: "Crit" },
};

export default function Dashboard() {
  const [activeView, setActiveView] = useState<"overview" | "tasks" | "employees">("overview");
  const [overview, setOverview] = useState<Overview | null>(null);
  const [tasks, setTasks] = useState<Task[]>([]);
  const [employees, setEmployees] = useState<User[]>([]);
  const [leaderboard, setLeaderboard] = useState<LeaderboardEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [showAddEmployee, setShowAddEmployee] = useState(false);
  const [showNewTask, setShowNewTask] = useState(false);
  const [notification, setNotification] = useState<string | null>(null);

  const { user, logout } = useAuthStore();
  const navigate = useNavigate();

  const showNotif = (msg: string) => {
    setNotification(msg);
    setTimeout(() => setNotification(null), 3000);
  };

  const loadData = async () => {
    try {
      setLoading(true);
      const [ovRes, tasksRes, usersRes, lbRes] = await Promise.all([
        api.get("/analytics/overview"),
        api.get("/tasks"),
        api.get("/users"),
        api.get("/analytics/leaderboard").catch(() => ({ data: [] })),
      ]);
      setOverview(ovRes.data);
      setTasks(tasksRes.data.data || tasksRes.data);
      setEmployees((usersRes.data || []).filter((u: User) => u.role === "employee"));
      setLeaderboard(lbRes.data || []);
    } catch (err) {
      console.error("Failed to load data", err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadData();
    const interval = setInterval(loadData, 30000);
    return () => clearInterval(interval);
  }, []);

  const handleLogout = () => {
    logout();
    navigate("/login");
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
    .pulse { animation: pulse 2s infinite; }
    @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }
    input, select, textarea {
      background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.10);
      border-radius: 8px; color: #e2e8f0; font-family: 'Inter', sans-serif;
      font-size: 13px; padding: 8px 12px; outline: none; width: 100%;
    }
    input:focus, select:focus, textarea:focus { border-color: #22d3ee; }
    select option { background: #1a2035; }
    .btn { padding: 7px 14px; border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer; border: none; }
    .btn-primary { background: #22d3ee; color: #080c14; }
    .btn-ghost { background: rgba(255,255,255,0.06); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.10); }
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
                {activeView === "employees" && "People & Team"}
              </h1>
              <div style={{ fontSize: 11, color: "rgba(226,232,240,0.35)", fontFamily: "'DM Mono', monospace", marginTop: 2 }}>
                Auto-refresh every 30s
              </div>
            </div>
            <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
              <button className="btn btn-ghost" onClick={loadData}>↻ Refresh</button>
              {activeView === "employees" && (
                <button className="btn btn-primary" onClick={() => setShowAddEmployee(true)}>+ Add Employee</button>
              )}
              {activeView === "tasks" && (
                <button className="btn btn-primary" onClick={() => setShowNewTask(true)}>+ New Task</button>
              )}
            </div>
          </header>

          <div className="fade-in" style={{ padding: 24 }}>
            {loading && !overview && (
              <div style={{ textAlign: "center", padding: 40, color: "rgba(226,232,240,0.5)" }}>
                Loading data...
              </div>
            )}

            {activeView === "overview" && overview && (
              <div style={{ display: "flex", flexDirection: "column", gap: 20 }}>
                <div style={{ display: "grid", gridTemplateColumns: "repeat(5, 1fr)", gap: 12 }}>
                  {[
                    { label: "Total Tasks",  value: overview.total_tasks,        color: "#22d3ee" },
                    { label: "Open Tasks",   value: overview.open_tasks,         color: "#3b82f6" },
                    { label: "Completed",    value: overview.completed_tasks,    color: "#10b981" },
                    { label: "Overdue",      value: overview.overdue_tasks,      color: "#ef4444" },
                    { label: "Team APIX",    value: `${overview.team_productivity}%`, color: "#f59e0b" },
                  ].map((kpi, i) => (
                    <div key={i} style={{
                      background: "#0e1420", border: "1px solid rgba(255,255,255,0.06)", borderRadius: 12, padding: 16,
                    }}>
                      <div style={{ fontSize: 10, color: "rgba(226,232,240,0.35)", fontFamily: "'DM Mono', monospace", textTransform: "uppercase", letterSpacing: 1 }}>{kpi.label}</div>
                      <div style={{ fontSize: 28, fontWeight: 800, color: kpi.color, fontFamily: "'Syne', sans-serif", marginTop: 6 }}>{kpi.value}</div>
                    </div>
                  ))}
                </div>

                {employees.length === 0 && tasks.length === 0 && (
                  <div style={{
                    background: "rgba(34,211,238,0.05)", border: "1px solid rgba(34,211,238,0.2)",
                    borderRadius: 12, padding: 24, textAlign: "center"
                  }}>
                    <div style={{ fontSize: 16, fontWeight: 600, marginBottom: 8, color: "#22d3ee" }}>👋 Welcome to TaskFlow!</div>
                    <div style={{ fontSize: 13, color: "rgba(226,232,240,0.7)", lineHeight: 1.6, marginBottom: 16 }}>
                      Your system is empty. Start by adding your first employee.<br/>
                      They'll receive tasks via WhatsApp instantly.
                    </div>
                    <button className="btn btn-primary" onClick={() => { setActiveView("employees"); setShowAddEmployee(true); }}>
                      + Add First Employee
                    </button>
                  </div>
                )}
              </div>
            )}

            {activeView === "tasks" && (
              <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
                {tasks.length === 0 ? (
                  <div style={{ textAlign: "center", padding: 60, color: "rgba(226,232,240,0.5)", background: "#0e1420", borderRadius: 12, border: "1px solid rgba(255,255,255,0.06)" }}>
                    <div style={{ fontSize: 14, marginBottom: 8 }}>No tasks yet</div>
                    <div style={{ fontSize: 12 }}>Assign tasks via WhatsApp or click "+ New Task"</div>
                  </div>
                ) : tasks.map(task => {
                  const sc = STATUS_CONFIG[task.status] || STATUS_CONFIG.assigned;
                  const pc = PRIORITY_CONFIG[task.priority] || PRIORITY_CONFIG.medium;
                  return (
                    <div key={task.id} style={{
                      background: "#0e1420", border: "1px solid rgba(255,255,255,0.06)", borderRadius: 12,
                      padding: "14px 18px", display: "flex", alignItems: "center", gap: 16,
                    }}>
                      <span style={{ padding: "2px 8px", borderRadius: 4, fontSize: 11, fontFamily: "'DM Mono', monospace", background: sc.bg, color: sc.color, border: `1px solid ${sc.color}40`, minWidth: 88, textAlign: "center" }}>
                        {sc.label}
                      </span>
                      <div style={{ flex: 1, minWidth: 0 }}>
                        <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 2, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{task.title}</div>
                        <div style={{ fontSize: 11, color: "rgba(226,232,240,0.35)" }}>
                          {task.assigned_to?.name || "Unassigned"} · ID: T-{task.id.substring(0, 6)}
                        </div>
                      </div>
                      <span style={{ padding: "2px 8px", borderRadius: 4, fontSize: 11, fontFamily: "'DM Mono', monospace", background: `${pc.color}15`, color: pc.color, border: `1px solid ${pc.color}30` }}>
                        {pc.label}
                      </span>
                      <div style={{ fontSize: 11, color: "rgba(226,232,240,0.35)", fontFamily: "'DM Mono', monospace" }}>
                        {task.due_date ? new Date(task.due_date).toLocaleDateString() : "No due date"}
                      </div>
                      <div style={{ fontSize: 12, color: "#f59e0b", fontFamily: "'DM Mono', monospace" }}>⭐ {task.reward_points}</div>
                    </div>
                  );
                })}
              </div>
            )}

            {activeView === "employees" && (
              <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: 14 }}>
                {employees.length === 0 ? (
                  <div style={{ gridColumn: "1 / -1", textAlign: "center", padding: 60, color: "rgba(226,232,240,0.5)", background: "#0e1420", borderRadius: 12, border: "1px solid rgba(255,255,255,0.06)" }}>
                    <div style={{ fontSize: 14, marginBottom: 8 }}>No employees yet</div>
                    <div style={{ fontSize: 12, marginBottom: 16 }}>Add employees via WhatsApp or button above</div>
                    <button className="btn btn-primary" onClick={() => setShowAddEmployee(true)}>+ Add First Employee</button>
                  </div>
                ) : employees.map(emp => (
                  <div key={emp.id} style={{
                    background: "#0e1420", border: "1px solid rgba(255,255,255,0.06)", borderRadius: 14, padding: 18,
                  }}>
                    <div style={{ display: "flex", gap: 12, alignItems: "flex-start", marginBottom: 12 }}>
                      <div style={{
                        width: 44, height: 44, borderRadius: "50%",
                        background: "rgba(34,211,238,0.2)",
                        display: "flex", alignItems: "center", justifyContent: "center",
                        fontSize: 14, fontWeight: 700, color: "#22d3ee"
                      }}>{emp.name.charAt(0)}</div>
                      <div style={{ flex: 1 }}>
                        <div style={{ fontSize: 13, fontWeight: 700 }}>{emp.name}</div>
                        <div style={{ fontSize: 11, color: "rgba(226,232,240,0.35)", marginTop: 1 }}>{emp.designation}</div>
                        <div style={{ fontSize: 10, color: "rgba(226,232,240,0.35)", marginTop: 2, fontFamily: "'DM Mono', monospace" }}>{emp.phone}</div>
                      </div>
                    </div>
                    <div style={{ display: "flex", gap: 6 }}>
                      <span style={{ padding: "2px 8px", borderRadius: 4, fontSize: 10, background: emp.is_active ? "rgba(16,185,129,0.15)" : "rgba(100,116,139,0.15)", color: emp.is_active ? "#10b981" : "#64748b", fontFamily: "'DM Mono', monospace" }}>
                        {emp.is_active ? "Active" : "Inactive"}
                      </span>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </main>
      </div>

      {showAddEmployee && (
        <AddEmployeeModal
          onClose={() => setShowAddEmployee(false)}
          onSuccess={() => { showNotif("✅ Employee added! WhatsApp message sent."); loadData(); }}
        />
      )}

      {showNewTask && (
        <NewTaskModal
          employees={employees}
          onClose={() => setShowNewTask(false)}
          onSuccess={() => { showNotif("✅ Task assigned via WhatsApp!"); loadData(); }}
        />
      )}

      {notification && (
        <div style={{
          position: "fixed", bottom: 24, right: 24, zIndex: 200,
          background: "rgba(16,185,129,0.15)", border: "1px solid rgba(16,185,129,0.3)",
          borderRadius: 10, padding: "12px 18px", fontSize: 13, color: "#e2e8f0",
          backdropFilter: "blur(12px)",
        }}>{notification}</div>
      )}
    </>
  );
}

function AddEmployeeModal({ onClose, onSuccess }: { onClose: () => void; onSuccess: () => void }) {
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [designation, setDesignation] = useState("");
  const [email, setEmail] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const handleSubmit = async () => {
    setError("");
    
    // Client-side validation
    if (!name.trim()) {
      setError("Name is required");
      return;
    }
    if (!phone.trim()) {
      setError("Phone is required");
      return;
    }
    
    // Clean phone number
    let cleanPhone = phone.replace(/[\s\-()]/g, '');
    if (!cleanPhone.startsWith('+')) {
      if (cleanPhone.length === 10) {
        cleanPhone = '+91' + cleanPhone;
      } else if (cleanPhone.startsWith('91') && cleanPhone.length === 12) {
        cleanPhone = '+' + cleanPhone;
      }
    }
    
    setSaving(true);
    try {
      const payload: any = {
        name: name.trim(),
        phone: cleanPhone,
        role: "employee",
      };
      if (designation.trim()) payload.designation = designation.trim();
      if (email.trim()) payload.email = email.trim();
      
      console.log("Sending payload:", payload);
      const response = await api.post("/users", payload);
      console.log("Success:", response.data);
      onSuccess();
      onClose();
    } catch (err: any) {
      console.error("Error response:", err.response?.data);
      
      // Extract detailed error from server
      let errorMsg = "Failed to add employee";
      
      if (err.response?.data?.message) {
        errorMsg = err.response.data.message;
      } else if (err.response?.data?.errors) {
        const firstError = Object.values(err.response.data.errors)[0];
        errorMsg = Array.isArray(firstError) ? firstError[0] : String(firstError);
      } else if (err.message) {
        errorMsg = err.message;
      }
      
      setError(errorMsg);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div style={{ position: "fixed", inset: 0, background: "rgba(0,0,0,0.7)", display: "flex", alignItems: "center", justifyContent: "center", zIndex: 100, backdropFilter: "blur(4px)" }} onClick={onClose}>
      <div style={{ background: "#131928", border: "1px solid rgba(255,255,255,0.10)", borderRadius: 16, padding: 28, width: 440 }} onClick={(e) => e.stopPropagation()}>
        <h2 style={{ fontFamily: "'Syne', sans-serif", fontSize: 18, fontWeight: 700, marginBottom: 4 }}>Add Employee</h2>
        <div style={{ fontSize: 11, color: "rgba(226,232,240,0.35)", marginBottom: 20 }}>They'll receive welcome message on WhatsApp</div>
        <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
          <input placeholder="Full Name" value={name} onChange={(e) => setName(e.target.value)} />
          <input placeholder="Phone (e.g. 9876543210 or +919876543210)" value={phone} onChange={(e) => setPhone(e.target.value)} />
          <input placeholder="Designation (e.g. Field Executive)" value={designation} onChange={(e) => setDesignation(e.target.value)} />
          <input placeholder="Email (optional - auto-generated if blank)" value={email} onChange={(e) => setEmail(e.target.value)} />
          {error && <div style={{ fontSize: 12, color: "#ef4444", padding: "8px 12px", background: "rgba(239,68,68,0.1)", borderRadius: 6, border: "1px solid rgba(239,68,68,0.3)" }}>⚠️ {error}</div>}
          <div style={{ display: "flex", gap: 8, marginTop: 6 }}>
            <button className="btn btn-ghost" style={{ flex: 1 }} onClick={onClose}>Cancel</button>
            <button className="btn btn-primary" style={{ flex: 2 }} onClick={handleSubmit} disabled={saving || !name || !phone}>
              {saving ? "Adding..." : "Add Employee"}
            </button>
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
      await api.post("/tasks", {
        title, assigned_to: assignedTo, priority,
        due_date: dueDate || null, reward_points: points,
      });
      onSuccess();
      onClose();
    } catch (err: any) {
      setError(err.response?.data?.message || "Failed to create task");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div style={{ position: "fixed", inset: 0, background: "rgba(0,0,0,0.7)", display: "flex", alignItems: "center", justifyContent: "center", zIndex: 100, backdropFilter: "blur(4px)" }} onClick={onClose}>
      <div style={{ background: "#131928", border: "1px solid rgba(255,255,255,0.10)", borderRadius: 16, padding: 28, width: 440 }} onClick={(e) => e.stopPropagation()}>
        <h2 style={{ fontFamily: "'Syne', sans-serif", fontSize: 18, fontWeight: 700, marginBottom: 4 }}>New Task</h2>
        <div style={{ fontSize: 11, color: "rgba(226,232,240,0.35)", marginBottom: 20 }}>Employee will receive task via WhatsApp instantly</div>
        <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
          <input placeholder="Task title" value={title} onChange={(e) => setTitle(e.target.value)} />
          <select value={assignedTo} onChange={(e) => setAssignedTo(e.target.value)}>
            <option value="">Select employee</option>
            {employees.map(e => <option key={e.id} value={e.id}>{e.name}</option>)}
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
          <div style={{ display: "flex", gap: 8, marginTop: 6 }}>
            <button className="btn btn-ghost" style={{ flex: 1 }} onClick={onClose}>Cancel</button>
            <button className="btn btn-primary" style={{ flex: 2 }} onClick={handleSubmit} disabled={saving || !title || !assignedTo}>
              {saving ? "Assigning..." : "Assign via WhatsApp"}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

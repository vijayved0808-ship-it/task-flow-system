import { useState, FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuthStore } from '../store/auth'

export default function Login() {
  const [email, setEmail] = useState('admin@taskflow.com')
  const [password, setPassword] = useState('Admin@123')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const { login } = useAuthStore()
  const navigate = useNavigate()

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setLoading(true)
    setError('')
    try {
      await login(email, password)
      navigate('/')
    } catch {
      setError('Invalid email or password')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div style={{
      minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center',
      background: 'var(--bg)',
    }}>
      <div style={{
        background: 'var(--surface)', border: '1px solid var(--border2)',
        borderRadius: 16, padding: '36px 32px', width: 380, maxWidth: '90vw',
      }}>
        <div style={{ textAlign: 'center', marginBottom: 28 }}>
          <div style={{
            width: 48, height: 48, borderRadius: 14, background: 'linear-gradient(135deg, #22d3ee, #0891b2)',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            fontSize: 20, fontWeight: 800, color: '#080c14', fontFamily: "'Syne', sans-serif",
            margin: '0 auto 12px',
          }}>TF</div>
          <h1 style={{ fontFamily: "'Syne', sans-serif", fontSize: 22, fontWeight: 700, color: 'var(--text)' }}>
            TaskFlow
          </h1>
          <p style={{ fontSize: 13, color: 'var(--text3)', marginTop: 4 }}>WhatsApp Workforce OS</p>
        </div>

        <form onSubmit={handleSubmit}>
          <div style={{ marginBottom: 14 }}>
            <label style={{ fontSize: 11, color: 'var(--text3)', display: 'block', marginBottom: 5, fontFamily: "'DM Mono', monospace", textTransform: 'uppercase', letterSpacing: 1 }}>
              Email
            </label>
            <input
              type="email" value={email} onChange={(e) => setEmail(e.target.value)} required
              style={{
                width: '100%', background: 'rgba(255,255,255,0.05)', border: '1px solid var(--border2)',
                borderRadius: 8, color: 'var(--text)', fontSize: 13, padding: '9px 12px', outline: 'none',
              }}
            />
          </div>
          <div style={{ marginBottom: 20 }}>
            <label style={{ fontSize: 11, color: 'var(--text3)', display: 'block', marginBottom: 5, fontFamily: "'DM Mono', monospace", textTransform: 'uppercase', letterSpacing: 1 }}>
              Password
            </label>
            <input
              type="password" value={password} onChange={(e) => setPassword(e.target.value)} required
              style={{
                width: '100%', background: 'rgba(255,255,255,0.05)', border: '1px solid var(--border2)',
                borderRadius: 8, color: 'var(--text)', fontSize: 13, padding: '9px 12px', outline: 'none',
              }}
            />
          </div>

          {error && (
            <div style={{ background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.3)', borderRadius: 8, padding: '8px 12px', fontSize: 12, color: '#ef4444', marginBottom: 14 }}>
              {error}
            </div>
          )}

          <button type="submit" disabled={loading} style={{
            width: '100%', background: '#22d3ee', color: '#080c14', border: 'none',
            borderRadius: 8, padding: '10px 0', fontSize: 14, fontWeight: 600,
            cursor: loading ? 'not-allowed' : 'pointer', opacity: loading ? 0.7 : 1,
            fontFamily: 'inherit', transition: 'all 0.15s',
          }}>
            {loading ? 'Signing in...' : 'Sign In'}
          </button>
        </form>

        <p style={{ fontSize: 11, color: 'var(--text3)', textAlign: 'center', marginTop: 16, fontFamily: "'DM Mono', monospace" }}>
          Default: admin@taskflow.com / Admin@123
        </p>
      </div>
    </div>
  )
}

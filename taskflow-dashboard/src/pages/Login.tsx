import { useState, FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuthStore } from '../store/auth'

export default function Login() {
  const [email, setEmail] = useState('admin@uicgroup.com')
  const [password, setPassword] = useState('UIC@2026')
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
      background: '#f8fafc', fontFamily: "'Inter', sans-serif",
    }}>
      <style dangerouslySetInnerHTML={{ __html: `
        @import url('https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&family=Inter:wght@300;400;500;600&display=swap');
        * { box-sizing: border-box; }
        body { margin: 0; background: #f8fafc; }
      ` }} />
      <div style={{
        background: '#ffffff', border: '1px solid #cbd5e1',
        borderRadius: 16, padding: '36px 32px', width: 380, maxWidth: '90vw',
      }}>
        <div style={{ textAlign: 'center', marginBottom: 28 }}>
          <div style={{
            width: 48, height: 48, borderRadius: 14, background: 'linear-gradient(135deg, #0891b2, #0891b2)',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            fontSize: 20, fontWeight: 800, color: '#f8fafc', fontFamily: "'Syne', sans-serif",
            margin: '0 auto 12px',
          }}>TF</div>
          <h1 style={{ fontFamily: "'Syne', sans-serif", fontSize: 22, fontWeight: 700, color: '#0f172a', margin: 0 }}>
            TaskFlow
          </h1>
          <p style={{ fontSize: 13, color: 'rgba(15,23,42,0.5)', marginTop: 4, marginBottom: 0 }}>WhatsApp Workforce OS · UIC Group</p>
        </div>

        <form onSubmit={handleSubmit}>
          <div style={{ marginBottom: 14 }}>
            <label style={{ fontSize: 11, color: 'rgba(15,23,42,0.5)', display: 'block', marginBottom: 5, fontFamily: "'DM Mono', monospace", textTransform: 'uppercase', letterSpacing: 1 }}>Email</label>
            <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required style={{
              width: '100%', background: '#f1f5f9', border: '1px solid #cbd5e1',
              borderRadius: 8, color: '#0f172a', fontSize: 13, padding: '9px 12px', outline: 'none', fontFamily: 'inherit',
            }} />
          </div>
          <div style={{ marginBottom: 20 }}>
            <label style={{ fontSize: 11, color: 'rgba(15,23,42,0.5)', display: 'block', marginBottom: 5, fontFamily: "'DM Mono', monospace", textTransform: 'uppercase', letterSpacing: 1 }}>Password</label>
            <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required style={{
              width: '100%', background: '#f1f5f9', border: '1px solid #cbd5e1',
              borderRadius: 8, color: '#0f172a', fontSize: 13, padding: '9px 12px', outline: 'none', fontFamily: 'inherit',
            }} />
          </div>

          {error && (
            <div style={{ background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.3)', borderRadius: 8, padding: '8px 12px', fontSize: 12, color: '#ef4444', marginBottom: 14 }}>
              {error}
            </div>
          )}

          <button type="submit" disabled={loading} style={{
            width: '100%', background: '#0891b2', color: '#f8fafc', border: 'none',
            borderRadius: 8, padding: '10px 0', fontSize: 14, fontWeight: 600,
            cursor: loading ? 'not-allowed' : 'pointer', opacity: loading ? 0.7 : 1,
            fontFamily: 'inherit',
          }}>
            {loading ? 'Signing in...' : 'Sign In'}
          </button>
        </form>

        <p style={{ fontSize: 11, color: 'rgba(15,23,42,0.5)', textAlign: 'center', marginTop: 16, fontFamily: "'DM Mono', monospace" }}>
          admin@uicgroup.com / UIC@2026
        </p>
      </div>
    </div>
  )
}

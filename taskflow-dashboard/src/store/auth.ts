import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import api from '../shared/api/client'

interface AuthUser { id: string; name: string; email: string; role: string }

interface AuthState {
  token: string | null
  user: AuthUser | null
  login: (email: string, password: string) => Promise<void>
  logout: () => void
  isAuthenticated: () => boolean
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      token: null,
      user: null,
      login: async (email, password) => {
        const { data } = await api.post('/auth/login', { email, password })
        localStorage.setItem('tf_token', data.token)
        set({ token: data.token, user: data.user })
      },
      logout: () => {
        localStorage.removeItem('tf_token')
        set({ token: null, user: null })
      },
      isAuthenticated: () => !!get().token,
    }),
    { name: 'taskflow-auth', partialize: (s) => ({ token: s.token, user: s.user }) }
  )
)

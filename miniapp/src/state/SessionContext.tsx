import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, setSession, verifyUrl } from '../lib/api'
import { tg } from '../lib/telegram'
import type { UserInfo } from '../lib/types'

interface SessionState {
  status: 'loading' | 'ready' | 'error'
  error: string | null
  userInfo: UserInfo | null
  refreshUserInfo: () => Promise<void>
}

const SESSION_STORAGE_KEY = 'miniapp_token'

const SessionCtx = createContext<SessionState | null>(null)

export function SessionProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<SessionState['status']>('loading')
  const [error, setError] = useState<string | null>(null)
  const [userInfo, setUserInfo] = useState<UserInfo | null>(null)

  async function refreshUserInfo() {
    try {
      const info = await api.userInfo()
      setUserInfo(info)
    } catch {
      // keep last known value on transient errors
    }
  }

  useEffect(() => {
    let cancelled = false

    async function boot() {
      const telegramUser = tg.initDataUnsafe.user
      if (!telegramUser) {
        setStatus('error')
        setError('اطلاعات کاربر تلگرام در دسترس نیست. لطفاً از داخل تلگرام وارد شوید.')
        return
      }

      const cachedToken = sessionStorage.getItem(SESSION_STORAGE_KEY)
      if (cachedToken) {
        setSession(cachedToken, telegramUser.id)
        try {
          const info = await api.userInfo()
          if (cancelled) return
          setUserInfo(info)
          setStatus('ready')
          return
        } catch {
          sessionStorage.removeItem(SESSION_STORAGE_KEY)
        }
      }

      try {
        const res = await fetch(verifyUrl(), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ initData: tg.initData }),
        })
        const json = await res.json()
        if (!res.ok || !json.status || !json.token) {
          throw new Error(json.msg ?? 'ورود ناموفق بود')
        }
        if (cancelled) return
        sessionStorage.setItem(SESSION_STORAGE_KEY, json.token)
        setSession(json.token, telegramUser.id)
        const info = await api.userInfo()
        if (cancelled) return
        setUserInfo(info)
        setStatus('ready')
      } catch (err) {
        if (cancelled) return
        setStatus('error')
        setError(err instanceof Error ? err.message : 'خطا در برقراری ارتباط')
      }
    }

    boot()
    return () => {
      cancelled = true
    }
  }, [])

  return (
    <SessionCtx.Provider value={{ status, error, userInfo, refreshUserInfo }}>{children}</SessionCtx.Provider>
  )
}

export function useSession() {
  const ctx = useContext(SessionCtx)
  if (!ctx) throw new Error('useSession must be used within SessionProvider')
  return ctx
}

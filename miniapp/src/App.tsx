import { useEffect } from 'react'
import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { AppLayout } from './components/AppLayout'
import { LoadingScreen, ErrorState } from './components/ui'
import { SessionProvider, useSession } from './state/SessionContext'
import { initTelegram } from './lib/telegram'
import Home from './routes/Home'
import Buy from './routes/Buy'
import MyServices from './routes/MyServices'
import ServiceDetail from './routes/ServiceDetail'
import Wallet from './routes/Wallet'
import Account from './routes/Account'
import NotFound from './routes/NotFound'

function Gate({ children }: { children: React.ReactNode }) {
  const { status, error } = useSession()
  if (status === 'loading') return <LoadingScreen label="در حال ورود..." />
  if (status === 'error') return <ErrorState message={error ?? 'خطا در ورود'} onRetry={() => window.location.reload()} />
  return <>{children}</>
}

export default function App() {
  useEffect(() => {
    initTelegram()
  }, [])

  const basename = window.__APP_CONFIG__?.basename ?? ''

  return (
    <BrowserRouter basename={basename}>
      <SessionProvider>
        <Gate>
          <Routes>
            <Route element={<AppLayout />}>
              <Route index element={<Home />} />
              <Route path="services" element={<MyServices />} />
              <Route path="services/:username" element={<ServiceDetail />} />
              <Route path="buy" element={<Buy />} />
              <Route path="wallet" element={<Wallet />} />
              <Route path="account" element={<Account />} />
              <Route path="*" element={<NotFound />} />
            </Route>
          </Routes>
        </Gate>
      </SessionProvider>
    </BrowserRouter>
  )
}
